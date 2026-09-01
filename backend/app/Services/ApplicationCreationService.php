<?php

namespace App\Services;

use App\Models\AdminPermission;
use App\Models\Application;
use App\Models\EquipmentUnit;
use App\Models\LeaseAgreement;
use App\Models\User;
use App\Notifications\NewApplicationSubmittedNotification;
use Illuminate\Http\UploadedFile;
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
    public static function create(User $customer, array $data, ?UploadedFile $idDocument, ?string $salesPerson = null): Application
    {
        abort_unless($customer->isCustomer(), 422, 'Selected account is not a customer.');

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
                ! empty($data['description']) ? " — {$data['description']}" : '',
            )),
            'status' => EquipmentUnit::STATUS_LEASED,
        ]);

        $application = Application::create([
            'customer_id' => $customer->id,
            'status' => Application::STATUS_SUBMITTED,
            'internal_notes' => ! empty($salesPerson) ? "Sales person: {$salesPerson}" : null,
        ]);

        $termMonths = (int) $data['term_months'];
        $monthlyRental = (float) $data['monthly_rental'];
        $taxRate = (float) ($data['tax_rate'] ?? 0) / 100;
        $startDate = now()->toDateString();

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
            'security_deposit' => $data['security_deposit'] ?? 0,
            'cash_price' => $data['cash_price'],
            'total_rental_purchase_price' => LeaseEngine::totalRentalPurchasePrice($monthlyRental, $termMonths),
            'rental_payments_paid_to_date' => 0,
            'additional_funds' => 0,
            'ownership_status' => LeaseAgreement::OWNERSHIP_LEASING,
            'ldw_selected' => ($data['ldw'] ?? 'no') === 'yes',
            'promo_code' => $data['promo_code'] ?? null,
        ]);

        $equipmentUnit->update([
            'expected_return_or_ownership_date' => now()->addMonthsNoOverflow($termMonths)->toDateString(),
        ]);

        $mappedResidenceType = RiskScoringService::mapResidenceType($data['residence_type'] ?? null);

        $idDocumentPath = $idDocument ? $idDocument->store('id-documents', 'local') : null;

        $customer->customerProfile()->updateOrCreate(['user_id' => $customer->id], array_filter([
            'government_id_type' => ! empty($data['drivers_license']) ? 'drivers_license' : null,
            'government_id_number' => $data['drivers_license'] ?? null,
            'government_id_document_path' => $idDocumentPath,
            'address_line_1' => $data['mailing_address'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'zip' => $data['zip'] ?? null,
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'residence_type' => $mappedResidenceType,
            'years_at_residence' => $data['years_at_residence'] ?? null,
            'move_notification_agreed' => $data['move_notification_agreed'] ?? false,
            'employment_status' => $data['income_source'] ?? null,
            'monthly_income' => $data['gross_monthly_income'] ?? null,
        ], fn ($value) => $value !== null));

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

        RiskScoringService::evaluate($customer, $monthlyRental);

        $recipients = User::where('role', User::ROLE_SUPER_ADMIN)
            ->orWhere(function ($query) {
                $query->where('role', User::ROLE_ADMIN)
                    ->where(function ($inner) {
                        $inner->whereDoesntHave('adminPermissions')
                            ->orWhereHas('adminPermissions', fn ($p) => $p->where('permission', AdminPermission::APPLICATION_REVIEW));
                    });
            })->get();
        Notification::send($recipients, new NewApplicationSubmittedNotification($application));

        return $application->fresh();
    }
}
