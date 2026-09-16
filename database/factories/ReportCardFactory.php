<?php

namespace Database\Factories;

use App\Enums\ReportCardStatus;
use App\Models\AcademicYear;
use App\Models\Student;
use App\Models\Term;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\ReportCard> */
class ReportCardFactory extends Factory
{
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'term_id' => Term::factory(),
            'academic_year_id' => AcademicYear::factory(),
            'status' => ReportCardStatus::Draft,
        ];
    }
}
