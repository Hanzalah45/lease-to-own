<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\AdminPermission;
use App\Models\Application;
use App\Models\Contract;
use App\Models\LeaseAgreement;
use App\Models\User;
use App\Notifications\ContractPdfGenerationFailedNotification;
use App\Notifications\ContractSignedNotification;
use App\Services\ContractPdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * Built-in e-signature capture for the customer's own lease — the plan's
 * "Built-in electronic signature flow" for launch, ahead of a DocuSign/
 * HelloSign upgrade.
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
            'signer_name' => ['required', 'string', 'min:2', 'max:80'],
        ]);

        $lease = LeaseAgreement::findOrFail($data['lease_agreement_id']);
        abort_unless($lease->customer_id === $request->user()->id, 404);

        // Terms are only locked in once the application is approved — signing
        // any earlier would let a customer bind themselves to numbers that
        // underwriting hasn't actually signed off on yet.
        $eligibleStatuses = [
            Application::STATUS_APPROVED,
            Application::STATUS_COMPLETED,
            Application::STATUS_PROCESSED,
            Application::STATUS_FUNDED_PAID,
        ];
        abort_unless(
            in_array($lease->application?->status, $eligibleStatuses, true),
            422,
            'This lease agreement cannot be signed until the application is approved.',
        );

        // Locking the lease row serializes concurrent sign attempts (e.g. a
        // double-click) so the "already signed" check below is re-verified
        // against committed data, not a pre-lock read that could be stale by
        // the time this request's own INSERT lands.
        $contract = DB::transaction(function () use ($lease, $data, $request) {
            $lockedLease = LeaseAgreement::whereKey($lease->id)->lockForUpdate()->firstOrFail();
            abort_if($lockedLease->contract()->exists(), 422, 'This lease agreement has already been signed.');

            return Contract::create([
                'lease_agreement_id' => $lockedLease->id,
                'signer_user_id' => $request->user()->id,
                'signer_name' => $data['signer_name'],
                // Starts at 1, and increments if a prior signature on this lease
                // was voided and this is a re-sign — the version count is the
                // running total across the lease's whole history, not just active ones.
                'version' => ($lockedLease->contracts()->max('version') ?? 0) + 1,
                'signed_at' => now(),
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 512),
            ]);
        });

        $recipients = User::where('role', User::ROLE_SUPER_ADMIN)
            ->orWhere(function ($query) {
                $query->where('role', User::ROLE_ADMIN)
                    ->where(function ($q) {
                        $q->whereDoesntHave('adminPermissions')
                            ->orWhereHas('adminPermissions', fn ($p) => $p->where('permission', AdminPermission::CONTRACT_GENERATION));
                    });
            })->get();

        try {
            ContractPdfService::ensure($contract);
        } catch (\Throwable $e) {
            report($e);
            Notification::send($recipients, new ContractPdfGenerationFailedNotification($contract));
        }

        Notification::send($recipients->push($request->user()), new ContractSignedNotification($lease));

        return response()->json(['data' => $contract->fresh()->load('leaseAgreement.equipmentUnit')], 201);
    }

    public function download(Request $request, Contract $contract)
    {
        abort_unless($contract->leaseAgreement->customer_id === $request->user()->id, 404);

        $path = ContractPdfService::ensure($contract);

        return Storage::disk('local')->download($path, "lease-agreement-{$contract->lease_agreement_id}.pdf");
    }
}
