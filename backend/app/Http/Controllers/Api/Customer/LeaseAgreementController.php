<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\LeaseAgreement;
use App\Services\BillingSchedule;
use App\Services\LeaseEngine;
use Illuminate\Http\Request;

class LeaseAgreementController extends Controller
{
    public function index(Request $request)
    {
        // Eager-loading payments lets paymentsMadeCount()/epoToday() below use
        // the loaded collection instead of a fresh COUNT query per lease.
        $leases = $request->user()->leaseAgreements()->with(['equipmentUnit', 'contract', 'payments'])->latest()->get();

        return response()->json(['data' => $leases->map(fn ($lease) => $this->present($lease))]);
    }

    public function show(Request $request, LeaseAgreement $leaseAgreement)
    {
        abort_unless($leaseAgreement->customer_id === $request->user()->id, 404);

        $leaseAgreement->load(['equipmentUnit', 'contract', 'payments']);

        return response()->json(['data' => $this->present($leaseAgreement, includeSchedule: true)]);
    }

    private function present(LeaseAgreement $lease, bool $includeSchedule = false): array
    {
        $payload = array_merge($lease->toArray(), [
            'sales_tax_amount' => $lease->salesTaxAmount(),
            'total_monthly_payment' => $lease->totalMonthlyPayment(),
            'payments_made' => $lease->paymentsMadeCount(),
            'epo_today' => LeaseEngine::epoToday($lease),
            // Dual pricing (client, 2026-10-05): the bank and card price of every charge.
            'pricing' => $lease->pricingSummary(),
        ]);

        if ($includeSchedule) {
            $payload['epo_schedule'] = LeaseEngine::fullSchedule($lease);

            // What each billing cycle would look like, shown on the signing
            // page where the customer chooses. Before pickup it is "if you
            // picked up today" (there is no real schedule yet); for a customer
            // who already picked up (re-signing the new contract, 2026-10-06)
            // it is built from their real pickup date.
            if (! $lease->contract) {
                $pickup = LeaseEngine::pickupDate($lease);
                $payload['billing_preview'] = BillingSchedule::illustrate($lease, $pickup);
                $payload['billing_preview_pickup_date'] = $pickup;
            }
        }

        return $payload;
    }
}
