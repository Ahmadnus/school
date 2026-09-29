<?php

namespace Database\Factories;

use App\Models\Rubric;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Rubric> */
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
