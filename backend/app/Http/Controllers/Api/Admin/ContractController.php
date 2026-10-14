<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Notifications\ContractVoidedNotification;
use App\Services\ContractPdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ContractController extends Controller
{
    public function download(Contract $contract)
    {
        $path = ContractPdfService::ensure($contract);

        return Storage::disk('local')->download($path, "lease-agreement-{$contract->lease_agreement_id}.pdf");
    }

    /**
     * Voids a signature without deleting it — the row and its PDF stay on
     * file as a permanent record. Clears the way for the customer to sign
     * again (LeaseAgreement::contract() stops returning a voided row) and
     * for the admin to edit the lease's terms again in the meantime.
     *
     * The customer is only emailed when the admin asks for it (client, Joel,
     * 2026-10-14): a signature is voided for a reason, and an automatic "please
     * sign again" email went out whether or not staff wanted the customer to.
     */
    public function void(Request $request, Contract $contract)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:2', 'max:1000'],
            'notify_customer' => ['sometimes', 'boolean'],
        ]);

        // Locked and re-checked inside the transaction so two admins voiding
        // the same contract at once can't both succeed — the second would
        // otherwise silently overwrite the first's voided_by/void_reason.
        DB::transaction(function () use ($contract, $data) {
            $locked = Contract::whereKey($contract->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->voided_at, 422, 'This contract has already been voided.');

            $locked->update([
                'voided_at' => now(),
                'voided_by' => Auth::id(),
                'void_reason' => $data['reason'],
            ]);
        });

        $contract = $contract->fresh()->loadMissing('leaseAgreement.customer.customerProfile');
        $customer = $contract->leaseAgreement?->customer;
        if ($customer && ($data['notify_customer'] ?? false) && ($customer->customerProfile?->status_change_emails ?? true)) {
            $customer->notify(new ContractVoidedNotification($contract));
        }

        return response()->json(['data' => $contract->load('voidedBy:id,name')]);
    }
}
