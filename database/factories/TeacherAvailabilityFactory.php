<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Enums\Weekday;
use App\Models\TeacherAvailability;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TeacherAvailability> */
class TeacherAvailabilityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'staff_id' => User::factory()->role(UserRole::Teacher),
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
