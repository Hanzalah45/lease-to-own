<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\PaymentPart;
use App\Services\BillingClock;
use App\Services\PaymentSplitter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Staff take a payment toward something that is due, in whole or in part (client, Joel, 2026-10-19 checklist, sections 1 and 7: "record
 * partial payment", "split by method", "split by amount", "partial now, finish later"): cash, a check or another method received in the
 * office, or a charge to the customer's saved bank account or card after they agreed. The work is PaymentSplitter's.
 */
class PaymentPartController extends Controller
{
    public function __construct(private readonly PaymentSplitter $splitter) {}

    /** A piece toward a payment that exists: a monthly payment, a late fee, a deposit or pickup balance that is waiting to be paid. */
    public function store(Request $request, Payment $payment)
    {
        return $this->take($request, $payment);
    }

    /**
     * A piece toward the deposit or the tracking fee and first month of an application, which may have no payment row yet (nothing was
     * charged before): the row is made from what the lease says is due, then the piece is taken toward it.
     */
    public function storeForApplication(Request $request, Application $application)
    {
        $type = $request->validate(['type' => ['required', Rule::in([Payment::TYPE_DEPOSIT, Payment::TYPE_PICKUP_BALANCE])]])['type'];

        $lease = $application->leaseAgreement;
        abort_unless($lease, 422, 'This application has no lease agreement yet.');
        abort_unless($lease->contract()->exists(), 422, 'The lease agreement must be signed before a payment is taken.');
        abort_unless(
            in_array($application->status, [Application::STATUS_WAITING_DEPOSIT, Application::STATUS_WAITING_DELIVERY, Application::STATUS_FINISHED], true),
            422,
            'A payment cannot be taken at this stage of the application.',
        );

        $payment = DB::transaction(function () use ($lease, $type) {
            LeaseAgreement::whereKey($lease->id)->lockForUpdate()->firstOrFail();
            $existing = $lease->payments()->where('type', $type)->latest('id')->first();
            abort_if($existing?->status === Payment::STATUS_PAID, 422, $type === Payment::TYPE_DEPOSIT ? 'The deposit has already been paid.' : 'The tracking fee and first month has already been paid.');

            // A row that is waiting to be paid (or was declined) is finished; otherwise one is made from what is due.
            if ($existing && in_array($existing->status, [Payment::STATUS_PENDING, Payment::STATUS_FAILED], true)) {
                return $existing;
            }

            return Payment::create([
                'lease_agreement_id' => $lease->id,
                'type' => $type,
                'amount' => $type === Payment::TYPE_DEPOSIT ? $lease->depositAmountDue() : $lease->pickupBalanceAmountDue(),
                'due_date' => BillingClock::todayDate(),
                'status' => Payment::STATUS_PENDING,
            ]);
        });

        return $this->take($request, $payment);
    }

    /** A charge for a piece that Stripe never answered is cancelled once staff have checked Stripe that it was not made. */
    public function cancel(Request $request, PaymentPart $paymentPart)
    {
        $this->splitter->cancelUnanswered($paymentPart, $request->user());

        return response()->json(['data' => $paymentPart->payment->fresh(PaymentRefundController::WITH)]);
    }

    private function take(Request $request, Payment $payment)
    {
        $data = $request->validate([
            'method' => ['required', Rule::in([...PaymentSplitter::MANUAL_METHODS, 'bank', 'card'])],
            // Same bound as every other typed payment amount here; the real limit is what is still due on the payment.
            'amount' => ['required', 'numeric', 'min:0.01', 'max:100000'],
            'note' => ['nullable', 'string', 'max:300'],
            // A charge to a saved method: the total the person saw on screen, and that the customer agreed to it.
            'expected_total_cents' => ['nullable', 'integer', 'min:1'],
            // ('accepted' runs even when the field is absent, so it is only asked for when a charge is made.)
            'customer_agreed' => in_array($request->input('method'), ['bank', 'card'], true) ? ['accepted'] : ['nullable'],
        ]);
        $staff = $request->user();

        if (in_array($data['method'], PaymentSplitter::MANUAL_METHODS, true)) {
            $part = $this->splitter->recordManual($payment, $data['method'], (float) $data['amount'], $staff, $data['note'] ?? null);
            $outcome = ['part' => $part, 'status' => 'paid', 'message' => null];
        } else {
            $outcome = $this->splitter->charge(
                $payment,
                $data['method'],
                (float) $data['amount'],
                isset($data['expected_total_cents']) ? (int) $data['expected_total_cents'] : null,
                $staff,
                PaymentPart::BY_STAFF,
            );
        }

        return response()->json([
            'data' => $payment->fresh(PaymentRefundController::WITH),
            'part' => $outcome['part'],
            'status' => $outcome['status'],
        ], 201);
    }
}
