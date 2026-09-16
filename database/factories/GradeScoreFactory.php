<?php

namespace Database\Factories;

use App\Models\Assessment;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\GradeScore> */
class GradeScoreFactory extends Factory
{
    public function definition(): array
    {
        return [
            'assessment_id' => Assessment::factory(),
            'student_id' => Student::factory(),
            'score' => $this->faker->numberBetween(0, 100),
            'entered_at' => now(),
        ];
    }
}
