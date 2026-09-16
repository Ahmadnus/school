<?php

namespace Database\Factories;

use App\Enums\FeePlanStatus;
use App\Models\AcademicYear;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\FeePlan> */
class FeePlanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'academic_year_id' => AcademicYear::factory(),
            'total_minor' => '1000000.00',
            'discount_minor' => 0,
            'status' => FeePlanStatus::Active,
        ];
    }
}
