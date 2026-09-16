<?php

namespace Database\Factories;

use App\Enums\Status;
use App\Enums\UserRole;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** @extends Factory<\App\Models\User> */
class UserFactory extends Factory
{
    protected static ?string $password = null;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'phone' => '09'.$this->faker->unique()->numerify('########'),
            'email' => $this->faker->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => UserRole::Teacher,
            'specialty' => null,
            'status' => Status::Active,
            'remember_token' => Str::random(10),
        ];
    }

    public function role(UserRole $role): static
    {
        return $this->state(fn () => ['role' => $role]);
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }
}
