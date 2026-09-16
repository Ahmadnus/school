<?php

namespace Database\Factories;

use App\Enums\NotificationApp;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\SchoolNotificationSetting> */
class SchoolNotificationSettingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'app' => NotificationApp::Staff,
            'key' => 'post_published',
            'group' => 'posts',
            'is_enabled' => true,
        ];
    }
}
