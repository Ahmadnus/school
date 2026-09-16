<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Term> */
class TermFactory extends Factory
{
    public function definition(): array
    {
        return [
            'academic_year_id' => AcademicYear::factory(),
            'name' => 'الفصل الأول',
            'start_date' => now()->startOfYear()->addMonths(8)->toDateString(),
            'end_date' => now()->startOfYear()->addMonths(12)->toDateString(),
            'is_current' => false,
        ];
    }
}
