<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\LeaseAgreement;
use App\Services\PaymentMethodLocker;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Staff lock a customer's saved bank account or card (fraud, a dispute, a customer who asks), and unlock it again (client, Joel,
 * 2026-10-19 checklist, section 4: "lock method"). A locked method stays on file but is never charged by AutoPay, "Pay now" or staff.
 */
class MethodLockController extends Controller
{
    public function store(Request $request, LeaseAgreement $leaseAgreement)
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['bank', 'card'])],
            'locked' => ['required', 'boolean'],
            // Why it was locked, kept with the lock and shown to staff. Required to lock, never asked to unlock.
            'reason' => ['required_if:locked,true', 'nullable', 'string', 'min:3', 'max:200'],
        ]);
        $type = $data['type'];
        abort_unless(
            $type === 'bank' ? $leaseAgreement->stripe_bank_payment_method_id : $leaseAgreement->stripe_card_payment_method_id,
            422,
            sprintf('There is no %s saved on this lease.', PaymentMethodLocker::label($type)),
        );

        if ($data['locked']) {
            abort_if(! PaymentMethodLocker::lock($leaseAgreement, $type, trim($data['reason']), $request->user()), 422, 'That method is already locked.');
        } else {
            abort_if(! PaymentMethodLocker::unlock($leaseAgreement, $type, $request->user()), 422, 'That method is not locked.');
        }

        $leaseAgreement->refresh();

        return response()->json(['data' => [
            'bank_lock' => $leaseAgreement->isMethodLocked('bank') ? ['locked_at' => $leaseAgreement->bank_locked_at, 'reason' => $leaseAgreement->bank_lock_reason] : null,
            'card_lock' => $leaseAgreement->isMethodLocked('card') ? ['locked_at' => $leaseAgreement->card_locked_at, 'reason' => $leaseAgreement->card_lock_reason] : null,
        ]]);
    }
}
