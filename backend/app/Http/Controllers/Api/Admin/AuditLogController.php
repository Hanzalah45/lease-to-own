<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentAuditLog;
use App\Services\BillingClock;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * The payment audit trail as one list: who did what to which payment, when, with what method and from where (client, Joel,
 * 2026-10-19 checklist, section 9). Read only: nothing in the portal can change or delete a line.
 */
class AuditLogController extends Controller
{
    private const PAGE = 50;

    public function index(Request $request)
    {
        $data = $request->validate([
            'lease_agreement_id' => ['nullable', 'integer'],
            'payment_id' => ['nullable', 'integer'],
            // A whole family ("refund", "method") or one action ("refund.completed").
            'action' => ['nullable', 'string', 'max:48'],
            'who' => ['nullable', 'in:staff,customer,system'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $query = PaymentAuditLog::with(['payment:id,lease_agreement_id,type,due_date', 'leaseAgreement:id,customer_id,application_id', 'leaseAgreement.customer:id,name'])
            ->orderByDesc('id');

        if (! empty($data['lease_agreement_id'])) {
            $query->where('lease_agreement_id', $data['lease_agreement_id']);
        }
        if (! empty($data['payment_id'])) {
            $query->where('payment_id', $data['payment_id']);
        }
        if (! empty($data['action'])) {
            $query->where(fn ($q) => $q->where('action', $data['action'])->orWhere('action', 'like', $data['action'].'.%'));
        }
        if (! empty($data['who'])) {
            $query->where('actor_type', $data['who']);
        }
        // The days are the business's own (Central), the moments are stored in UTC.
        if (! empty($data['from'])) {
            $query->where('created_at', '>=', Carbon::parse($data['from'], BillingClock::TIMEZONE)->startOfDay()->utc());
        }
        if (! empty($data['to'])) {
            $query->where('created_at', '<=', Carbon::parse($data['to'], BillingClock::TIMEZONE)->endOfDay()->utc());
        }

        return response()->json($query->paginate(self::PAGE));
    }
}
