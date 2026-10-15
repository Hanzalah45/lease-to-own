<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Payment;
use App\Services\StripeDepositPaymentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * An admin charges the security deposit, or the tracking fee and first month,
 * to the customer's saved bank account or card after the customer agreed to it
 * (client, Joel, 2026-10-12 for the deposit, 2026-10-16 for the tracking fee and
 * first month).
 *
 * Real gap found on 2026-10-12: a customer took the equipment having paid only
 * the tracking fee and first month. "Mark Deposit Received" had been clicked
 * without any money collected, and the customer's own deposit button closes
 * once the deposit is marked received or the application leaves "waiting on
 * deposit", so there was no way left to collect it. The same happened the other
 * way round on 2026-10-16 (a customer picked up having paid only the deposit).
 * This is the staff-side way: same charge, same dual pricing and the same
 * payment row as the customer's own button, but it names who ran it, leaves a
 * dealer note, and needs the person to confirm the customer agreed.
 */
class DepositChargeController extends Controller
{
    public function __construct(private readonly StripeDepositPaymentService $deposits) {}

    public function store(Request $request, Application $application)
    {
        return $this->charge($request, $application, Payment::TYPE_DEPOSIT);
    }

    public function storePickupBalance(Request $request, Application $application)
    {
        return $this->charge($request, $application, Payment::TYPE_PICKUP_BALANCE);
    }

    private function charge(Request $request, Application $application, string $type)
    {
        $data = $request->validate([
            'method' => ['required', Rule::in(['bank', 'card'])],
            // What the admin saw on screen: refused with a 409 if the price moved underneath them.
            'expected_total_cents' => ['required', 'integer', 'min:1'],
            'customer_agreed' => ['accepted'],
        ]);
        $what = $this->what($type);

        $lease = $application->leaseAgreement;
        abort_unless($lease, 422, 'This application has no lease agreement yet.');
        abort_unless($lease->contract()->exists(), 422, "The lease agreement must be signed before {$what} is charged.");
        abort_unless(
            in_array($application->status, [Application::STATUS_WAITING_DEPOSIT, Application::STATUS_WAITING_DELIVERY, Application::STATUS_FINISHED], true),
            422,
            ucfirst($what).' cannot be charged at this stage of the application.',
        );

        $admin = $request->user();
        $lastId = (int) $lease->payments()->where('type', $type)->max('id');
        // An earlier charge that never got an answer is picked up again by this one (same row), not added as a new row.
        $resumedId = $lease->payments()
            ->where('type', $type)
            ->where('status', Payment::STATUS_PENDING)
            ->whereNull('stripe_payment_intent_id')
            ->value('id');

        try {
            $result = $type === Payment::TYPE_DEPOSIT
                ? $this->deposits->chargeDepositOnly($lease, $data['method'], (int) $data['expected_total_cents'], $admin->id)
                : $this->deposits->chargePickupBalance($lease, $data['method'], (int) $data['expected_total_cents'], $admin->id);
        } catch (HttpException $e) {
            // A card the bank declines comes back as an exception after the
            // attempt was recorded as failed: still leave the note, then pass
            // the message on. A refusal before any attempt (already paid,
            // price changed) created no row, so there is nothing to note.
            $declined = $lease->payments()
                ->where('type', $type)
                ->where('status', Payment::STATUS_FAILED)
                ->where(fn ($q) => $q->where('id', '>', $lastId)->when($resumedId, fn ($r) => $r->orWhere('id', $resumedId)))
                ->latest('id')
                ->first();
            if ($declined) {
                $this->leaveNote($application, $declined, $admin->id, $admin->name);
            }

            throw $e;
        }
        /** @var Payment $payment */
        $payment = $result['payment'];

        $this->leaveNote($application, $payment, $admin->id, $admin->name);

        abort_if($payment->status === Payment::STATUS_FAILED, 422, 'The charge was declined, so nothing was collected. Ask the customer to check the saved method, or try the other one.');

        return response()->json(['data' => ['payment' => $payment]]);
    }

    /** How the charge is named in a sentence ("the deposit"). */
    private function what(string $type): string
    {
        return $type === Payment::TYPE_DEPOSIT ? 'the deposit' : 'the tracking fee and first month';
    }

    private function leaveNote(Application $application, Payment $payment, int $adminId, string $adminName): void
    {
        $application->dealerNotes()->create([
            'author_user_id' => $adminId,
            'text' => $this->noteFor($payment, $adminName),
        ]);
    }

    private function noteFor(Payment $payment, string $adminName): string
    {
        $total = number_format((float) $payment->amount + (float) $payment->card_fee_amount, 2);
        $via = $payment->method === 'ach' ? 'bank account' : 'card';
        $name = $payment->type === Payment::TYPE_DEPOSIT ? 'Security deposit' : 'Tracking fee and first month';
        $part = $payment->type === Payment::TYPE_DEPOSIT ? 'deposit' : 'tracking fee and first month';
        $fee = (float) $payment->card_fee_amount > 0
            ? sprintf(' (%s $%s + $%s card fee)', $part, number_format((float) $payment->amount, 2), number_format((float) $payment->card_fee_amount, 2))
            : '';

        return match ($payment->status) {
            Payment::STATUS_PAID => "{$name} charged to the customer's saved {$via}: \${$total}{$fee}. Run by {$adminName} after the customer agreed.",
            Payment::STATUS_FAILED => "{$name} charge to the customer's saved {$via} was declined: \${$total}. Run by {$adminName}; nothing was collected.",
            default => "{$name} bank debit started: \${$total}. Run by {$adminName} after the customer agreed. It settles in a few business days.",
        };
    }
}
