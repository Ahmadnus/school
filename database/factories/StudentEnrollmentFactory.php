<?php

namespace Database\Factories;

use App\Enums\EnrollmentScope;
use App\Enums\EnrollmentStatus;
use App\Models\Section;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\StudentEnrollment> */
class StudentEnrollmentFactory extends Factory
{
    public function definition(): array
    {
        $section = Section::factory();

        return [
            'student_id' => Student::factory(),
            'section_id' => $section,
            'academic_year_id' => fn (array $attrs) => Section::find($attrs['section_id'])->academic_year_id,
            'scope' => EnrollmentScope::FullYear,
            'enrolled_at' => now()->toDateString(),
            'transport_subscribed' => false,
            'status' => EnrollmentStatus::Active,
        ];
    }
}
