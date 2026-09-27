<?php

namespace Database\Factories;

use App\Enums\TimetableRunStatus;
use App\Models\School;
use App\Models\Term;
use App\Models\TimetableRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TimetableRun> */
class TimetableRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'term_id' => Term::factory(),
            'status' => TimetableRunStatus::Generated,
            'requested_by' => null,
            'lesson_minutes' => 60,
            'stats' => [],
            'conflicts' => [],
            'lessons_placed' => 0,
            'lessons_required' => 0,
        ];
    }
}
