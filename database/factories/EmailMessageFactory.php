<?php

namespace Database\Factories;

use App\Models\EmailMessage;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailMessage>
 */
class EmailMessageFactory extends Factory
{
    protected $model = EmailMessage::class;

    public function definition(): array
    {
        return [
            'work_order_id' => WorkOrder::factory(),
            'vendor_id' => fn () => Vendor::query()->create([
                'propertyware_id' => $this->faker->unique()->uuid(),
                'name' => $this->faker->company(),
                'email' => $this->faker->unique()->safeEmail(),
                'is_active' => true,
                'user_id' => User::factory()->create()->id,
            ])->id,
            'direction' => 'outbound',
            'subject' => 'New Service Request [TX-1000-1]',
            'body_html' => '<p>Hello</p>',
            'body_text' => 'Hello',
            'from_email' => 'workorders@texasrenters.com',
            'to_email' => $this->faker->unique()->safeEmail(),
            'cc' => ['mc@texasrenters.com', 'ofm@txhomemp.com'],
            'correlation_tag' => 'TX-1000-1',
            'graph_message_id' => $this->faker->uuid(),
            'graph_conversation_id' => $this->faker->uuid(),
            'internet_message_id' => '<'.$this->faker->uuid().'@texasrenters.com>',
            'in_reply_to' => null,
            'has_attachments' => false,
            'sent_by_user_id' => null,
            'emailed_at' => now(),
        ];
    }

    public function inbound(): static
    {
        return $this->state(fn () => ['direction' => 'inbound']);
    }
}
