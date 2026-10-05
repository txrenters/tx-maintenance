<?php

namespace Tests\Feature;

use App\Services\TwilioService;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * config/services.php carries eleven per-feature SMS flags, most of them on by default,
 * texting tenants and owners. Those choose WHICH texts a working deployment sends. This
 * guard decides whether it may text anyone at all -- one switch, above all of them, so a
 * deployment holding a copy of production data cannot text a real tenant because someone
 * missed one flag out of eleven.
 */
class OutboundSmsGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The constructor builds a real Twilio\Rest\Client, which refuses empty credentials.
        config([
            'services.twilio.sid' => 'AC'.str_repeat('0', 32),
            'services.twilio.auth_token' => 'token',
        ]);
    }

    private function guard(bool $enabled, string $allowlist = ''): TwilioService
    {
        config([
            'services.twilio.outbound_enabled' => $enabled,
            'services.twilio.allowlist' => $allowlist,
        ]);

        return new TwilioService;
    }

    public function test_nothing_may_be_sent_when_outbound_sms_is_switched_off(): void
    {
        $this->assertFalse($this->guard(false)->shouldSend('+15551234567'));
    }

    public function test_everything_may_be_sent_when_switched_on_with_no_allowlist(): void
    {
        $this->assertTrue($this->guard(true)->shouldSend('+15551234567'));
    }

    public function test_an_allowlist_limits_sending_to_the_numbers_on_it(): void
    {
        $service = $this->guard(true, '+15559998888');

        $this->assertTrue($service->shouldSend('+15559998888'));
        $this->assertFalse($service->shouldSend('+15551234567'));
    }

    public function test_the_allowlist_ignores_formatting_differences(): void
    {
        $service = $this->guard(true, '(555) 999-8888, 555-111-2222');

        $this->assertTrue($service->shouldSend('+15559998888'));
        $this->assertTrue($service->shouldSend('5551112222'));
        $this->assertFalse($service->shouldSend('+15553334444'));
    }

    public function test_the_master_switch_beats_the_allowlist(): void
    {
        $this->assertFalse($this->guard(false, '+15559998888')->shouldSend('+15559998888'));
    }

    public function test_the_guard_beats_the_per_feature_flags(): void
    {
        // Every feature flag on, master switch off: still nothing sends.
        config([
            'services.twilio.tenant_intake_sms' => true,
            'services.twilio.owner_assignment_sms' => true,
            'services.twilio.hoa_violation_sms' => true,
        ]);

        $this->assertNull($this->guard(false)->sendMessage('+15551234567', '+15550000000', 'hello'));
    }

    public function test_a_suppressed_message_is_logged_loudly_enough_to_find(): void
    {
        Log::spy();

        $this->guard(false)->sendMessage('+15551234567', '+15550000000', 'must not reach a real person');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'suppressed')
                && $context['to'] === '+15551234567')
            ->once();
    }
}
