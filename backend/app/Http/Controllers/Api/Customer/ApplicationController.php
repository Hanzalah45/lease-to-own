<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Services\ApplicationCreationService;
use App\Services\LeaseEngine;
use Illuminate\Http\Request;

class ApplicationController extends Controller
{
    public function index(Request $request)
    {
        $applications = $request->user()->applications()->with('leaseAgreement.equipmentUnit')->latest()->get();

        return response()->json(['data' => $applications->map(fn ($app) => $this->present($app))]);
    }

    /** Self-service application — the customer applies for themselves, same engine as the admin wizard. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'cell_phone' => ['nullable', 'string', 'max:30'],
            'mailing_address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:2'],
            'zip' => ['nullable', 'string', 'max:10'],
            'date_of_birth' => ['nullable', 'date'],
            'drivers_license' => ['nullable', 'string', 'max:60'],
            'id_document' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],

            'residence_type' => ['nullable', 'string', 'max:30'],
            'years_at_residence' => ['nullable', 'string', 'max:10'],
            'income_source' => ['nullable', 'string', 'max:30'],
            'gross_monthly_income' => ['nullable', 'numeric', 'min:0'],
            'move_notification_agreed' => ['nullable', 'boolean'],

            'condition' => ['nullable', 'in:new,used'],
            'make' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'serial' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'ldw' => ['nullable', 'in:yes,no'],
            'cash_price' => ['required', 'numeric', 'min:0'],
            'year' => ['nullable', 'string', 'max:10'],
            'promo_code' => ['nullable', 'string', 'max:60'],

            'term_months' => ['required', 'integer', 'min:1', 'max:120'],
            'monthly_rental' => ['required', 'numeric', 'min:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'security_deposit' => ['nullable', 'numeric', 'min:0'],
            'payment_due_day' => ['nullable', 'string', 'max:10'],
            'autopay' => ['nullable', 'in:yes,no'],
        ]);

        $application = ApplicationCreationService::create($request->user(), $data, $request->file('id_document'));

        return response()->json(['data' => $this->present($application)], 201);
    }

    public function show(Request $request, Application $application)
    {
        abort_unless($application->customer_id === $request->user()->id, 404);

        return response()->json(['data' => $this->present($application)]);
    }

    private function present(Application $application): array
    {
        $application->load(['leaseAgreement.equipmentUnit', 'leaseAgreement.payments']);

        $payload = $application->toArray();

        if ($lease = $application->leaseAgreement) {
            $payload['lease_agreement']['sales_tax_amount'] = $lease->salesTaxAmount();
            $payload['lease_agreement']['total_monthly_payment'] = $lease->totalMonthlyPayment();
            $payload['lease_agreement']['payments_made'] = $lease->paymentsMadeCount();
            $payload['lease_agreement']['epo_today'] = LeaseEngine::epoToday($lease);
        }

        return $payload;
    }
}
