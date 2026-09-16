<?php

namespace Database\Factories;

use App\Enums\Status;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Guardian> */
class GuardianFactory extends Factory
{
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'name' => $this->faker->name(),
            'phone' => '09'.$this->faker->unique()->numerify('########'),
            'status' => Status::Active,
        ];
    }
}
