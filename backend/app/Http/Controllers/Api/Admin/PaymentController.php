<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Payment;
use App\Services\LeaseEngine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $query = Payment::with(['leaseAgreement.customer:id,name,email', 'recordedBy:id,name', 'attempts'])->latest('due_date');

        if ($request->filled('lease_agreement_id')) {
            $query->where('lease_agreement_id', $request->integer('lease_agreement_id'));
        } else {
            $this->onlyPaymentsThatNeedTracking($query);
        }

        return response()->json(['data' => $query->get()]);
    }

    /**
     * The Payments tab lists every lease at once, so it leaves out rows nobody
     * can act on (client, Joel, 2026-10-16: it showed 2029 due dates and
     * customers who never picked up): a monthly payment of a lease that has not
     * started yet (a leftover schedule built when the lease was created, which
     * pickup replaces), and anything still waiting on a customer whose
     * application was declined or withdrawn. Money that already moved (paid,
     * refunded) always stays, so the totals do not change. A single lease's own
     * list (lease_agreement_id) is never trimmed.
     */
    private function onlyPaymentsThatNeedTracking(Builder $query): void
    {
        $query->where(function (Builder $q) {
            $q->whereNotIn('status', [Payment::STATUS_PENDING, Payment::STATUS_FAILED])
                ->orWhere(function (Builder $open) {
                    $open->whereHas('leaseAgreement.application', fn (Builder $a) => $a->whereNotIn('status', [Application::STATUS_DECLINED, Application::STATUS_WITHDRAWN]))
                        ->where(function (Builder $started) {
                            $started->where('type', '!=', Payment::TYPE_RENTAL)
                                ->orWhereHas('leaseAgreement.payments', fn (Builder $p) => $p->where('type', Payment::TYPE_RENTAL)->where('status', Payment::STATUS_PAID));
                        });
                });
        });
    }

    public function show(Payment $payment)
    {
        return response()->json(['data' => $payment->load(['leaseAgreement.customer:id,name,email', 'recordedBy:id,name', 'attempts'])]);
    }

    /** Admins record manual payments here (cash/check/ACH confirmation), or a processor webhook records one automatically — see LeaseEngine::applyPaymentStatusChange(). */
    public function update(Request $request, Payment $payment)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([Payment::STATUS_PENDING, Payment::STATUS_PAID, Payment::STATUS_FAILED, Payment::STATUS_REFUNDED])],
            'method' => ['sometimes', 'nullable', Rule::in(['ach', 'card', 'cash', 'other'])],
        ]);

        $payment = LeaseEngine::applyPaymentStatusChange($payment, $data['status'], $data['method'] ?? null, Auth::id());

        return response()->json(['data' => $payment->load('recordedBy:id,name')]);
    }
}
