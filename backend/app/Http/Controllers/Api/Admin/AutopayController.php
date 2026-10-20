<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Services\AuditLog;
use App\Services\AutopayCharger;
use App\Services\AutopayRevocationService;
use Illuminate\Http\Request;

/**
 * Staff controls over automatic monthly charging (client, Joel, 2026-10-05).
 * A signed lease's terms, autopay_enabled included, can no longer be edited,
 * so pausing is the only way staff can stop automatic charges on one lease;
 * a failed payment is not retried automatically, so retrying is staff's call.
 */
class AutopayController extends Controller
{
    public function pause(LeaseAgreement $leaseAgreement)
    {
        abort_unless($leaseAgreement->autopay_enabled, 422, 'AutoPay is not enabled on this lease.');

        $leaseAgreement->update(['autopay_paused_at' => $leaseAgreement->autopay_paused_at ?? now()]);
        AuditLog::record('autopay.paused', 'AutoPay was paused: no automatic charges until it is resumed.', ['lease' => $leaseAgreement]);

        return response()->json(['data' => ['autopay_paused_at' => $leaseAgreement->autopay_paused_at]]);
    }

    public function resume(LeaseAgreement $leaseAgreement)
    {
        $leaseAgreement->update(['autopay_paused_at' => null]);
        AuditLog::record('autopay.resumed', 'AutoPay was resumed.', ['lease' => $leaseAgreement]);

        return response()->json(['data' => ['autopay_paused_at' => null]]);
    }

    /**
     * A customer asked in writing (a letter, an email) to turn AutoPay off, as the authorization allows with three business days' notice:
     * staff enter it here, and the same three business days run from today, after which the system switches AutoPay off.
     */
    public function recordRevocation(Request $request, LeaseAgreement $leaseAgreement, AutopayRevocationService $revocations)
    {
        $request->validate(['customer_asked_in_writing' => ['accepted']]);

        $revocation = $revocations->request($leaseAgreement, $request->user(), $request, 'staff');

        return response()->json(['data' => ['effective_on' => $revocation->effective_on->toDateString()]]);
    }

    /** Charges a failed rental payment again, starting a fresh round with the customer's primary method. */
    public function retry(Payment $payment, AutopayCharger $charger)
    {
        abort_unless(config('billing.autopay_charging_enabled'), 409, 'Automatic charging is switched off in this environment.');

        $result = $charger->retry($payment);
        AuditLog::record('autopay.retried', 'A failed payment was charged again with AutoPay.', ['payment' => $payment, 'details' => ['result' => $result]]);

        return response()->json([
            'data' => [
                'result' => $result,
                'payment' => $payment->fresh()->load(['recordedBy:id,name', 'attempts']),
            ],
        ]);
    }
}
