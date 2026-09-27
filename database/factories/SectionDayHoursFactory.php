<?php

namespace Database\Factories;

use App\Enums\Weekday;
use App\Models\Section;
use App\Models\SectionDayHours;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SectionDayHours> */
class SectionDayHoursFactory extends Factory
{
    public function definition(): array
    {
        return [
            'section_id' => Section::factory(),
            'day_of_week' => Weekday::Sunday,
            'starts_at' => '08:00',
            'ends_at' => '14:00',
            'period_minutes' => 60,
            'break_minutes' => 0,
        ];
    }

    public function on(Weekday $day, string $from, string $to, int $minutes = 60): static
    {
        return $this->state(fn () => [
            'day_of_week' => $day,
            'starts_at' => $from,
            'ends_at' => $to,
            'period_minutes' => $minutes,
        ]);
    }
}
