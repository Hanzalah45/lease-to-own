<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Services\LeaseEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LeaseAgreementController extends Controller
{
    public function index()
    {
        // Eager-loading payments lets paymentsMadeCount()/epoToday() below use
        // the loaded collection instead of a fresh COUNT query per lease.
        $leases = LeaseAgreement::with(['customer:id,name,email', 'equipmentUnit', 'contract', 'updatedBy:id,name', 'payments'])->latest()->get();

        return response()->json(['data' => $leases->map(fn ($lease) => $this->present($lease))]);
    }

    public function show(LeaseAgreement $leaseAgreement)
    {
        $leaseAgreement->load(['customer:id,name,email', 'equipmentUnit', 'payments', 'contract', 'updatedBy:id,name']);

        return response()->json(['data' => $this->present($leaseAgreement, includeSchedule: true)]);
    }

    public function update(Request $request, LeaseAgreement $leaseAgreement)
    {
        abort_if(
            $leaseAgreement->contract()->exists(),
            422,
            'This lease agreement has already been signed and its terms can no longer be changed.',
        );

        $data = $request->validate([
            'term_months' => ['sometimes', 'integer', 'min:1', 'max:120'],
            'monthly_rental_payment' => ['sometimes', 'numeric', 'min:0'],
            'sales_tax_rate' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'security_deposit' => ['sometimes', 'numeric', 'min:0'],
            'additional_funds' => ['sometimes', 'numeric', 'min:0'],
            'autopay_enabled' => ['sometimes', 'boolean'],
        ]);

        $leaseAgreement->update(array_merge($data, ['updated_by' => Auth::id()]));

        if (isset($data['term_months']) || isset($data['monthly_rental_payment'])) {
            $leaseAgreement->update([
                'total_rental_purchase_price' => LeaseEngine::totalRentalPurchasePrice(
                    (float) $leaseAgreement->monthly_rental_payment,
                    (int) $leaseAgreement->term_months,
                ),
            ]);

            // A schedule may already exist from the approval step — rebuild it
            // at the new terms rather than leaving stale rows on the books.
            if ($leaseAgreement->payments()->exists()) {
                abort_if(
                    $leaseAgreement->payments()->where('status', Payment::STATUS_PAID)->exists(),
                    422,
                    'Cannot change the term or rental amount once a payment has been made against this lease.',
                );
                LeaseEngine::regeneratePaymentSchedule($leaseAgreement);
            }
        }

        return response()->json(['data' => $this->present($leaseAgreement->fresh(), includeSchedule: true)]);
    }

    private function present(LeaseAgreement $lease, bool $includeSchedule = false): array
    {
        $payload = array_merge($lease->toArray(), [
            'sales_tax_amount' => $lease->salesTaxAmount(),
            'total_monthly_payment' => $lease->totalMonthlyPayment(),
            'payments_made' => $lease->paymentsMadeCount(),
            'epo_today' => LeaseEngine::epoToday($lease),
        ]);

        if ($includeSchedule) {
            $payload['epo_schedule'] = LeaseEngine::fullSchedule($lease);
        }

        return $payload;
    }
}
