<?php

namespace Database\Factories;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\PostType;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Post> */
class PostFactory extends Factory
{
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'post_type_id' => PostType::factory(),
            'author_id' => User::factory(),
            'title' => $this->faker->sentence(),
            'body' => $this->faker->paragraph(),
            'status' => PostStatus::Draft,
        ];
    }
}
