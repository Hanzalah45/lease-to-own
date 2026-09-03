<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\EquipmentUnit;
use App\Models\LeaseAgreement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LeaseAgreement> */
class LeaseAgreementFactory extends Factory
{
    protected $model = LeaseAgreement::class;

    public function definition(): array
    {
        return [
            'application_id' => Application::factory(),
            'customer_id' => User::factory(),
            'equipment_unit_id' => EquipmentUnit::factory(),
            'term_months' => 36,
            'start_date' => now()->toDateString(),
            'renewal_date' => now()->addMonthNoOverflow()->toDateString(),
            'monthly_rental_payment' => 150,
            'sales_tax_rate' => 0.0825,
            'security_deposit' => 0,
            'cash_price' => 3000,
            'total_rental_purchase_price' => 5400,
            'ownership_status' => LeaseAgreement::OWNERSHIP_LEASING,
        ];
    }
}
