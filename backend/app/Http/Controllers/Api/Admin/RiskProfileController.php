<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminPermission;
use App\Models\RiskProfile;
use App\Models\RiskRedFlag;
use App\Models\User;
use App\Notifications\RedFlagResolvedNotification;
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
            'background_check_notes' => ['sometimes', 'nullable', 'string'],
            'landlord_contact_required' => ['sometimes', 'boolean'],
            'landlord_contact_reason' => ['sometimes', 'nullable', 'string'],
        ]);

        $riskProfile->update(array_merge($data, ['updated_by' => Auth::id()]));
        RiskScoringService::recomputeScore($riskProfile);

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
