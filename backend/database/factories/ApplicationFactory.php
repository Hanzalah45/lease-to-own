<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Application> */
class ApplicationFactory extends Factory
{
    protected $model = Application::class;

    public function definition(): array
    {
        return [
            'customer_id' => User::factory(),
            'status' => Application::STATUS_SUBMITTED,
        ];
    }

    public function approved(): static
    {
        return $this->state(['status' => Application::STATUS_APPROVED]);
    }
}
