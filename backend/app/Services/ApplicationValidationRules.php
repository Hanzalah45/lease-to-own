<?php

namespace App\Services;

use Illuminate\Validation\Rule;

/**
 * Shared Laravel validation rules for every "submit a lease application"
 * entry point (Customer\ApplicationController, Admin\ApplicationController,
 * GuestApplicationController) — one definition per field so a length limit
 * can't drift between them. Builds on CommonValidationRules for the fields
 * every controller shares (name/email/phone/street/city), and mirrors the
 * frontend's own field-specific limits (frontend/src/lib/validation.ts) for
 * the fields specific to an application, rather than reusing Laravel's
 * `max:255` VARCHAR default everywhere, which is a storage artifact, not a
 * real limit on how long a name, phone number, or address actually is.
 */
class ApplicationValidationRules
{
    // Person-name, phone, street, city, and email limits (min and max) come
    // from CommonValidationRules — the same constants every other
    // controller in the app uses, so a limit can't drift between "submit an
    // application" and, say, "admin creates a customer."
    private const NAME_MIN = CommonValidationRules::NAME_MIN;

    private const NAME_MAX = CommonValidationRules::NAME_MAX;

    private const PHONE_MIN = CommonValidationRules::PHONE_MIN;

    private const PHONE_MAX = CommonValidationRules::PHONE_MAX;

    private const STREET_MIN = CommonValidationRules::STREET_MIN;

    private const STREET_MAX = CommonValidationRules::STREET_MAX;

    private const CITY_MAX = CommonValidationRules::CITY_MAX;

    // Mirrors DRIVERS_LICENSE_MAX / PROMO_CODE_MAX in validation.ts (neither
    // has a frontend minimum beyond "required").
    private const DRIVERS_LICENSE_MAX = 60;

    private const PROMO_CODE_MAX = 60;

    // Also shared with Admin\EquipmentUnitController via CommonValidationRules.
    private const EQUIPMENT_MODEL_MIN = CommonValidationRules::EQUIPMENT_MODEL_MIN;

    private const EQUIPMENT_MODEL_MAX = CommonValidationRules::EQUIPMENT_MODEL_MAX;

    private const SERIAL_MIN = CommonValidationRules::SERIAL_MIN;

    private const SERIAL_MAX = CommonValidationRules::SERIAL_MAX;

    // Mirrors NOTES_MAX in validation.ts — a UX ceiling so a free-text box
    // can't swallow a pasted document (no minimum — an empty description is fine).
    private const NOTES_MAX = 1000;

    /** Applicant identity — required only on the guest (no-login) path, where there's no existing User to read it from. */
    public static function identity(): array
    {
        return [
            'name' => CommonValidationRules::name(),
            'email' => CommonValidationRules::email('unique:users,email'),
        ];
    }

    /** Contact info, residence/verification, and employment fields — shared by every entry point. */
    public static function customerAndRisk(): array
    {
        return [
            'cell_phone' => ['nullable', 'string', 'min:'.self::PHONE_MIN, 'max:'.self::PHONE_MAX],
            'mailing_address' => ['nullable', 'string', 'min:'.self::STREET_MIN, 'max:'.self::STREET_MAX],
            'city' => ['nullable', 'string', 'min:'.self::NAME_MIN, 'max:'.self::CITY_MAX],
            'state' => ['nullable', 'string', 'max:2'],
            'zip' => ['nullable', 'string', 'max:10'],
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today', 'after_or_equal:'.now()->subDays(365 * 120)->toDateString()],
            'drivers_license' => ['nullable', 'string', 'max:'.self::DRIVERS_LICENSE_MAX],
            'id_document' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],

            'residence_type' => ['nullable', Rule::in(['rent_apartment', 'own_single', 'own_multi', 'rent_house', 'other'])],
            'years_at_residence' => ['nullable', 'string', 'max:10'],
            'previous_address' => ['nullable', 'string', 'min:'.self::STREET_MIN, 'max:'.self::STREET_MAX],
            'landlord_name' => ['nullable', 'string', 'min:'.self::NAME_MIN, 'max:'.self::NAME_MAX],
            'landlord_phone' => ['nullable', 'string', 'min:'.self::PHONE_MIN, 'max:'.self::PHONE_MAX],
            'monthly_rent' => ['nullable', 'numeric', 'min:0'],
            'mortgage_amount' => ['nullable', 'numeric', 'min:0'],
            'mortgage_years' => ['nullable', 'string', 'max:10'],
            'utility_bill' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
            'alternate_contact_1_name' => ['nullable', 'string', 'min:'.self::NAME_MIN, 'max:'.self::NAME_MAX],
            'alternate_contact_1_phone' => ['nullable', 'string', 'min:'.self::PHONE_MIN, 'max:'.self::PHONE_MAX],
            'alternate_contact_2_name' => ['nullable', 'string', 'min:'.self::NAME_MIN, 'max:'.self::NAME_MAX],
            'alternate_contact_2_phone' => ['nullable', 'string', 'min:'.self::PHONE_MIN, 'max:'.self::PHONE_MAX],
            'income_source' => ['nullable', 'string', 'max:30'],
            'employer_name' => ['nullable', 'string', 'min:'.self::NAME_MIN, 'max:'.self::NAME_MAX],
            'employer_phone' => ['nullable', 'string', 'min:'.self::PHONE_MIN, 'max:'.self::PHONE_MAX],
            'employer_position' => ['nullable', 'string', 'min:'.self::NAME_MIN, 'max:'.self::NAME_MAX],
            'gross_monthly_income' => ['nullable', 'numeric', 'min:0'],
            'move_notification_agreed' => ['required', 'accepted'],
        ];
    }

    /** Equipment + lease terms — not part of the guest (no-login) path, since nothing is priced yet at that point. */
    public static function equipmentAndLease(): array
    {
        return [
            'condition' => ['nullable', 'in:new,used'],
            'make' => ['nullable', 'string', 'min:'.self::EQUIPMENT_MODEL_MIN, 'max:'.self::EQUIPMENT_MODEL_MAX],
            'model' => ['nullable', 'string', 'min:'.self::EQUIPMENT_MODEL_MIN, 'max:'.self::EQUIPMENT_MODEL_MAX],
            'serial' => ['nullable', 'string', 'min:'.self::SERIAL_MIN, 'max:'.self::SERIAL_MAX],
            'description' => ['nullable', 'string', 'max:'.self::NOTES_MAX],
            'ldw' => ['nullable', 'in:yes,no'],
            'cash_price' => ['required', 'numeric', 'min:0', 'max:'.LeasePricing::CASH_PRICE_MAX],
            'year' => ['nullable', 'string', 'max:10'],
            'promo_code' => ['nullable', 'string', 'max:'.self::PROMO_CODE_MAX],

            // Only these three terms have a defined monthly-payment divisor
            // (see LeasePricing::monthlyPaymentDivisor) — the official
            // 12/24/36-month lease terms sheet, 2026-09-04.
            'term_months' => ['required', 'integer', Rule::in(LeasePricing::TERMS)],
            'monthly_rental' => ['required', 'numeric', 'min:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'security_deposit' => ['nullable', 'numeric', 'min:0'],
            // Legacy free-text day (never used to compute a due date). Still
            // validated if an old client sends it; superseded by billing_cycle.
            'payment_due_day' => ['nullable', 'integer', 'between:1,31'],
            // Billing cycles (client, 2026-10-05): the 1st or the 15th, no
            // custom dates. Optional here: the customer chooses it right
            // before signing if the admin leaves it blank.
            'billing_cycle' => ['nullable', Rule::in(BillingSchedule::CYCLES)],
            'autopay' => ['nullable', 'in:yes,no'],
        ];
    }

    /**
     * Fields for swapping the mower on an existing application and re-pricing
     * its lease. The same rules as a new application's equipment and pricing,
     * except the mower must be identified by a make or a model.
     */
    public static function changeEquipment(): array
    {
        $rules = collect(self::equipmentAndLease())->only([
            'condition', 'make', 'model', 'serial', 'description', 'ldw', 'cash_price', 'year', 'term_months', 'tax_rate',
        ])->all();

        $rules['make'] = ['required_without:model', 'nullable', 'string', 'min:'.self::EQUIPMENT_MODEL_MIN, 'max:'.self::EQUIPMENT_MODEL_MAX];
        $rules['model'] = ['required_without:make', 'nullable', 'string', 'min:'.self::EQUIPMENT_MODEL_MIN, 'max:'.self::EQUIPMENT_MODEL_MAX];
        // A new application tolerates 0 here; re-pricing an existing lease at $0 is always a typo.
        $rules['cash_price'] = ['required', 'numeric', 'gt:0', 'max:'.LeasePricing::CASH_PRICE_MAX];

        return $rules + [
            // Required only when the lease already has a signed contract: the
            // price changes, so that signature is voided and must be redone.
            'void_signed_contract' => ['nullable', 'boolean'],
        ];
    }

    /** Inputs for the standalone price calculator: no customer, no application. */
    public static function priceQuote(): array
    {
        return [
            'cash_price' => ['required', 'numeric', 'gt:0', 'max:'.LeasePricing::CASH_PRICE_MAX],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'term_months' => ['required', 'integer', Rule::in(LeasePricing::TERMS)],
            'ldw' => ['nullable', 'in:yes,no'],
        ];
    }

    /** Salesperson name — admin-entry-point only. */
    public static function salesPerson(): array
    {
        return ['sales_person' => ['nullable', 'string', 'min:'.self::NAME_MIN, 'max:'.self::NAME_MAX]];
    }
}
