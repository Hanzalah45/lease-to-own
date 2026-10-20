<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\PaymentReceipt;
use App\Services\PaymentReceipts;
use Illuminate\Http\Request;

/**
 * A customer's own receipts: the list, and the stored PDF copy of each (client, Joel, 2026-10-19 checklist, section 8). Only receipts of
 * the customer's own leases are ever shown.
 */
class ReceiptController extends Controller
{
    public function __construct(private readonly PaymentReceipts $receipts) {}

    public function index(Request $request)
    {
        return response()->json(['data' => PaymentReceipt::whereHas('leaseAgreement', fn ($q) => $q->where('customer_id', $request->user()->id))
            ->orderByDesc('id')
            ->get()
            ->map(fn (PaymentReceipt $r) => [
                'id' => $r->id,
                'receipt_number' => $r->receipt_number,
                'kind' => $r->kind,
                'payment_id' => $r->payment_id,
                'amount' => $r->amount,
                'description' => $r->details['description'] ?? null,
                'issued_at' => $r->issued_at,
            ])]);
    }

    public function download(Request $request, PaymentReceipt $receipt)
    {
        abort_unless($receipt->leaseAgreement->customer_id === $request->user()->id, 404);

        return response($this->receipts->copyOf($receipt), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->receipts->fileName($receipt).'"',
        ]);
    }
}
