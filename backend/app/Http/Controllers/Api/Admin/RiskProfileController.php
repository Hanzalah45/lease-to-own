<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\RiskProfile;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RiskProfileController extends Controller
{
    public function index()
    {
        $profiles = RiskProfile::with(['customer:id,name,email', 'redFlags'])->latest()->get();

        return response()->json(['data' => $profiles]);
    }

    public function show(RiskProfile $riskProfile)
    {
        return response()->json(['data' => $riskProfile->load(['customer:id,name,email', 'redFlags'])]);
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

        $riskProfile->update($data);

        return response()->json(['data' => $riskProfile->fresh()->load('redFlags')]);
    }
}
