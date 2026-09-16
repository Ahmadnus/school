<?php

namespace Database\Factories;

use App\Enums\GradingMethod;
use App\Models\Grade;
use App\Models\Term;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Subject> */
class SubjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'grade_id' => Grade::factory(),
            'term_id' => Term::factory(),
            'name' => $this->faker->unique()->word(),
            'grading_method' => GradingMethod::Numeric,
            'max_score' => 100,
            'pass_score' => 50,
            'periods_per_week' => 3,
        ];
    }
}
