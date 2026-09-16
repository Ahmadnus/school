<?php

namespace Database\Factories;

use App\Enums\GuardianRelation;
use App\Models\Guardian;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\StudentGuardian> */
class StudentGuardianFactory extends Factory
{
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'guardian_id' => Guardian::factory(),
            'relation' => GuardianRelation::Father,
            'is_primary' => false,
        ];
    }
}
