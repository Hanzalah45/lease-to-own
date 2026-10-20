<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\LeaseAgreement;
use App\Models\PaymentReceipt;
use App\Models\PaymentReceiptSend;
use App\Services\PaymentReceipts;
use Illuminate\Http\Request;

/**
 * Staff see every receipt of a customer's lease with the log of each time it was emailed, download the stored copy, and send a receipt
 * again (client, Joel, 2026-10-19 checklist, section 8). The work is PaymentReceipts'.
 */
class PaymentReceiptController extends Controller
{
    public function __construct(private readonly PaymentReceipts $receipts) {}

    private const WITH = ['sends.sentBy:id,name', 'payment:id,type,due_date,status'];

    /** Every receipt of a lease, newest first. */
    public function index(LeaseAgreement $leaseAgreement)
    {
        return response()->json(['data' => PaymentReceipt::where('lease_agreement_id', $leaseAgreement->id)
            ->with(self::WITH)
            ->orderByDesc('id')
            ->get()]);
    }

    /** The stored copy, exactly as it was written. */
    public function download(PaymentReceipt $receipt)
    {
        return response($this->receipts->copyOf($receipt), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->receipts->fileName($receipt).'"',
        ]);
    }

    /** Sends the receipt again, to the customer's address or another. */
    public function send(Request $request, PaymentReceipt $receipt)
    {
        $data = $request->validate(['email' => ['nullable', 'email', 'max:254']]);
        $receipt->loadMissing('leaseAgreement.customer');
        $email = $data['email'] ?? $receipt->leaseAgreement->customer?->email;
        abort_unless($email, 422, 'There is no email address to send it to.');

        $send = $this->receipts->send($receipt, $email, PaymentReceiptSend::TRIGGER_MANUAL, $request->user());

        return response()->json([
            'data' => $receipt->fresh(self::WITH),
            'mailed' => $send->outcome === PaymentReceiptSend::OUTCOME_SENT,
        ]);
    }
}
