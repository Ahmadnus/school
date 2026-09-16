<?php

namespace Database\Factories;

use App\Enums\PostGroup;
use App\Enums\UserRole;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\PostType> */
class PostTypeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'key' => 'general',
            'group' => PostGroup::Academic,
            'sort_order' => 1,
            'is_enabled' => true,
            'min_role' => UserRole::Teacher,
            'requires_approval' => false,
        ];
    }
}
