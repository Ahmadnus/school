<?php

namespace Database\Factories;

use App\Enums\Weekday;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Term;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\ScheduleSlot> */
class ScheduleSlotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'section_id' => Section::factory(),
            'term_id' => Term::factory(),
            'subject_id' => Subject::factory(),
            'staff_id' => null,
            'day_of_week' => Weekday::Sunday,
            'starts_at' => '08:00',
            'ends_at' => '08:45',
            'period_number' => 1,
            'room' => null,
        ];
    }
}
