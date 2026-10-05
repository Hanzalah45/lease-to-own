<?php

namespace App\Services;

use App\Models\AdminPermission;
use App\Models\Application;
use App\Models\DealerNote;
use App\Models\EquipmentUnit;
use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\ContractVoidedNotification;
use App\Notifications\NewApplicationSubmittedNotification;
use App\Notifications\RequestContractSignatureNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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
     *                            Application wizard) or the customer themselves (self-service). Always
     *                            provided by the caller since both entry points have an authenticated user.
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

    /**
     * Swaps the mower on an application that already has equipment and
     * pricing, and re-prices its lease (client, Joel, 2026-10-06: a customer
     * often changes their mind, usually over the price). Only possible while
     * nothing has been collected and the equipment has not gone out: once a
     * deposit or payment exists, the new price would not match what was paid,
     * so that case is refused until the client decides how to handle it.
     *
     * A signed contract states the old price, so it has to be voided and
     * signed again; that is only done when the caller confirms it
     * (`void_signed_contract`), never silently.
     *
     * @return array{contract_voided: bool}
     */
    public static function changeEquipment(Application $application, array $data, ?int $actorUserId = null): array
    {
        $voidSigned = (bool) ($data['void_signed_contract'] ?? false);
        $voided = null;

        DB::transaction(function () use ($application, $data, $actorUserId, $voidSigned, &$voided) {
            $locked = Application::whereKey($application->id)->lockForUpdate()->firstOrFail();
            $lease = LeaseAgreement::where('application_id', $locked->id)->lockForUpdate()->first();

            abort_unless($lease, 422, 'This application has no equipment or pricing yet. Use Add Equipment & Pricing instead.');
            abort_if(
                in_array($locked->status, [Application::STATUS_FINISHED, Application::STATUS_DECLINED, Application::STATUS_WITHDRAWN], true),
                422,
                'The mower can only be changed before the equipment is picked up, and not on a declined or withdrawn application.',
            );

            $moneyCollected = $locked->deposit_received
                || $locked->pickup_balance_received
                || $lease->payments()->where('status', Payment::STATUS_PAID)->exists()
                || $lease->payments()->where('status', Payment::STATUS_PENDING)->whereNotNull('stripe_payment_intent_id')->exists();
            abort_if($moneyCollected, 422, 'A deposit or payment has already been collected on this application, so the mower cannot be changed here. The price would no longer match what was paid.');

            $contract = $lease->contract()->first();
            abort_if(
                $contract && ! $voidSigned,
                422,
                'This lease is already signed. Changing the mower changes the price, so the signed contract has to be cancelled and the customer must sign again. Confirm that to continue.',
            );

            $ldwSelected = ($data['ldw'] ?? 'no') === 'yes';
            $termMonths = (int) $data['term_months'];
            $cashPrice = (float) $data['cash_price'];
            $terms = LeasePricing::terms($cashPrice, $termMonths, $ldwSelected);

            $oldModel = $lease->equipmentUnit?->model ?? 'the previous mower';
            $oldMonthly = $lease->totalMonthlyPayment();

            $unit = self::swapEquipmentUnit($lease, $data, $actorUserId);

            $lease->update(array_merge($terms, [
                'equipment_unit_id' => $unit->id,
                'term_months' => $termMonths,
                'cash_price' => $cashPrice,
                'sales_tax_rate' => isset($data['tax_rate']) ? (float) $data['tax_rate'] / 100 : $lease->sales_tax_rate,
                'ldw_selected' => $ldwSelected,
                'updated_by' => $actorUserId,
            ]));
            $unit->update(['expected_return_or_ownership_date' => now()->addMonthsNoOverflow($termMonths)->toDateString()]);

            // The same mower at a new price (a customer renegotiating) reads
            // differently from a different mower.
            $sameMower = $oldModel === $unit->model;
            $newMonthly = $lease->fresh()->totalMonthlyPayment();

            if ($contract) {
                $contract->update([
                    'voided_at' => now(),
                    'voided_by' => $actorUserId,
                    'void_reason' => $sameMower
                        ? "The lease for {$unit->model} was re-priced."
                        : "The mower was changed from {$oldModel} to {$unit->model} and the lease was re-priced.",
                ]);
                $voided = $contract;

                $locked->signature_received = false;
                // "Ready for pickup" requires a signed contract.
                if ($locked->status === Application::STATUS_WAITING_DELIVERY) {
                    $locked->status = Application::STATUS_WAITING_DEPOSIT;
                }
                $locked->save();
            }

            // Leaves a visible trail on the application for the rest of the team.
            DealerNote::create([
                'application_id' => $locked->id,
                'author_user_id' => $actorUserId,
                'text' => sprintf(
                    '%s ($%s/mo before, $%s/mo now, cash price $%s).%s',
                    $sameMower ? "Lease re-priced for {$unit->model}" : "Mower changed from {$oldModel} to {$unit->model}",
                    number_format($oldMonthly, 2),
                    number_format($newMonthly, 2),
                    number_format($cashPrice, 2),
                    $contract ? ' The signed contract was voided and must be signed again.' : '',
                ),
            ]);
        });

        if ($voided) {
            $application->refresh();
            $customer = $application->customer;
            try {
                if ($customer->status === 'pending') {
                    // A guest has no portal login, so they get a fresh signed link.
                    if ($application->status === Application::STATUS_WAITING_DEPOSIT) {
                        $customer->notify(new RequestContractSignatureNotification(ContractSigner::urlFor($customer, $application->leaseAgreement)));
                    }
                } elseif ($customer->customerProfile?->status_change_emails ?? true) {
                    $customer->notify(new ContractVoidedNotification($voided->fresh()));
                }
            } catch (\Throwable $e) {
                // The change is already saved; a mail hiccup must not undo it or report failure.
                report($e);
            }
        }

        return ['contract_voided' => (bool) $voided];
    }

    /**
     * Points the lease at the new mower. A unit that only exists because this
     * application created it (still "leased", never delivered) is just edited
     * in place. A real fleet unit that was already assigned is released back
     * to stock and a fresh unit is created for the new mower.
     */
    private static function swapEquipmentUnit(LeaseAgreement $lease, array $data, ?int $actorUserId): EquipmentUnit
    {
        $current = $lease->equipmentUnit;
        $isPlaceholder = $current
            && $current->status === EquipmentUnit::STATUS_LEASED
            && $current->delivery_date === null;

        $serial = trim($data['serial'] ?? '') ?: 'NA';
        $serialTaken = EquipmentUnit::where('serial_number', $serial)
            ->when($isPlaceholder, fn ($q) => $q->where('id', '!=', $current->id))
            ->exists();
        if ($serialTaken) {
            $serial = $serial.'-'.Str::upper(Str::random(5));
        }

        $attributes = [
            'model' => trim(($data['make'] ?? '').' '.($data['model'] ?? '')) ?: 'Unspecified',
            'serial_number' => $serial,
            'vin' => null,
            'gps_device_id' => null,
            'condition_notes' => trim(sprintf(
                'Condition: %s · Year: %s%s',
                $data['condition'] ?? 'unspecified',
                $data['year'] ?? 'unspecified',
                ! empty($data['description']) ? " · {$data['description']}" : '',
            )),
            'updated_by' => $actorUserId,
        ];

        if ($isPlaceholder) {
            $current->update($attributes);

            return $current->fresh();
        }

        if ($current) {
            $current->update([
                'status' => EquipmentUnit::STATUS_IN_STOCK,
                'delivery_date' => null,
                'expected_return_or_ownership_date' => null,
                'updated_by' => $actorUserId,
            ]);
        }

        return EquipmentUnit::create($attributes + ['status' => EquipmentUnit::STATUS_LEASED]);
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

        // Monthly payment, LDW and deposit are auto-calculated from cash
        // price, term and LDW, never admin-typed (client requirement,
        // 2026-09-04), and computed server-side so they stay locked
        // regardless of what's posted. See LeasePricing for the formulas.
        $terms = LeasePricing::terms($cashPrice, $termMonths, $ldwSelected);

        LeaseAgreement::create([
            'application_id' => $application->id,
            'customer_id' => $customer->id,
            'equipment_unit_id' => $equipmentUnit->id,
            'term_months' => $termMonths,
            'start_date' => $startDate,
            'renewal_date' => now()->addMonthNoOverflow()->toDateString(),
            'payment_due_day' => $data['payment_due_day'] ?? null,
            'billing_cycle' => $data['billing_cycle'] ?? null,
            'autopay_enabled' => ($data['autopay'] ?? 'no') === 'yes',
            'monthly_rental_payment' => $terms['monthly_rental_payment'],
            'sales_tax_rate' => $taxRate,
            'security_deposit' => $terms['security_deposit'],
            'cash_price' => $cashPrice,
            'total_rental_purchase_price' => $terms['total_rental_purchase_price'],
            'rental_payments_paid_to_date' => 0,
            'additional_funds' => 0,
            'ownership_status' => LeaseAgreement::OWNERSHIP_LEASING,
            'ldw_selected' => $ldwSelected,
            'ldw_amount' => $terms['ldw_amount'],
            'promo_code' => $data['promo_code'] ?? null,
        ]);

        $equipmentUnit->update([
            'expected_return_or_ownership_date' => now()->addMonthsNoOverflow($termMonths)->toDateString(),
        ]);
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
