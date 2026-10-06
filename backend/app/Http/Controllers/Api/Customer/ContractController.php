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
use App\Services\BillingSchedule;
use App\Services\CommonValidationRules;
use App\Services\ContractPdfService;
use App\Services\LeaseEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

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
            'signer_name' => ['required', 'string', 'min:'.CommonValidationRules::NAME_MIN, 'max:'.CommonValidationRules::NAME_MAX],
            // Billing cycle (client, 2026-10-05): chosen right before signing
            // so the contract states it. Required unless the lease already
            // carries one (an admin may have pre-selected it).
            'billing_cycle' => ['nullable', Rule::in(BillingSchedule::CYCLES)],
        ]);

        $lease = LeaseAgreement::findOrFail($data['lease_agreement_id']);
        abort_unless($lease->customer_id === $request->user()->id, 404);

        // Terms are only locked in once verification has passed — signing any
        // earlier would let a customer bind themselves to numbers that
        // underwriting hasn't actually signed off on yet. Per the client's
        // 2026-09-04 flow: verification -> approval -> signed contract ->
        // deposit, so "waiting on deposit" onward is the earliest eligible
        // point (in_verification itself hasn't concluded yet).
        $eligibleStatuses = [
            Application::STATUS_WAITING_DEPOSIT,
            Application::STATUS_WAITING_DELIVERY,
            Application::STATUS_FINISHED,
        ];
        abort_unless(
            in_array($lease->application?->status, $eligibleStatuses, true),
            422,
            'This lease agreement cannot be signed until the application clears verification.',
        );

        // Locking the lease row serializes concurrent sign attempts (e.g. a
        // double-click) so the "already signed" check below is re-verified
        // against committed data, not a pre-lock read that could be stale by
        // the time this request's own INSERT lands.
        $contract = DB::transaction(function () use ($lease, $data, $request) {
            $lockedLease = LeaseAgreement::whereKey($lease->id)->lockForUpdate()->firstOrFail();
            abort_if($lockedLease->contract()->exists(), 422, 'This lease agreement has already been signed.');

            // Saved in the same transaction as the signature, so the billing
            // cycle the customer agreed to can never differ from the one
            // stored (and it is immutable once signed: terms edits are blocked).
            $cycle = $data['billing_cycle'] ?? $lockedLease->billing_cycle;
            abort_unless($cycle, 422, 'Choose your billing cycle (the 1st or the 15th) before signing.');
            // A customer who already picked up (re-signing the new contract) can
            // only choose a billing day that is still ahead of them.
            LeaseEngine::assertBillingCycleStillAhead($lockedLease, $cycle);
            if ($lockedLease->billing_cycle !== $cycle) {
                $lockedLease->update(['billing_cycle' => $cycle]);
            }

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

        // Keeps the "Ready for pickup" checklist honest — it reads this flag
        // directly, and it otherwise stayed false forever unless an admin
        // separately remembered to toggle it by hand (real gap, 2026-09-04).
        // Signing is also what starts the 30-day deposit hold (client,
        // 2026-09-05): the customer just agreed to the hold-and-forfeiture
        // clause in the contract itself, so the clock starts now, not at
        // some earlier "waiting on deposit" status change.
        // A lease that was already picked up has no deposit to hold, so
        // re-signing it (the new contract, 2026-10-06) must not start a new hold.
        $pickedUp = $lease->application?->status === Application::STATUS_FINISHED;
        $lease->application?->update([
            'signature_received' => true,
            ...($pickedUp ? [] : ['deposit_hold_expires_at' => now()->addDays(30)]),
        ]);

        // A customer who already picked up moves onto the new billing-cycle
        // schedule now that they have chosen their billing day.
        LeaseEngine::rebuildScheduleAfterResign($lease);

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
            try {
                Notification::send($recipients, new ContractPdfGenerationFailedNotification($contract));
            } catch (\Throwable $notifyException) {
                report($notifyException);
            }
        }

        // The signature is already committed above — a mail transport hiccup
        // here (e.g. a rate limit) must not turn a successful signing into
        // an apparent failure for the customer.
        try {
            Notification::send($recipients->push($request->user()), new ContractSignedNotification($lease));
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(['data' => $contract->fresh()->load('leaseAgreement.equipmentUnit')], 201);
    }

    public function download(Request $request, Contract $contract)
    {
        abort_unless($contract->leaseAgreement->customer_id === $request->user()->id, 404);

        $path = ContractPdfService::ensure($contract);

        return Storage::disk('local')->download($path, "lease-agreement-{$contract->lease_agreement_id}.pdf");
    }

    /**
     * The full agreement text, readable before signing (client, Joel,
     * 2026-10-01) — guest counterpart is PublicContractController::preview().
     */
    public function preview(Request $request, LeaseAgreement $leaseAgreement)
    {
        abort_unless($leaseAgreement->customer_id === $request->user()->id, 404);
        $leaseAgreement->loadMissing('equipmentUnit', 'customer.customerProfile');

        // Lets the customer see the contract with the billing cycle they are
        // CONSIDERING, before it is saved (it is only saved at signing).
        $preview = $request->validate(['billing_cycle' => ['nullable', Rule::in(BillingSchedule::CYCLES)]]);
        if (! empty($preview['billing_cycle'])) {
            $leaseAgreement->billing_cycle = $preview['billing_cycle'];
        }

        return response(ContractPdfService::preview($leaseAgreement), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="lease-agreement-preview.pdf"',
        ]);
    }
}
