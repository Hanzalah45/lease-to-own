<?php

namespace Database\Factories;

use App\Models\LeaseAgreement;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Payment> */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'lease_agreement_id' => LeaseAgreement::factory(),
            'amount' => 100,
            'due_date' => now()->addMonth(),
            'status' => Payment::STATUS_PENDING,
        ];
    }
}
