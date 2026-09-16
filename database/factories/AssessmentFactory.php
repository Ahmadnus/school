<?php

namespace Database\Factories;

use App\Models\AssessmentType;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Assessment> */
class AssessmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'subject_id' => Subject::factory(),
            'assessment_type_id' => AssessmentType::factory(),
            'name' => $this->faker->unique()->word(),
            'max_score' => 100,
            'weight_percent' => 20,
        ];
    }
}
