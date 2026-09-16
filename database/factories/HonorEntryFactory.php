<?php

namespace Database\Factories;

use App\Enums\HonorCategory;
use App\Models\HonorEntry;
use App\Models\School;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HonorEntry> */
class HonorEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'student_id' => Student::factory(),
            'category' => HonorCategory::Academic,
            'reason' => 'تفوّق في الاختبار الشهري',
            'published_at' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => ['published_at' => now()]);
    }
}
