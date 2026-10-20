<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\PaymentLink;
use App\Services\PaymentLinkService;
use Illuminate\Http\Request;

/**
 * Staff make, send, send again and cancel payment links, and see every link a customer has had with when and where each was sent
 * (client, Joel, 2026-10-19 checklist, section 6). The work is PaymentLinkService's.
 */
class PaymentLinkController extends Controller
{
    public function __construct(private readonly PaymentLinkService $links) {}

    /** Every link of a lease, newest first, each with every time it was emailed. */
    public function index(LeaseAgreement $leaseAgreement)
    {
        return response()->json(['data' => PaymentLink::where('lease_agreement_id', $leaseAgreement->id)
            ->with(['sends.sentBy:id,name', 'createdBy:id,name', 'payment:id,type,due_date'])
            ->orderByDesc('id')
            ->get()]);
    }

    /** A link toward a payment that is due. The amount is the most it takes; left out, it is everything still due. */
    public function store(Request $request, Payment $payment)
    {
        $data = $this->validated($request);
        $payment->loadMissing('leaseAgreement.customer');
        $amount = isset($data['amount']) ? (float) $data['amount'] : $payment->chargeableAmount();

        $made = $this->links->createForPayment($payment, $amount, $request->user());

        return $this->respond($request, $made, $payment->leaseAgreement, $data);
    }

    /** A link for an extra payment toward the early purchase price. */
    public function storeExtra(Request $request, LeaseAgreement $leaseAgreement)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:100000'],
            'send' => ['nullable', 'boolean'],
            'email' => ['nullable', 'email', 'max:254'],
        ]);

        $made = $this->links->createExtra($leaseAgreement, (float) $data['amount'], $request->user());

        return $this->respond($request, $made, $leaseAgreement->loadMissing('customer'), $data);
    }

    /** Sends the link again, to the same address or another. The old address of the link stops working. */
    public function resend(Request $request, PaymentLink $paymentLink)
    {
        $data = $request->validate(['email' => ['nullable', 'email', 'max:254']]);
        $paymentLink->loadMissing('leaseAgreement.customer');
        $email = $data['email'] ?? $paymentLink->sent_to ?? $paymentLink->leaseAgreement->customer?->email;
        abort_unless($email, 422, 'There is no email address to send it to.');

        $sent = $this->links->send($paymentLink, $email, $request->user());

        return response()->json(['data' => $this->fresh($sent['link']), 'url' => $sent['url'], 'mailed' => $sent['mailed']]);
    }

    public function cancel(Request $request, PaymentLink $paymentLink)
    {
        return response()->json(['data' => $this->fresh($this->links->cancel($paymentLink, $request->user()))]);
    }

    /** @return array{amount?: mixed, send?: mixed, email?: mixed} */
    private function validated(Request $request): array
    {
        return $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0.01', 'max:100000'],
            // Whether to email it now. Left off, the link is only made and staff copy it.
            'send' => ['nullable', 'boolean'],
            'email' => ['nullable', 'email', 'max:254'],
        ]);
    }

    /**
     * @param  array{link: PaymentLink, url: string}  $made
     * @param  array<string, mixed>  $data
     */
    private function respond(Request $request, array $made, LeaseAgreement $lease, array $data)
    {
        $link = $made['link'];
        $url = $made['url'];
        $mailed = null;

        if (! empty($data['send'])) {
            $email = $data['email'] ?? $lease->customer?->email;
            abort_unless($email, 422, 'There is no email address to send it to.');
            $sent = $this->links->send($link, $email, $request->user());
            $link = $sent['link'];
            $url = $sent['url'];
            $mailed = $sent['mailed'];
        }

        return response()->json(['data' => $this->fresh($link), 'url' => $url, 'mailed' => $mailed], 201);
    }

    private function fresh(PaymentLink $link): PaymentLink
    {
        return $link->fresh(['sends.sentBy:id,name', 'createdBy:id,name', 'payment:id,type,due_date']);
    }
}
