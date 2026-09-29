<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Section;
use App\Models\Subject;
use App\Models\TeacherAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TeacherAssignment> */
class TeacherAssignmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'staff_id' => User::factory()->role(UserRole::Teacher),
            'subject_id' => Subject::factory(),
            'section_id' => Section::factory(),
        ];
    }
}
