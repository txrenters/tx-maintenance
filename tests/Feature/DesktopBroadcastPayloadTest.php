<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\DesktopTestNotification;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class DesktopBroadcastPayloadTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Run a notification through the real broadcast channel and hand back the
     * payload and channels the client would actually see.
     *
     * @return array{payload: array<string, mixed>, channels: array<int, string>}
     */
    private function broadcastOf(User $notifiable, BaseNotification $notification): array
    {
        $captured = null;

        Event::listen(BroadcastNotificationCreated::class, function ($event) use (&$captured) {
            $captured = $event;
        });

        $notifiable->notify($notification);

        $this->assertNotNull($captured, 'The notification never reached the broadcast channel.');

        return [
            'payload' => $captured->broadcastWith(),
            'channels' => collect($captured->broadcastOn())
                ->map(fn (PrivateChannel $channel) => (string) $channel)
                ->all(),
        ];
    }

    public function test_a_broadcast_notification_carries_every_key_the_desktop_client_reads(): void
    {
        $user = User::factory()->create();

        $payload = $this->broadcastOf($user, new DesktopTestNotification)['payload'];

        foreach (['id', 'title', 'message', 'url', 'priority', 'icon', 'created_at'] as $key) {
            $this->assertArrayHasKey($key, $payload);
        }

        $this->assertNotEmpty($payload['id']);
        $this->assertNotEmpty($payload['title']);
        $this->assertNotEmpty($payload['message']);
        $this->assertContains($payload['priority'], ['low', 'normal', 'high', 'urgent']);
        $this->assertStringStartsWith('http', $payload['url']);
        $this->assertStringStartsWith('http', $payload['icon']);

        // Absolute ISO-8601, so the client does not have to guess a timezone.
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $payload['created_at'],
        );
    }

    public function test_the_test_notification_only_ever_goes_to_its_own_recipients_private_channel(): void
    {
        $user = User::factory()->create();
        User::factory()->create();

        $result = $this->broadcastOf($user, new DesktopTestNotification);

        $this->assertSame(['private-App.Models.User.'.$user->id], $result['channels']);
        $this->assertSame('Desktop Connection Successful', $result['payload']['title']);
    }
}
