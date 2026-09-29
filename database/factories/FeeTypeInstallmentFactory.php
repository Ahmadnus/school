<?php

namespace Database\Factories;

use App\Models\FeeType;
use App\Models\FeeTypeInstallment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FeeTypeInstallment> */
class FeeTypeInstallmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'fee_type_id' => FeeType::factory(),
            'sort_order' => 1,
            'due_date' => now()->toDateString(),
            'amount_minor' => '500000.00',
        ];
    }
}
