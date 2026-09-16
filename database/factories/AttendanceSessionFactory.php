<?php

namespace Database\Factories;

use App\Enums\AttendanceSessionStatus;
use App\Models\Section;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\AttendanceSession> */
class AttendanceSessionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'section_id' => Section::factory(),
            'date' => now()->toDateString(),
            'status' => AttendanceSessionStatus::Draft,
        ];
    }
}
