<?php

namespace Database\Factories;

use App\Models\FeePlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\FeePlanInstallment> */
class FeePlanInstallmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'fee_plan_id' => FeePlan::factory(),
            'sort_order' => 1,
            'due_date' => now()->toDateString(),
            'amount_minor' => '500000.00',
        ];
    }
}
