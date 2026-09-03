<?php

namespace Database\Factories;

use App\Models\Contract;
use App\Models\LeaseAgreement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Contract> */
class ContractFactory extends Factory
{
    protected $model = Contract::class;

    public function definition(): array
    {
        return [
            'lease_agreement_id' => LeaseAgreement::factory(),
            'signer_user_id' => User::factory(),
            'signer_name' => fake()->name(),
            'version' => 1,
            'signed_at' => now(),
        ];
    }
}
