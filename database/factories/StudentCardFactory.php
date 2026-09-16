<?php

namespace Database\Factories;

use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\StudentCard> */
class StudentCardFactory extends Factory
{
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'nfc_uid' => strtoupper($this->faker->unique()->bothify('??##??##')),
            'issued_at' => now(),
            'is_active' => true,
        ];
    }
}
