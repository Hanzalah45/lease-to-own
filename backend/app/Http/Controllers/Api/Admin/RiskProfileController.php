<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminPermission;
use App\Models\Application;
use App\Models\RiskProfile;
use App\Models\RiskRedFlag;
use App\Models\User;
use App\Notifications\BackgroundCheckRunNotification;
use App\Notifications\RedFlagResolvedNotification;
use App\Notifications\RequestBankVerificationNotification;
use App\Services\BankVerificationSigner;
use App\Services\RiskScoringService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

class RiskProfileController extends Controller
{
    public function index()
    {
        $profiles = RiskProfile::with(['customer:id,name,email', 'redFlags.resolvedBy:id,name', 'updatedBy:id,name'])->latest()->get();

        return response()->json(['data' => $profiles]);
    }

    public function show(RiskProfile $riskProfile)
    {
        return response()->json(['data' => $riskProfile->load(['customer:id,name,email', 'redFlags.resolvedBy:id,name', 'updatedBy:id,name'])]);
    }

    /** Manual admin override of a customer's risk profile — used from the application detail page's Risk card. */
    public function update(Request $request, RiskProfile $riskProfile)
    {
        $data = $request->validate([
            'identity_verification_status' => ['sometimes', Rule::in(['pending', 'verified', 'failed'])],
            'employment_verification_status' => ['sometimes', Rule::in(['pending', 'verified', 'failed'])],
            'bank_verification_status' => ['sometimes', Rule::in(['pending', 'verified', 'failed'])],
            'background_check_status' => ['sometimes', Rule::in(['pending', 'clear', 'flagged'])],
            'background_check_notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'landlord_contact_required' => ['sometimes', 'boolean'],
            'landlord_contact_reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $riskProfile->update(array_merge($data, ['updated_by' => Auth::id()]));
        RiskScoringService::recomputeScore($riskProfile);

        return response()->json(['data' => $riskProfile->fresh()->load(['redFlags.resolvedBy:id,name', 'updatedBy:id,name'])]);
    }

    /**
     * "Run background check" (client, 2026-09-04): the affordability check
     * used to run automatically at submission — the client was explicit that
     * verification must be admin-triggered instead, once the customer has
     * agreed to price/terms on the approval call (waiting_approval ->
     * in_verification, see Application::LEGAL_STATUS_TRANSITIONS). Recomputes
     * the full risk profile (identity/employment/bank all being "on file"
     * checks anyway, not third-party calls) so it isn't stale by the time an
     * admin acts on it.
     */
    public function runBackgroundCheck(Application $application)
    {
        abort_unless($application->status === Application::STATUS_IN_VERIFICATION, 422, 'Move this application to verification before running a background check.');
        abort_unless($application->leaseAgreement, 422, 'Add equipment and pricing before running a background check.');

        $riskProfile = RiskScoringService::evaluate($application->customer, (float) $application->leaseAgreement->monthly_rental_payment);

        $recipients = User::where('role', User::ROLE_SUPER_ADMIN)
            ->orWhere(function ($query) {
                $query->where('role', User::ROLE_ADMIN)
                    ->where(function ($inner) {
                        $inner->whereDoesntHave('adminPermissions')
                            ->orWhereHas('adminPermissions', fn ($p) => $p->where('permission', AdminPermission::RISK_ASSESSMENT));
                    });
            })->get();
        Notification::send($recipients, new BackgroundCheckRunNotification($application, $riskProfile->background_check_status));

        return response()->json(['data' => $riskProfile->fresh()->load(['redFlags.resolvedBy:id,name', 'updatedBy:id,name'])]);
    }

    /**
     * "Request bank verification" (client, 2026-09-04): a separate,
     * independently-trackable action from runBackgroundCheck() above — an
     * admin cannot complete Plaid on the customer's behalf (Plaid Link needs
     * the account holder's own bank-credential session), so this instead
     * emails the customer a signed link (BankVerificationSigner) to a public
     * page that can complete Plaid without requiring them to already be
     * logged in — a guest-originated customer's account has no usable
     * password until Phase 6's pickup/first-payment account setup.
     */
    public function requestBankVerification(Application $application)
    {
        abort_unless($application->status === Application::STATUS_IN_VERIFICATION, 422, 'Move this application to verification before requesting bank verification.');

        $riskProfile = RiskProfile::firstOrCreate(['customer_id' => $application->customer_id]);
        $riskProfile->update(['bank_verification_requested_at' => now()]);

        $application->customer->notify(new RequestBankVerificationNotification(BankVerificationSigner::urlFor($application->customer)));

        return response()->json(['data' => $riskProfile->fresh()->load(['redFlags.resolvedBy:id,name', 'updatedBy:id,name'])]);
    }

    /** Marks one red flag resolved — used from the application detail page's Risk card. */
    public function resolveRedFlag(RiskProfile $riskProfile, RiskRedFlag $redFlag)
    {
        abort_unless($redFlag->risk_profile_id === $riskProfile->id, 404);
        abort_if($redFlag->resolved, 422, 'This red flag has already been resolved.');

        $redFlag->update(['resolved' => true, 'resolved_by' => Auth::id(), 'resolved_at' => now()]);

        $recipients = User::where('role', User::ROLE_SUPER_ADMIN)
            ->orWhere(function ($query) {
                $query->where('role', User::ROLE_ADMIN)
                    ->where(function ($inner) {
                        $inner->whereDoesntHave('adminPermissions')
                            ->orWhereHas('adminPermissions', fn ($p) => $p->where('permission', AdminPermission::RISK_ASSESSMENT));
                    });
            })->get()
            ->reject(fn (User $u) => $u->id === Auth::id());
        Notification::send($recipients, new RedFlagResolvedNotification($redFlag->fresh()));

        return response()->json(['data' => $riskProfile->fresh()->load(['redFlags.resolvedBy:id,name', 'updatedBy:id,name'])]);
    }
}
