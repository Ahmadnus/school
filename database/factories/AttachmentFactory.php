<?php

namespace Database\Factories;

use App\Enums\AttachmentOwner;
use App\Models\Attachment;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Attachment> */
class AttachmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'owner_type' => AttachmentOwner::Post,
            'owner_id' => 1,
            'path' => 'attachments/post/example.jpg',
            'name' => 'example.jpg',
            'mime' => 'image/jpeg',
            'size' => 1024,
        ];
    }
}
