<?php

namespace Database\Factories;

use App\Enums\ImportRowStatus;
use App\Models\StudentImport;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\StudentImportRow> */
class StudentImportRowFactory extends Factory
{
    public function definition(): array
    {
        return [
            'student_import_id' => StudentImport::factory(),
            'row_number' => 2,
            'raw' => ['أحمد', 'عمر'],
            'status' => ImportRowStatus::Pending,
        ];
    }
}
