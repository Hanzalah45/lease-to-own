<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PaymentLinkService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The page a payment link opens (client, Joel, 2026-10-19 checklist, section 6): what is due, exactly what the customer pays by bank
 * account or by card, and paying. No login: the secret in the link is what proves it is for them. Both calls take the secret in the
 * body, so it is never written into a URL log.
 */
class PublicPaymentLinkController extends Controller
{
    public function __construct(private readonly PaymentLinkService $links) {}

    public function show(Request $request)
    {
        $link = $this->link($request);

        return response()->json(['data' => $this->links->show($link)]);
    }

    public function pay(Request $request)
    {
        $link = $this->link($request);
        $data = $request->validate([
            'method' => ['required', Rule::in(['bank', 'card'])],
            // The total shown on screen: refused with a 409 if the price moved underneath the customer.
            'expected_total_cents' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json(['data' => $this->links->pay(
            $link,
            $data['method'],
            isset($data['expected_total_cents']) ? (int) $data['expected_total_cents'] : null,
        )]);
    }

    private function link(Request $request)
    {
        // The secret is 48 characters; anything much longer is not one.
        $token = (string) $request->validate(['token' => ['required', 'string', 'max:128']])['token'];

        return PaymentLinkService::find($token) ?? abort(404, 'This link is not valid. Please contact us for a new one.');
    }
}
