<?php

namespace Database\Factories;

use App\Enums\Gender;
use App\Enums\Status;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Student> */
class StudentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'birth_date' => $this->faker->dateTimeBetween('-18 years', '-6 years')->format('Y-m-d'),
            'gender' => $this->faker->randomElement(Gender::cases()),
            'nationality' => 'سوري',
            'status' => Status::Active,
        ];
    }
}
