<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\DesktopTestNotification;
use App\Services\Desktop\DesktopTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DesktopNotificationSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private DesktopTokenService $tokens;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->user = User::factory()->create();
        $this->tokens = app(DesktopTokenService::class);
    }

    public function test_the_settings_page_reports_never_connected_before_a_token_exists(): void
    {
        $this->actingAs($this->user)
            ->get(route('settings.desktop-notifications.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Profile/DesktopNotifications')
                ->where('connection.connected', false)
                ->where('connection.awaiting_connection', false)
                ->where('connection.device_name', null)
                ->where('connection.last_used_at', null)
                ->where('plainTextToken', null));
    }

    public function test_generating_a_new_setup_code_retires_the_previous_unused_one(): void
    {
        $first = $this->tokens->issueSetupToken($this->user);
        $second = $this->tokens->issueSetupToken($this->user);

        $this->assertNull(PersonalAccessToken::findToken($first->plainTextToken));
        $this->assertNotNull(PersonalAccessToken::findToken($second->plainTextToken));
        $this->assertSame(1, $this->user->tokens()->where('name', 'desktop:setup')->count());
    }

    public function test_generating_a_setup_code_leaves_a_connected_device_alone(): void
    {
        $device = $this->tokens->issueDeviceToken($this->user, 'JANES-LAPTOP');

        $this->tokens->issueSetupToken($this->user);

        $this->assertNotNull(PersonalAccessToken::findToken($device->plainTextToken));
    }

    public function test_an_outstanding_setup_code_is_awaiting_connection_not_connected(): void
    {
        $this->tokens->issueSetupToken($this->user);

        // Testing here would broadcast to nobody: no device has handshaken yet.
        $this->actingAs($this->user)
            ->get(route('settings.desktop-notifications.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('connection.connected', false)
                ->where('connection.awaiting_connection', true)
                ->where('connection.device_name', null));
    }

    public function test_a_connected_device_outranks_a_setup_code_that_is_still_outstanding(): void
    {
        $this->tokens->issueSetupToken($this->user);
        $this->tokens->issueDeviceToken($this->user, 'JANES-LAPTOP');

        $this->actingAs($this->user)
            ->get(route('settings.desktop-notifications.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('connection.connected', true)
                ->where('connection.awaiting_connection', false)
                ->where('connection.device_name', 'JANES-LAPTOP'));
    }

    public function test_generating_a_token_shows_the_plain_text_exactly_once(): void
    {
        $this->actingAs($this->user)
            ->post(route('settings.desktop-notifications.store'))
            ->assertRedirect(route('settings.desktop-notifications.edit'));

        $token = session('desktopToken');

        $this->assertIsString($token);

        $stored = PersonalAccessToken::findToken($token);

        $this->assertSame('desktop:setup', $stored->name);
        $this->assertSame([DesktopTokenService::CONNECT_ABILITY], $stored->abilities);
        $this->assertLessThanOrEqual(15, $stored->expires_at->diffInMinutes(now()));

        // The page redirected to carries it into the modal...
        $this->actingAs($this->user)
            ->get(route('settings.desktop-notifications.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('plainTextToken', $token));

        // ...and nothing after that can, because only the hash was ever stored.
        $this->actingAs($this->user)
            ->get(route('settings.desktop-notifications.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('plainTextToken', null));
    }

    public function test_the_settings_page_surfaces_the_device_name_and_last_used_at(): void
    {
        $device = $this->tokens->issueDeviceToken($this->user, 'JANES-LAPTOP');
        $device->accessToken->forceFill(['last_used_at' => now()->subMinutes(5)])->save();

        $this->actingAs($this->user)
            ->get(route('settings.desktop-notifications.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('connection.connected', true)
                ->where('connection.device_name', 'JANES-LAPTOP')
                ->where('connection.last_used_at', now()->subMinutes(5)->toIso8601String()));
    }

    public function test_an_expired_setup_token_does_not_count_as_connected(): void
    {
        $this->tokens->issueSetupToken($this->user);

        $this->travel(16)->minutes();

        $this->actingAs($this->user)
            ->get(route('settings.desktop-notifications.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('connection.connected', false)
                ->where('connection.awaiting_connection', false));
    }

    public function test_revoking_removes_every_desktop_token_and_leaves_other_tokens_alone(): void
    {
        $this->tokens->issueSetupToken($this->user);
        $this->tokens->issueDeviceToken($this->user, 'JANES-LAPTOP');
        $this->tokens->issueDeviceToken($this->user, 'DESK-PC');
        $this->user->createToken('some-other-integration');

        $this->actingAs($this->user)
            ->delete(route('settings.desktop-notifications.destroy'))
            ->assertRedirect(route('settings.desktop-notifications.edit'));

        $this->assertSame(['some-other-integration'], $this->user->tokens()->pluck('name')->all());
    }

    public function test_revoking_does_not_touch_another_users_desktop_tokens(): void
    {
        $other = User::factory()->create();
        $this->tokens->issueDeviceToken($other, 'THEIR-LAPTOP');
        $this->tokens->issueDeviceToken($this->user, 'JANES-LAPTOP');

        $this->actingAs($this->user)->delete(route('settings.desktop-notifications.destroy'));

        $this->assertSame(1, $other->tokens()->count());
    }

    public function test_the_settings_test_button_broadcasts_a_real_notification(): void
    {
        Notification::fake();

        $this->actingAs($this->user)
            ->post(route('settings.desktop-notifications.test'))
            ->assertRedirect(route('settings.desktop-notifications.edit'));

        Notification::assertSentTo($this->user, DesktopTestNotification::class);
    }

    public function test_any_authenticated_role_can_connect_a_desktop(): void
    {
        foreach (['admin', 'woc', 'vendor', 'tenant'] as $roleName) {
            Role::findOrCreate($roleName, 'web');

            $user = User::factory()->create()->assignRole($roleName);

            $this->actingAs($user)->get(route('settings.desktop-notifications.edit'))->assertOk();

            $this->actingAs($user)
                ->post(route('settings.desktop-notifications.store'))
                ->assertRedirect(route('settings.desktop-notifications.edit'));

            $token = PersonalAccessToken::findToken(session('desktopToken'));

            $this->assertSame($user->id, $token->tokenable_id);
            $this->assertSame([DesktopTokenService::CONNECT_ABILITY], $token->abilities);
        }
    }

    public function test_the_settings_routes_require_authentication(): void
    {
        $this->get(route('settings.desktop-notifications.edit'))->assertRedirect(route('login'));
        $this->post(route('settings.desktop-notifications.store'))->assertRedirect(route('login'));
        $this->delete(route('settings.desktop-notifications.destroy'))->assertRedirect(route('login'));
        $this->post(route('settings.desktop-notifications.test'))->assertRedirect(route('login'));
    }
}
