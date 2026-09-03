<?php

namespace Database\Factories;

use App\Models\EquipmentUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EquipmentUnit> */
class EquipmentUnitFactory extends Factory
{
    protected $model = EquipmentUnit::class;

    public function definition(): array
    {
        return [
            'model' => fake()->words(2, true),
            'serial_number' => fake()->unique()->bothify('TEST-####??'),
            'status' => EquipmentUnit::STATUS_LEASED,
        ];
    }
}
