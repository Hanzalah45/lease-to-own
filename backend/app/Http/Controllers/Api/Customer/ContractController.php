<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\AdminPermission;
use App\Models\Contract;
use App\Models\LeaseAgreement;
use App\Models\User;
use App\Notifications\ContractSignedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

/**
 * Built-in e-signature capture for the customer's own lease — the plan's
 * "Built-in electronic signature flow" for launch, ahead of a DocuSign/
 * HelloSign upgrade. No PDF is generated here; the signed terms are the
 * live LeaseAgreement itself, viewable/printable from the customer portal.
 */
class ContractController extends Controller
{
    public function index(Request $request)
    {
        $contracts = Contract::whereHas('leaseAgreement', fn ($q) => $q->where('customer_id', $request->user()->id))
            ->with('leaseAgreement.equipmentUnit')
            ->latest('signed_at')
            ->get();

        return response()->json(['data' => $contracts]);
    }

    public function show(Request $request, Contract $contract)
    {
        abort_unless($contract->leaseAgreement->customer_id === $request->user()->id, 404);

        return response()->json(['data' => $contract->load('leaseAgreement.equipmentUnit')]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'lease_agreement_id' => ['required', 'integer', 'exists:lease_agreements,id'],
        ]);

        $lease = LeaseAgreement::findOrFail($data['lease_agreement_id']);
        abort_unless($lease->customer_id === $request->user()->id, 404);
        abort_if($lease->contract()->exists(), 422, 'This lease agreement has already been signed.');

        $contract = Contract::create([
            'lease_agreement_id' => $lease->id,
            'signer_user_id' => $request->user()->id,
            'version' => 1,
            'signed_at' => now(),
        ]);

        $recipients = User::where('role', User::ROLE_SUPER_ADMIN)
            ->orWhere(function ($query) {
                $query->where('role', User::ROLE_ADMIN)
                    ->where(function ($q) {
                        $q->whereDoesntHave('adminPermissions')
                            ->orWhereHas('adminPermissions', fn ($p) => $p->where('permission', AdminPermission::CONTRACT_GENERATION));
                    });
            })->get();
        Notification::send($recipients->push($request->user()), new ContractSignedNotification($lease));

        return response()->json(['data' => $contract->load('leaseAgreement.equipmentUnit')], 201);
    }
}
