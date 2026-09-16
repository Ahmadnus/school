<?php

namespace Database\Factories;

use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Grade> */
class GradeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'name' => $this->faker->unique()->word(),
            'sort_order' => 0,
        ];
    }
}
