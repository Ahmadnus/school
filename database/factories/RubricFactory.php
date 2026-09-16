<?php

namespace Database\Factories;

use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Rubric> */
class RubricFactory extends Factory
{
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'name' => $this->faker->unique()->word(),
            'is_default' => false,
        ];
    }
}
