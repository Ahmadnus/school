<?php

namespace Database\Factories;

use App\Enums\Status;
use App\Models\FeeType;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FeeType> */
class FeeTypeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'name' => $this->faker->unique()->word(),
            'total_minor' => '1000000.00',
            'is_transport' => false,
            'is_default' => false,
            'status' => Status::Active,
        ];
    }
}
