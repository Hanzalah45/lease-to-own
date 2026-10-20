<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentAttempt;
use App\Services\BillingClock;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * The AutoPay log: every try at collecting a monthly payment through Stripe, automatic or pressed by the customer, with when it was
 * made, the method, the amount, how it ended and the bank's reason (client, Joel, 2026-10-19 checklist, section 5: "AutoPay logs
 * (attempted / succeeded / failed / reason)"). Read only.
 */
class AutopayLogController extends Controller
{
    private const PAGE = 50;

    public function index(Request $request)
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:initiated,processing,succeeded,failed'],
            'method' => ['nullable', 'in:bank,card'],
            // AutoPay's own charges, or the ones a customer pressed "Pay now" for.
            'started_by' => ['nullable', 'in:autopay,customer'],
            'lease_agreement_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $query = PaymentAttempt::with(['payment:id,lease_agreement_id,type,due_date,status', 'payment.leaseAgreement:id,customer_id,application_id', 'payment.leaseAgreement.customer:id,name'])
            ->orderByDesc('id');

        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (! empty($data['method'])) {
            $query->where('method', $data['method']);
        }
        if (($data['started_by'] ?? null) === 'customer') {
            $query->where('idempotency_key', 'like', PaymentAttempt::CUSTOMER_KEY_PREFIX.'%');
        } elseif (($data['started_by'] ?? null) === 'autopay') {
            $query->where('idempotency_key', 'not like', PaymentAttempt::CUSTOMER_KEY_PREFIX.'%');
        }
        if (! empty($data['lease_agreement_id'])) {
            $query->whereHas('payment', fn ($q) => $q->where('lease_agreement_id', $data['lease_agreement_id']));
        }
        // The days are the business's own (Central), the moments are stored in UTC.
        if (! empty($data['from'])) {
            $query->where('created_at', '>=', Carbon::parse($data['from'], BillingClock::TIMEZONE)->startOfDay()->utc());
        }
        if (! empty($data['to'])) {
            $query->where('created_at', '<=', Carbon::parse($data['to'], BillingClock::TIMEZONE)->endOfDay()->utc());
        }

        $page = $query->paginate(self::PAGE);
        $page->getCollection()->transform(fn (PaymentAttempt $a) => [
            'id' => $a->id,
            'created_at' => $a->created_at,
            'updated_at' => $a->updated_at,
            'attempt_no' => $a->attempt_no,
            'round' => $a->round,
            'method' => $a->method,
            'amount' => round($a->amount_cents / 100, 2),
            'fee' => round($a->fee_cents / 100, 2),
            'status' => $a->status,
            'failure_code' => $a->failure_code,
            'failure_message' => $a->failure_message,
            'started_by' => $a->startedByCustomer() ? 'customer' : 'autopay',
            'payment_id' => $a->payment_id,
            'payment_type' => $a->payment?->type,
            'due_date' => $a->payment?->due_date?->toDateString(),
            'lease_agreement_id' => $a->payment?->lease_agreement_id,
            'application_id' => $a->payment?->leaseAgreement?->application_id,
            'customer' => $a->payment?->leaseAgreement?->customer?->name,
        ]);

        return response()->json($page);
    }
}
