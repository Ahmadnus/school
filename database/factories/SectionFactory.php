<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\Section;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Section> */
class SectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'grade_id' => Grade::factory(),
            'academic_year_id' => AcademicYear::factory(),
            'name' => $this->faker->unique()->word(),
            'capacity' => 30,
            'sort_order' => 0,
        ];
    }
}
