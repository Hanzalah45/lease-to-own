<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApplicationCreationService;
use App\Services\ApplicationValidationRules;
use Illuminate\Http\Request;

/**
 * The public, no-login application entry point (client requirement,
 * 2026-09-04): a prospective customer applies from one generic shared link
 * before ever having an account. Collects exactly what CustomerInfoStep +
 * RiskVerificationStep collect in the logged-in wizards, plus name/email
 * (which those wizards get from the already-authenticated user instead) —
 * no equipment or pricing, since nothing has been priced yet.
 */
class GuestApplicationController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate(array_merge(
            ApplicationValidationRules::identity(),
            ApplicationValidationRules::customerAndRisk(),
        ));

        ApplicationCreationService::createGuestApplication($data, $request->file('id_document'), $request->file('utility_bill'));

        // Deliberately no application data in the response — this endpoint is
        // unauthenticated, and there's no session yet to scope a follow-up
        // lookup to, so nothing about the created record is returned.
        return response()->json([
            'message' => 'Thanks! Your application has been submitted. Someone from Outdoor Fix will reach out shortly.',
        ], 201);
    }
}
