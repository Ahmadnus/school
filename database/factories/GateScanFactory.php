<?php

namespace Database\Factories;

use App\Enums\GateScanResult;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\GateScan> */
class GateScanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'nfc_uid' => strtoupper($this->faker->bothify('??##??##')),
            'student_id' => null,
            'scanned_at' => now(),
            'result' => GateScanResult::UnknownCard,
        ];
    }
}
