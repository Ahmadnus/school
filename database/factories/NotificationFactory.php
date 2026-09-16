<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Notification> */
class NotificationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => 'post_published',
            'title' => $this->faker->sentence(),
            'body' => $this->faker->sentence(),
            'is_read' => false,
        ];
    }
}
