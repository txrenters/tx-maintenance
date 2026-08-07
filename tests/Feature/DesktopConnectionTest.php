<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\DesktopTestNotification;
use App\Services\Desktop\DesktopTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class DesktopConnectionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private DesktopTokenService $tokens;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('desktop.websocket', [
            'key' => 'reverb-app-key',
            'host' => 'reverb.example.com',
            'port' => 443,
            'scheme' => 'https',
        ]);

        $this->user = User::factory()->create();
        $this->tokens = app(DesktopTokenService::class);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function connect(string $plainTextToken, array $payload = []): TestResponse
    {
        // Guards memoise the resolved user for the lifetime of the container,
        // which in tests spans several requests. Production gets a fresh
        // container per request, so forget them to authenticate each call on
        // its own merits.
        Auth::forgetGuards();

        return $this->postJson('/api/desktop/connect', array_merge([
            'device_name' => 'JANES-LAPTOP',
            'client' => 'TexasRenters Desktop',
            'client_version' => '1.0.0',
            'platform' => 'Windows',
        ], $payload), [
            'Authorization' => 'Bearer '.$plainTextToken,
        ]);
    }

    public function test_the_handshake_returns_the_exact_shape_the_desktop_client_parses(): void
    {
        $setup = $this->tokens->issueSetupToken($this->user);

        $response = $this->connect($setup->plainTextToken);

        $response->assertOk()
            ->assertJsonStructure([
                'application' => ['name', 'icon_url'],
                'user' => ['id', 'name', 'email'],
                'token',
                'channel',
                'websocket' => ['key', 'host', 'port', 'scheme', 'auth_endpoint'],
            ])
            ->assertJsonPath('user.id', $this->user->id)
            ->assertJsonPath('user.email', $this->user->email)
            ->assertJsonPath('channel', 'private-App.Models.User.'.$this->user->id)
            ->assertJsonPath('websocket.key', 'reverb-app-key')
            ->assertJsonPath('websocket.host', 'reverb.example.com')
            ->assertJsonPath('websocket.port', 443)
            ->assertJsonPath('websocket.scheme', 'https')
            ->assertJsonPath('websocket.auth_endpoint', url('/api/broadcasting/auth'));

        $this->assertStringStartsWith('private-', $response->json('channel'));
        $this->assertStringStartsWith('http', $response->json('application.icon_url'));
    }

    public function test_the_handshake_swaps_the_setup_code_for_a_long_lived_listening_token(): void
    {
        $setup = $this->tokens->issueSetupToken($this->user);

        $returned = $this->connect($setup->plainTextToken)->json('token');

        $device = PersonalAccessToken::findToken($returned);

        $this->assertNotNull($device);
        $this->assertSame('desktop:JANES-LAPTOP', $device->name);
        $this->assertSame([DesktopTokenService::LISTEN_ABILITY], $device->abilities);
        $this->assertNull($device->expires_at);
        $this->assertSame($this->user->id, $device->tokenable_id);
    }

    public function test_reconnecting_the_same_device_replaces_its_previous_token(): void
    {
        $first = $this->connect($this->tokens->issueSetupToken($this->user)->plainTextToken)->json('token');
        $second = $this->connect($this->tokens->issueSetupToken($this->user)->plainTextToken)->json('token');

        $this->assertNull(PersonalAccessToken::findToken($first));
        $this->assertNotNull(PersonalAccessToken::findToken($second));
        $this->assertSame(1, $this->user->tokens()->where('name', 'desktop:JANES-LAPTOP')->count());
    }

    public function test_a_setup_code_cannot_be_replayed_once_it_has_been_swapped(): void
    {
        $setup = $this->tokens->issueSetupToken($this->user);

        $this->connect($setup->plainTextToken)->assertOk();

        $this->connect($setup->plainTextToken)->assertUnauthorized();
    }

    public function test_an_expired_setup_code_is_rejected_outright(): void
    {
        $setup = $this->tokens->issueSetupToken($this->user);

        $this->travel(config('desktop.setup_token_ttl') + 1)->minutes();

        $this->connect($setup->plainTextToken)->assertUnauthorized();
    }

    public function test_a_garbage_token_is_rejected_outright(): void
    {
        $this->connect('999|not-a-real-token')->assertUnauthorized();
    }

    public function test_a_device_token_cannot_be_used_to_handshake_again(): void
    {
        $device = $this->tokens->issueDeviceToken($this->user, 'JANES-LAPTOP');

        $this->connect($device->plainTextToken)->assertForbidden();
    }

    public function test_the_handshake_is_rate_limited(): void
    {
        foreach (range(1, 10) as $attempt) {
            $this->connect($this->tokens->issueSetupToken($this->user)->plainTextToken)->assertOk();
        }

        $this->connect($this->tokens->issueSetupToken($this->user)->plainTextToken)->assertStatus(429);
    }

    public function test_the_test_notification_endpoint_broadcasts_to_the_caller(): void
    {
        Notification::fake();

        $device = $this->tokens->issueDeviceToken($this->user, 'JANES-LAPTOP');

        Auth::forgetGuards();

        $this->postJson('/api/desktop/test-notification', [], [
            'Authorization' => 'Bearer '.$device->plainTextToken,
        ])->assertOk();

        Notification::assertSentTo($this->user, DesktopTestNotification::class);
    }

    public function test_the_test_notification_endpoint_requires_authentication(): void
    {
        $this->postJson('/api/desktop/test-notification')->assertUnauthorized();
    }
}
