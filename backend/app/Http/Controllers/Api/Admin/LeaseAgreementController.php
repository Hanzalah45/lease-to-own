<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\LeaseAgreement;
use App\Services\LeaseEngine;
use Illuminate\Http\Request;

class LeaseAgreementController extends Controller
{
    public function index()
    {
        $leases = LeaseAgreement::with(['customer:id,name,email', 'equipmentUnit'])->latest()->get();

        return response()->json(['data' => $leases->map(fn ($lease) => $this->present($lease))]);
    }

    public function show(LeaseAgreement $leaseAgreement)
    {
        $leaseAgreement->load(['customer:id,name,email', 'equipmentUnit', 'payments', 'contract']);

        return response()->json(['data' => $this->present($leaseAgreement, includeSchedule: true)]);
    }

    public function update(Request $request, LeaseAgreement $leaseAgreement)
    {
        $data = $request->validate([
            'term_months' => ['sometimes', 'integer', 'min:1', 'max:120'],
            'monthly_rental_payment' => ['sometimes', 'numeric', 'min:0'],
            'sales_tax_rate' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'security_deposit' => ['sometimes', 'numeric', 'min:0'],
            'additional_funds' => ['sometimes', 'numeric', 'min:0'],
            'autopay_enabled' => ['sometimes', 'boolean'],
        ]);

        $leaseAgreement->update($data);

        if (isset($data['term_months']) || isset($data['monthly_rental_payment'])) {
            $leaseAgreement->update([
                'total_rental_purchase_price' => LeaseEngine::totalRentalPurchasePrice(
                    (float) $leaseAgreement->monthly_rental_payment,
                    (int) $leaseAgreement->term_months,
                ),
            ]);
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
