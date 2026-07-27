<?php

namespace Database\Factories;

use App\Models\OwnerEmailAttachment;
use App\Models\OwnerEmailNotification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OwnerEmailAttachment>
 */
class OwnerEmailAttachmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_email_notification_id' => OwnerEmailNotification::factory(),
            'filename' => fake()->word().'.txt',
            'mime' => 'text/plain',
            'size' => 10,
            'path' => 'owner-email-attachments/'.fake()->uuid().'.txt',
        ];
    }
}
