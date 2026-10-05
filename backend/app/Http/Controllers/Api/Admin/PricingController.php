<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\ApplicationValidationRules;
use App\Services\LeasePricing;
use Illuminate\Http\Request;

/**
 * The price calculator (client, Joel, 2026-10-06): the same lease pricing the
 * New Application wizard shows, without needing a customer or an application,
 * so a price can be checked with a customer before a mower is entered. It
 * runs the real pricing code (LeasePricing), so a quoted number is exactly
 * what the system would store and bill.
 */
class PricingController extends Controller
{
    public function quote(Request $request)
    {
        $data = $request->validate(ApplicationValidationRules::priceQuote());

        return response()->json(['data' => LeasePricing::quote(
            (float) $data['cash_price'],
            (float) ($data['tax_rate'] ?? 0),
            (int) $data['term_months'],
            ($data['ldw'] ?? 'no') === 'yes',
        )]);
    }
}
