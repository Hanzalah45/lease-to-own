<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentPart;
use App\Models\PaymentRefund;
use App\Services\PaymentRefunder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Staff give money back for a paid payment, all of it or part (client, Joel, 2026-10-19). The work is PaymentRefunder's.
 */
class PaymentRefundController extends Controller
{
    public const WITH = ['leaseAgreement.customer:id,name,email', 'recordedBy:id,name', 'attempts', 'refunds.refundedBy:id,name', 'disputes', 'notes.author:id,name', 'parts.recordedBy:id,name', 'parts.refunds'];

    public function store(Request $request, Payment $payment, PaymentRefunder $refunder)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:100000'],
            'reason' => ['required', Rule::in(array_keys(PaymentRefund::REASONS))],
            // Why a refund was needed has to be written down when none of the listed reasons fits.
            'note' => ['nullable', 'string', 'max:500', 'required_if:reason,other'],
            // A payment that was paid in parts is refunded one part at a time: which one.
            'part_id' => ['nullable', 'integer'],
        ]);
        $part = isset($data['part_id']) ? PaymentPart::where('payment_id', $payment->id)->whereKey($data['part_id'])->firstOrFail() : null;

        $refund = $refunder->refund($payment, (float) $data['amount'], $data['reason'], $data['note'] ?? null, Auth::user(), $part);

        return response()->json([
            'data' => $payment->fresh(self::WITH),
            'refund' => $refund,
        ], 201);
    }

    /** A refund Stripe never answered is cancelled by hand once staff have checked in Stripe that it was not made. */
    public function cancel(PaymentRefund $paymentRefund, PaymentRefunder $refunder)
    {
        $refunder->cancelUnanswered($paymentRefund, Auth::user());

        return response()->json(['data' => $paymentRefund->payment->fresh(self::WITH)]);
    }
}
