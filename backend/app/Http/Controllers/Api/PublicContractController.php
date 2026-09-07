<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminPermission;
use App\Models\Application;
use App\Models\Contract;
use App\Models\LeaseAgreement;
use App\Models\User;
use App\Notifications\ContractPdfGenerationFailedNotification;
use App\Notifications\ContractSignedNotification;
use App\Services\CommonValidationRules;
use App\Services\ContractPdfService;
use App\Services\ContractSigner;
use App\Services\LeaseEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * The signed-link counterpart to Customer\ContractController — reached from
 * RequestContractSignatureNotification's emailed link rather than an
 * authenticated session, since a guest-originated customer has no working
 * login yet (see ContractSigner). Every request here re-validates the
 * signature itself; nothing here trusts Sanctum. Mirrors the eligibility and
 * locking logic in Customer\ContractController::store() exactly.
 */
class PublicContractController extends Controller
{
    public function show(Request $request)
    {
        $customer = $this->resolveSignedCustomer($request);
        $lease = LeaseAgreement::with('equipmentUnit', 'contract', 'payments')->findOrFail($request->integer('lease'));
        abort_unless($lease->customer_id === $customer->id, 404);

        // Same computed shape Customer\LeaseAgreementController returns —
        // the frontend's LeaseAgreement type expects total_monthly_payment
        // etc. to already be present, not derived client-side.
        $payload = array_merge($lease->toArray(), [
            'sales_tax_amount' => $lease->salesTaxAmount(),
            'total_monthly_payment' => $lease->totalMonthlyPayment(),
            'payments_made' => $lease->paymentsMadeCount(),
            'epo_today' => LeaseEngine::epoToday($lease),
        ]);

        return response()->json(['data' => $payload]);
    }

    public function store(Request $request)
    {
        $customer = $this->resolveSignedCustomer($request);

        $data = $request->validate([
            'signer_name' => ['required', 'string', 'min:'.CommonValidationRules::NAME_MIN, 'max:'.CommonValidationRules::NAME_MAX],
        ]);

        $lease = LeaseAgreement::findOrFail($request->integer('lease'));
        abort_unless($lease->customer_id === $customer->id, 404);

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

        $contract = DB::transaction(function () use ($lease, $data, $customer, $request) {
            $lockedLease = LeaseAgreement::whereKey($lease->id)->lockForUpdate()->firstOrFail();
            abort_if($lockedLease->contract()->exists(), 422, 'This lease agreement has already been signed.');

            return Contract::create([
                'lease_agreement_id' => $lockedLease->id,
                'signer_user_id' => $customer->id,
                'signer_name' => $data['signer_name'],
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
        $lease->application?->update([
            'signature_received' => true,
            'deposit_hold_expires_at' => now()->addDays(30),
        ]);

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
            Notification::send($recipients->push($customer), new ContractSignedNotification($lease));
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(['data' => $contract->fresh()->load('leaseAgreement.equipmentUnit')], 201);
    }

    private function resolveSignedCustomer(Request $request): User
    {
        $data = $request->validate([
            'id' => ['required', 'integer'],
            'lease' => ['required', 'integer'],
            'hash' => ['required', 'string'],
            'expires' => ['required', 'integer'],
            'signature' => ['required', 'string'],
        ]);

        $customer = User::find($data['id']);
        $linkIsValid = $customer
            && $customer->isCustomer()
            && hash_equals(sha1($customer->email), $data['hash'])
            && ContractSigner::isValid($data['id'], $data['lease'], $data['hash'], $data['expires'], $data['signature']);

        abort_unless($linkIsValid, 422, 'This signing link is invalid or has expired.');

        return $customer;
    }
}
