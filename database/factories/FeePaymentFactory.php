<?php

namespace Database\Factories;

use App\Models\FeePlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\FeePayment> */
class FeePaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'fee_plan_id' => FeePlan::factory(),
            'paid_on' => now()->toDateString(),
            'amount_minor' => '100000.00',
        ];
    }
}
