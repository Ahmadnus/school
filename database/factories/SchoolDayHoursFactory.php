<?php

namespace Database\Factories;

use App\Enums\Weekday;
use App\Models\School;
use App\Models\SchoolDayHours;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SchoolDayHours> */
class SchoolDayHoursFactory extends Factory
{
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'day_of_week' => Weekday::Sunday,
            'starts_at' => '08:00',
            'ends_at' => '18:00',
        ];
    }

    public function on(Weekday $day, string $from, string $to): static
    {
        return $this->state(fn () => [
            'day_of_week' => $day,
            'starts_at' => $from,
            'ends_at' => $to,
        ]);
    }
}
