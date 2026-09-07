<?php

namespace App\Services;

use App\Models\AdminPermission;
use App\Models\Application;
use App\Models\EquipmentUnit;
use App\Models\LeaseAgreement;
use App\Models\User;
use App\Notifications\NewApplicationSubmittedNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Shared "create a lease application" logic — used by both the admin wizard
 * (Admin\ApplicationController, an admin acting on a customer's behalf) and
 * the customer's own self-service wizard (Customer\ApplicationController).
 * Both paths produce an identical Application + LeaseAgreement + EquipmentUnit
 * and run the same risk-scoring/underwriting checks.
 */
class ApplicationCreationService
{
    /**
     * @param  int  $actorUserId  Who submitted this application — an admin (New
     *   Application wizard) or the customer themselves (self-service). Always
     *   provided by the caller since both entry points have an authenticated user.
     */
    public static function create(User $customer, array $data, ?UploadedFile $idDocument, ?string $salesPerson = null, ?int $actorUserId = null, ?UploadedFile $utilityBill = null): Application
    {
        abort_unless($customer->isCustomer(), 422, 'Selected account is not a customer.');

        $application = Application::create([
            'customer_id' => $customer->id,
            'created_by' => $actorUserId,
            'status' => Application::STATUS_WAITING_REVIEW,
            'internal_notes' => ! empty($salesPerson) ? "Sales person: {$salesPerson}" : null,
        ]);

        self::buildEquipmentAndLease($application, $customer, $data);

        $mappedResidenceType = self::upsertCustomerProfile($customer, $data, $idDocument, $utilityBill, $actorUserId);

        if (! empty($data['cell_phone'])) {
            $customer->update(['phone' => $data['cell_phone']]);
        }

        // Underwriting policy: apartment residences are declined immediately, before scoring.
        if (RiskScoringService::requiresAutoDecline($mappedResidenceType)) {
            $application->update([
                'status' => Application::STATUS_DECLINED,
                'status_notes' => 'Apartments are automatically declined per underwriting policy.',
            ]);
        }

        // Deliberately no RiskScoringService::evaluate() call here — the
        // client was explicit that background check / bank verification must
        // be triggered by an admin, not automatically at submission (see
        // Admin\RiskProfileController::runBackgroundCheck()).
        self::notifyReviewers($application);

        return $application->fresh();
    }

    /**
     * Guest (no-login) application — a customer applied via the public,
     * unauthenticated link before ever having an account. Creates the shadow
     * User + CustomerProfile + Application, but deliberately no equipment or
     * LeaseAgreement yet (nothing has been priced) — an admin adds those
     * later via attachLease(), once the phone call in waiting_approval has
     * happened. Also deliberately skips RiskScoringService::evaluate(): the
     * client wants verification triggered manually by an admin (see
     * Admin\RiskProfileController), not automatically at submission.
     */
    public static function createGuestApplication(array $data, ?UploadedFile $idDocument, ?UploadedFile $utilityBill): Application
    {
        $customer = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['cell_phone'] ?? null,
            // Unusable until Phase 6's account-setup link lets the customer pick
            // their own password at first-payment/pickup — see EmailVerificationSigner.
            'password' => Hash::make(Str::random(40)),
            'role' => User::ROLE_CUSTOMER,
            'status' => 'pending',
        ]);

        $application = Application::create([
            'customer_id' => $customer->id,
            'created_by' => null,
            'status' => Application::STATUS_WAITING_REVIEW,
        ]);

        $mappedResidenceType = self::upsertCustomerProfile($customer, $data, $idDocument, $utilityBill, null);

        if (RiskScoringService::requiresAutoDecline($mappedResidenceType)) {
            $application->update([
                'status' => Application::STATUS_DECLINED,
                'status_notes' => 'Apartments are automatically declined per underwriting policy.',
            ]);
        }

        self::notifyReviewers($application);

        return $application->fresh();
    }

    /**
     * Rejoins a guest-originated application (no equipment/lease yet) with
     * the normal lease-creation flow, once an admin has priced it after the
     * approval call. Application must not already have a lease.
     */
    public static function attachLease(Application $application, array $data, ?int $actorUserId = null): Application
    {
        abort_if($application->leaseAgreement, 422, 'This application already has equipment and lease terms.');

        self::buildEquipmentAndLease($application, $application->customer, $data);

        return $application->fresh();
    }

    private static function buildEquipmentAndLease(Application $application, User $customer, array $data): void
    {
        // Equipment unit — reuse-by-serial where possible, disambiguate on collision
        // (multiple applications commonly arrive with a placeholder "NA" serial).
        $serial = trim($data['serial'] ?? '') ?: 'NA';
        if (EquipmentUnit::where('serial_number', $serial)->exists()) {
            $serial = $serial.'-'.Str::upper(Str::random(5));
        }

        $equipmentUnit = EquipmentUnit::create([
            'model' => trim(($data['make'] ?? '').' '.($data['model'] ?? '')) ?: 'Unspecified',
            'serial_number' => $serial,
            'condition_notes' => trim(sprintf(
                'Condition: %s · Year: %s%s',
                $data['condition'] ?? 'unspecified',
                $data['year'] ?? 'unspecified',
                ! empty($data['description']) ? " · {$data['description']}" : '',
            )),
            'status' => EquipmentUnit::STATUS_LEASED,
        ]);

        $termMonths = (int) $data['term_months'];
        $cashPrice = (float) $data['cash_price'];
        $taxRate = (float) ($data['tax_rate'] ?? 0) / 100;
        $startDate = now()->toDateString();
        $ldwSelected = ($data['ldw'] ?? 'no') === 'yes';

        // Monthly payment auto-calculates from cash price and term — never
        // admin-typed (client requirement, 2026-09-04) — using the official
        // divisor table from Outdoor Fix's own customer-facing lease terms
        // sheet: "Divide the cash price (excluding tax) by 19.8 for
        // 36-months, 16.0 for 24-months, or 10.0 for 12-months." These are
        // NOT proportional to term (10/12, 16/24, 19.8/36 are different
        // ratios), so it's a lookup, not a formula — only these three terms
        // are priced. Computed server-side, not trusted from the client, so
        // it stays locked regardless of what's posted.
        $monthlyRental = round($cashPrice / self::monthlyPaymentDivisor($termMonths), 2);

        // Official pricing blueprint (client, 2026-09-04): taking LDW adds a
        // recurring 0.75%/month charge; DECLINING it isn't free either — it
        // adds a smaller 0.35%/month "no-LDW surcharge" instead. Both live in
        // the same ldw_amount column (nullable decimal, no schema change
        // needed) since exactly one ever applies per lease — ldw_selected
        // says which — and both get billed monthly the same way via
        // LeaseAgreement::ldwMonthlyAmount()/totalMonthlyPayment().
        $ldwAmount = $ldwSelected
            ? round($cashPrice * 0.0075, 2)
            : round($cashPrice * 0.0035, 2);

        // Security deposit (blueprint, 2026-09-04): 7% of cash price when LDW
        // is taken, or 3x the (base + surcharge) monthly payment when
        // declined. The $150 tracking device fee is a SEPARATE line item —
        // due alongside the deposit, but not part of it (see
        // LeaseAgreement::TRACKING_DEVICE_FEE, added wherever "total due
        // today" is shown, not here).
        $securityDeposit = $ldwSelected
            ? round($cashPrice * 0.07, 2)
            : round(($monthlyRental + $ldwAmount) * 3, 2);

        LeaseAgreement::create([
            'application_id' => $application->id,
            'customer_id' => $customer->id,
            'equipment_unit_id' => $equipmentUnit->id,
            'term_months' => $termMonths,
            'start_date' => $startDate,
            'renewal_date' => now()->addMonthNoOverflow()->toDateString(),
            'payment_due_day' => $data['payment_due_day'] ?? null,
            'autopay_enabled' => ($data['autopay'] ?? 'no') === 'yes',
            'monthly_rental_payment' => $monthlyRental,
            'sales_tax_rate' => $taxRate,
            'security_deposit' => $securityDeposit,
            'cash_price' => $cashPrice,
            'total_rental_purchase_price' => LeaseEngine::totalRentalPurchasePrice($monthlyRental, $termMonths),
            'rental_payments_paid_to_date' => 0,
            'additional_funds' => 0,
            'ownership_status' => LeaseAgreement::OWNERSHIP_LEASING,
            'ldw_selected' => $ldwSelected,
            'ldw_amount' => $ldwAmount,
            'promo_code' => $data['promo_code'] ?? null,
        ]);

        $equipmentUnit->update([
            'expected_return_or_ownership_date' => now()->addMonthsNoOverflow($termMonths)->toDateString(),
        ]);
    }

    /**
     * Official divisor table from Outdoor Fix's customer-facing lease terms
     * sheet (2026-09-04) — only 12/24/36-month terms are priced.
     */
    private static function monthlyPaymentDivisor(int $termMonths): float
    {
        return match ($termMonths) {
            12 => 10.0,
            24 => 16.0,
            36 => 19.8,
            default => throw new \InvalidArgumentException("Unsupported lease term: {$termMonths} months. Only 12, 24, or 36 are priced."),
        };
    }

    /** @return string|null the mapped (coarse) residence type, so callers can run the auto-decline check without re-mapping it themselves. */
    private static function upsertCustomerProfile(User $customer, array $data, ?UploadedFile $idDocument, ?UploadedFile $utilityBill, ?int $actorUserId): ?string
    {
        $mappedResidenceType = RiskScoringService::mapResidenceType($data['residence_type'] ?? null);

        $idDocumentPath = $idDocument ? $idDocument->store('id-documents', 'local') : null;
        $utilityBillPath = $utilityBill ? $utilityBill->store('utility-bills', 'local') : null;
        $rawResidenceType = $data['residence_type'] ?? null;

        $customer->customerProfile()->updateOrCreate(['user_id' => $customer->id], array_merge(array_filter([
            'government_id_type' => ! empty($data['drivers_license']) ? 'drivers_license' : null,
            'government_id_number' => $data['drivers_license'] ?? null,
            'government_id_document_path' => $idDocumentPath,
            'utility_bill_document_path' => $utilityBillPath,
            'address_line_1' => $data['mailing_address'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'zip' => $data['zip'] ?? null,
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'residence_type' => $mappedResidenceType,
            'years_at_residence' => $data['years_at_residence'] ?? null,
            'previous_address' => $data['previous_address'] ?? null,
            'move_notification_agreed' => $data['move_notification_agreed'] ?? false,
            'employment_status' => $data['income_source'] ?? null,
            'employer_name' => $data['employer_name'] ?? null,
            'employer_phone' => $data['employer_phone'] ?? null,
            'employer_position' => $data['employer_position'] ?? null,
            'monthly_income' => $data['gross_monthly_income'] ?? null,
            // Rent vs mortgage are mutually exclusive — driven by the raw
            // (unmapped) residence_type, which distinguishes own_* from
            // rent_* (customer_profiles' own residence_type enum is coarser).
            'landlord_name' => str_starts_with((string) $rawResidenceType, 'rent_') ? ($data['landlord_name'] ?? null) : null,
            'landlord_phone' => str_starts_with((string) $rawResidenceType, 'rent_') ? ($data['landlord_phone'] ?? null) : null,
            'monthly_rent' => str_starts_with((string) $rawResidenceType, 'rent_') ? ($data['monthly_rent'] ?? null) : null,
            'mortgage_amount' => str_starts_with((string) $rawResidenceType, 'own_') ? ($data['mortgage_amount'] ?? null) : null,
            'mortgage_years' => str_starts_with((string) $rawResidenceType, 'own_') ? ($data['mortgage_years'] ?? null) : null,
            'alternate_contact_1_name' => $data['alternate_contact_1_name'] ?? null,
            'alternate_contact_1_phone' => $data['alternate_contact_1_phone'] ?? null,
            'alternate_contact_2_name' => $data['alternate_contact_2_name'] ?? null,
            'alternate_contact_2_phone' => $data['alternate_contact_2_phone'] ?? null,
        ], fn ($value) => $value !== null), ['updated_by' => $actorUserId]));

        return $mappedResidenceType;
    }

    private static function notifyReviewers(Application $application): void
    {
        $recipients = User::where('role', User::ROLE_SUPER_ADMIN)
            ->orWhere(function ($query) {
                $query->where('role', User::ROLE_ADMIN)
                    ->where(function ($inner) {
                        $inner->whereDoesntHave('adminPermissions')
                            ->orWhereHas('adminPermissions', fn ($p) => $p->where('permission', AdminPermission::APPLICATION_REVIEW));
                    });
            })->get();
        Notification::send($recipients, new NewApplicationSubmittedNotification($application));
    }
}
