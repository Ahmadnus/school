<?php

namespace Database\Factories;

use App\Enums\ImportStatus;
use App\Models\AcademicYear;
use App\Models\School;
use App\Models\Section;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\StudentImport> */
class StudentImportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'academic_year_id' => AcademicYear::factory(),
            'section_id' => Section::factory(),
            'path' => 'imports/students/example.csv',
            'original_name' => 'example.csv',
            'status' => ImportStatus::Uploaded,
        ];
    }
}
