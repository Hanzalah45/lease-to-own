<?php

namespace App\Services;

use App\Models\RiskProfile;
use App\Models\User;

/**
 * Milestone 3 — Risk Assessment Engine. There is no live identity/employment
 * verification API or credit-bureau background-check connection wired in
 * yet (none was provided) — "verified" here means the required information
 * has been collected and is on file, not that a third party attested to it.
 * Bank verification is the one leg that's genuinely third-party-verified,
 * via the real Plaid connection (see PlaidController) — this service only
 * reads that result, it never touches Plaid itself.
 */
class RiskScoringService
{
    private const MIN_INCOME_TO_PAYMENT_RATIO = 3.0;

    /** Maps the wizard's fine-grained residence options down to the schema's 3-value enum. */
    public static function mapResidenceType(?string $wizardValue): ?string
    {
        return match ($wizardValue) {
            'rent_apartment' => 'apartment',
            'own_single', 'own_multi', 'rent_house' => 'house',
            'other' => 'other',
            default => null,
        };
    }

    /** Underwriting policy: apartment residences are declined automatically, before any other check runs. */
    public static function requiresAutoDecline(?string $mappedResidenceType): bool
    {
        return $mappedResidenceType === 'apartment';
    }

    /**
     * Recomputes and persists the customer's risk profile. Pass the lease's
     * monthly rental payment when known so the income/payment affordability
     * check can run; omitted, the background check simply stays pending.
     */
    public static function evaluate(User $customer, ?float $monthlyRentalPayment = null): RiskProfile
    {
        $profile = $customer->customerProfile;

        $identityStatus = ($profile?->government_id_number && $profile?->date_of_birth)
            ? RiskProfile::VERIFICATION_VERIFIED
            : RiskProfile::VERIFICATION_PENDING;

        $employmentStatus = ($profile?->employment_status && $profile?->monthly_income)
            ? RiskProfile::VERIFICATION_VERIFIED
            : RiskProfile::VERIFICATION_PENDING;

        $bankStatus = $profile?->bank_verified_at
            ? RiskProfile::VERIFICATION_VERIFIED
            : RiskProfile::VERIFICATION_PENDING;

        [$backgroundStatus, $backgroundNotes] = self::assessAffordability($profile?->monthly_income, $monthlyRentalPayment);

        $landlordContactRequired = $profile?->residence_type === 'house'
            && in_array($profile?->years_at_residence, ['lt1', '1-3'], true)
            && ! $profile?->landlord_name;

        $riskProfile = RiskProfile::updateOrCreate(
            ['customer_id' => $customer->id],
            [
                'identity_verification_status' => $identityStatus,
                'employment_verification_status' => $employmentStatus,
                'bank_verification_status' => $bankStatus,
                'background_check_status' => $backgroundStatus,
                'background_check_notes' => $backgroundNotes,
                'risk_score' => self::score($identityStatus, $employmentStatus, $bankStatus, $backgroundStatus),
                'landlord_contact_required' => $landlordContactRequired,
                'landlord_contact_reason' => $landlordContactRequired
                    ? 'Renting less than 3 years at current residence — landlord verification not yet on file.'
                    : null,
            ]
        );

        return $riskProfile;
    }

    private static function assessAffordability(?string $monthlyIncome, ?float $monthlyRentalPayment): array
    {
        if (! $monthlyIncome || ! $monthlyRentalPayment) {
            return [RiskProfile::BACKGROUND_PENDING, null];
        }

        $ratio = (float) $monthlyIncome / max(0.01, $monthlyRentalPayment);

        if ($ratio < self::MIN_INCOME_TO_PAYMENT_RATIO) {
            return [
                RiskProfile::BACKGROUND_FLAGGED,
                sprintf(
                    'Gross monthly income (%s) is under the standard %sx monthly-payment underwriting ratio for a %s payment.',
                    number_format((float) $monthlyIncome, 2),
                    rtrim(rtrim(number_format(self::MIN_INCOME_TO_PAYMENT_RATIO, 1), '0'), '.'),
                    number_format($monthlyRentalPayment, 2),
                ),
            ];
        }

        return [RiskProfile::BACKGROUND_CLEAR, null];
    }

    private static function score(string $identity, string $employment, string $bank, string $background): int
    {
        $score = 0;
        $score += $identity === RiskProfile::VERIFICATION_VERIFIED ? 30 : 0;
        $score += $employment === RiskProfile::VERIFICATION_VERIFIED ? 20 : 0;
        $score += $bank === RiskProfile::VERIFICATION_VERIFIED ? 30 : 0;
        $score += match ($background) {
            RiskProfile::BACKGROUND_CLEAR => 20,
            RiskProfile::BACKGROUND_PENDING => 10,
            default => 0,
        };

        return $score;
    }
}
