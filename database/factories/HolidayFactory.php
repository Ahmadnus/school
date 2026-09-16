<?php

namespace Database\Factories;

use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Holiday> */
class HolidayFactory extends Factory
{
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'name' => $this->faker->word(),
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
        ];
    }
}
