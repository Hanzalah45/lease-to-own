<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminPermission;
use App\Models\Payment;
use App\Models\RiskRedFlag;
use App\Models\User;
use App\Notifications\PaymentStatusChangedNotification;
use App\Services\LeaseEngine;
use App\Services\RiskRedFlagger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $query = Payment::with(['leaseAgreement.customer:id,name,email', 'recordedBy:id,name'])->latest('due_date');

        if ($request->filled('lease_agreement_id')) {
            $query->where('lease_agreement_id', $request->integer('lease_agreement_id'));
        }

        return response()->json(['data' => $query->get()]);
    }

    public function show(Payment $payment)
    {
        return response()->json(['data' => $payment->load(['leaseAgreement.customer:id,name,email', 'recordedBy:id,name'])]);
    }

    /** Admins record manual payments here (cash/check/ACH confirmation) — no live processor is wired up yet. */
    public function update(Request $request, Payment $payment)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([Payment::STATUS_PENDING, Payment::STATUS_PAID, Payment::STATUS_FAILED, Payment::STATUS_REFUNDED])],
            'method' => ['sometimes', 'nullable', Rule::in(['ach', 'card', 'cash', 'other'])],
        ]);

        $wasFailed = $payment->status === Payment::STATUS_FAILED;
        $wasPaid = $payment->status === Payment::STATUS_PAID;

        $payment->update([
            'status' => $data['status'],
            'method' => $data['method'] ?? $payment->method,
            'paid_date' => $data['status'] === Payment::STATUS_PAID ? now()->toDateString() : $payment->paid_date,
            'recorded_by' => Auth::id(),
        ]);

        if ($data['status'] === Payment::STATUS_FAILED && ! $wasFailed) {
            RiskRedFlagger::flag(
                $payment->leaseAgreement->customer_id,
                RiskRedFlag::TYPE_FAILED_ACH,
                sprintf(
                    '%s payment of $%s (due %s) was marked failed.',
                    $payment->method ? strtoupper($payment->method) : 'A',
                    number_format((float) $payment->amount, 2),
                    $payment->due_date?->toDateString() ?? 'unknown date',
                ),
                $payment,
            );
        }

        // Staff are notified on both a failure and a successful payment landing —
        // only on the transition into that status, not on every re-save.
        $enteredFailed = $data['status'] === Payment::STATUS_FAILED && ! $wasFailed;
        $enteredPaid = $data['status'] === Payment::STATUS_PAID && ! $wasPaid;
        if ($enteredFailed || $enteredPaid) {
            $recipients = User::where('role', User::ROLE_SUPER_ADMIN)
                ->orWhere(function ($query) {
                    $query->where('role', User::ROLE_ADMIN)
                        ->where(function ($inner) {
                            $inner->whereDoesntHave('adminPermissions')
                                ->orWhereHas('adminPermissions', fn ($p) => $p->where('permission', AdminPermission::PAYMENT_TRACKING));
                        });
                })->get();
            Notification::send($recipients, new PaymentStatusChangedNotification($payment));
        }

        LeaseEngine::syncPaymentsPaidToDate($payment->leaseAgreement);

        // Pickup/first-payment account setup (client, 2026-09-04) — see
        // LeaseEngine::activateGuestAccountIfFirstPayment(), also used by
        // the "Mark Delivered & Paid" path (ApplicationController::update()),
        // so this isn't the only place a first payment can trigger it.
        if ($enteredPaid) {
            LeaseEngine::activateGuestAccountIfFirstPayment($payment->leaseAgreement);
        }

        // "...emails" is the field name from the customer's own preferences UI — the
        // toggle gates both the in-app and email notification together (skips
        // ->notify() entirely), matching the same on/off pattern used elsewhere
        // (e.g. status_change_emails for contract/equipment notifications).
        if (in_array($data['status'], [Payment::STATUS_PAID, Payment::STATUS_FAILED, Payment::STATUS_REFUNDED], true)) {
            $customer = $payment->leaseAgreement->customer;
            if ($customer->customerProfile?->payment_reminder_emails ?? true) {
                $customer->notify(new PaymentStatusChangedNotification($payment));
            }
        }

        return response()->json(['data' => $payment->fresh()->load('recordedBy:id,name')]);
    }
}
