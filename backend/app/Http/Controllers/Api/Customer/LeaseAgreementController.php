<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\LeaseAgreement;
use App\Services\LeaseEngine;
use Illuminate\Http\Request;

class LeaseAgreementController extends Controller
{
    public function index(Request $request)
    {
        $leases = $request->user()->leaseAgreements()->with('equipmentUnit')->latest()->get();

        return response()->json(['data' => $leases->map(fn ($lease) => $this->present($lease))]);
    }

    public function show(Request $request, LeaseAgreement $leaseAgreement)
    {
        abort_unless($leaseAgreement->customer_id === $request->user()->id, 404);

        $leaseAgreement->load('equipmentUnit');

        return response()->json(['data' => $this->present($leaseAgreement, includeSchedule: true)]);
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
