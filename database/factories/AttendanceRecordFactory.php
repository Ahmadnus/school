<?php

namespace Database\Factories;

use App\Enums\AttendanceSource;
use App\Enums\AttendanceStatus;
use App\Models\Section;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\AttendanceRecord> */
class AttendanceRecordFactory extends Factory
{
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'section_id' => Section::factory(),
            'date' => now()->toDateString(),
            'status' => AttendanceStatus::Present,
            'source' => AttendanceSource::Manual,
            'recorded_at' => now(),
        ];
    }
}
