<?php

namespace Database\Factories;

use App\Enums\ConversationStatus;
use App\Enums\ConversationType;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Conversation> */
class ConversationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'type' => ConversationType::Staff,
            'status' => ConversationStatus::Open,
            'last_message_at' => now(),
        ];
    }
}
