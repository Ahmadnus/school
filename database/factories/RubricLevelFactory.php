<?php

namespace Database\Factories;

use App\Models\Rubric;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\RubricLevel> */
class RubricLevelFactory extends Factory
{
    public function definition(): array
    {
        return [
            'rubric_id' => Rubric::factory(),
            'name' => $this->faker->word(),
            'value' => $this->faker->numberBetween(1, 5),
            'sort_order' => 0,
        ];
    }
}
