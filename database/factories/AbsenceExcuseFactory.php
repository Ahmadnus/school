<?php

namespace Database\Factories;

use App\Enums\ExcuseStatus;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\AbsenceExcuse> */
class AbsenceExcuseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
            'reason' => 'مرض',
            'status' => ExcuseStatus::Pending,
        ];
    }

    public function accepted(): static
    {
        return $this->state(fn () => ['status' => ExcuseStatus::Accepted, 'reviewed_at' => now()]);
    }
}
