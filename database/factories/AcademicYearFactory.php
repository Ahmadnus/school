<?php

namespace Database\Factories;

use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\AcademicYear> */
class AcademicYearFactory extends Factory
{
    public function definition(): array
    {
        $start = now()->startOfYear()->addMonths(8);

        return [
            'school_id' => School::factory(),
            'name' => $start->year.'-'.($start->year + 1),
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addMonths(9)->toDateString(),
            'is_current' => false,
        ];
    }

    public function current(): static
    {
        return $this->state(fn () => ['is_current' => true]);
    }
}
