<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Desktop\DesktopTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DesktopBroadcastAuthTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private DesktopTokenService $tokens;

    protected function setUp(): void
    {
        parent::setUp();

        // The suite broadcasts to the log driver, which authorises everything.
        // Swap in a Pusher-protocol driver so the real "key:signature" response
        // the desktop client parses is exercised — then drop the already
        // resolved broadcaster and re-register the channel callbacks, which
        // live on whichever driver was current when they ran.
        config()->set('broadcasting.default', 'reverb');
        config()->set('broadcasting.connections.reverb', [
            'driver' => 'reverb',
            'key' => 'reverb-app-key',
            'secret' => 'reverb-app-secret',
            'app_id' => 'reverb-app-id',
            'options' => ['host' => 'reverb.example.com', 'port' => 443, 'scheme' => 'https'],
        ]);

        Broadcast::forgetDrivers();

        require base_path('routes/channels.php');

        $this->user = User::factory()->create();
        $this->tokens = app(DesktopTokenService::class);
    }

    private function authorizeChannel(string $plainTextToken, string $channel, string $socketId = '1234.5678'): TestResponse
    {
        Auth::forgetGuards();

        return $this->postJson('/api/broadcasting/auth', [
            'socket_id' => $socketId,
            'channel_name' => $channel,
        ], [
            'Authorization' => 'Bearer '.$plainTextToken,
        ]);
    }

    public function test_a_device_token_can_authorise_its_own_private_notification_channel(): void
    {
        $device = $this->tokens->issueDeviceToken($this->user, 'JANES-LAPTOP');
        $channel = 'private-App.Models.User.'.$this->user->id;

        $response = $this->authorizeChannel($device->plainTextToken, $channel);

        $response->assertOk()->assertJsonStructure(['auth']);

        $this->assertStringStartsWith('reverb-app-key:', $response->json('auth'));
    }

    public function test_the_signature_is_bound_to_the_socket_id_that_asked_for_it(): void
    {
        $device = $this->tokens->issueDeviceToken($this->user, 'JANES-LAPTOP');
        $channel = 'private-App.Models.User.'.$this->user->id;

        $first = $this->authorizeChannel($device->plainTextToken, $channel, '1234.5678')->json('auth');
        $second = $this->authorizeChannel($device->plainTextToken, $channel, '8765.4321')->json('auth');

        $this->assertNotSame($first, $second);
    }

    public function test_a_device_token_cannot_authorise_another_users_channel(): void
    {
        $other = User::factory()->create();
        $device = $this->tokens->issueDeviceToken($this->user, 'JANES-LAPTOP');

        $this->authorizeChannel($device->plainTextToken, 'private-App.Models.User.'.$other->id)->assertForbidden();
    }

    public function test_a_setup_code_cannot_subscribe_only_handshake(): void
    {
        $setup = $this->tokens->issueSetupToken($this->user);

        $this->authorizeChannel($setup->plainTextToken, 'private-App.Models.User.'.$this->user->id)->assertForbidden();
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $this->authorizeChannel('999|not-a-real-token', 'private-App.Models.User.'.$this->user->id)->assertUnauthorized();
    }

    public function test_the_browser_session_route_still_works_and_is_untouched(): void
    {
        $payload = [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-App.Models.User.'.$this->user->id,
        ];

        $this->post('/broadcasting/auth', $payload)->assertForbidden();

        Auth::forgetGuards();

        $this->actingAs($this->user)
            ->post('/broadcasting/auth', $payload)
            ->assertOk()
            ->assertJsonStructure(['auth']);
    }
}
