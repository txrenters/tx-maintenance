<?php

namespace Tests\Feature;

use App\Exceptions\JobberReconnectRequiredException;
use App\Models\JobberToken;
use App\Services\JobberTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class JobberTokenServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeToken(array $overrides = []): JobberToken
    {
        return JobberToken::query()->create(array_merge([
            'access_token' => 'stored-token',
            'refresh_token' => 'stored-refresh',
            'expires_at' => now()->addHour(),
        ], $overrides));
    }

    public function test_a_fresh_stored_token_is_returned_without_any_http_call(): void
    {
        Http::fake();
        $this->makeToken();

        $this->assertSame('stored-token', app(JobberTokenService::class)->getAccessToken());
        Http::assertNothingSent();
    }

    public function test_refresh_is_skipped_when_the_stored_token_is_still_fresh(): void
    {
        Http::fake();
        $this->makeToken();

        // The re-read-after-lock guard: an expiry-driven refresh that finds the
        // token already fresh (another process rotated it) must not spend the
        // single-use refresh token again.
        $this->assertSame('stored-token', app(JobberTokenService::class)->refreshAccessToken());
        Http::assertNothingSent();
    }

    public function test_an_expiring_token_is_refreshed_and_the_rotated_pair_saved(): void
    {
        Http::fake([
            'api.getjobber.com/api/oauth/token' => Http::response([
                'access_token' => 'fresh-token',
                'refresh_token' => 'fresh-refresh',
                'expires_in' => 3600,
            ]),
        ]);
        $this->makeToken(['expires_at' => now()->addMinute()]);

        $this->assertSame('fresh-token', app(JobberTokenService::class)->getAccessToken());

        $token = JobberToken::query()->first();
        $this->assertSame('fresh-token', $token->access_token);
        $this->assertSame('fresh-refresh', $token->refresh_token);
    }

    public function test_get_access_token_fails_fast_when_the_reconnect_flag_is_set(): void
    {
        Http::fake();
        $this->makeToken();
        Cache::forever('jobber:needs-reconnect', now()->toIso8601String());

        try {
            app(JobberTokenService::class)->getAccessToken();
            $this->fail('Expected JobberReconnectRequiredException was not thrown.');
        } catch (JobberReconnectRequiredException) {
        }

        Http::assertNothingSent();
    }

    public function test_a_missing_token_row_marks_the_connection_disconnected(): void
    {
        Http::fake();

        try {
            app(JobberTokenService::class)->getAccessToken();
            $this->fail('Expected JobberReconnectRequiredException was not thrown.');
        } catch (JobberReconnectRequiredException) {
        }

        $this->assertTrue(Cache::has('jobber:needs-reconnect'));
        Http::assertNothingSent();
    }

    public function test_the_oauth_callback_rejects_a_mismatched_state(): void
    {
        Http::fake();

        $this->withSession(['jobber_oauth_state' => 'expected'])
            ->get('/jobber/callback?code=abc&state=wrong')
            ->assertRedirect('/inspections');

        $this->assertSame(0, JobberToken::query()->count());
        Http::assertNothingSent();
    }

    public function test_the_oauth_callback_stores_tokens_and_clears_the_reconnect_flag(): void
    {
        Cache::forever('jobber:needs-reconnect', now()->toIso8601String());
        Cache::forever('jobber:reconnect-alerted', true);

        Http::fake([
            'api.getjobber.com/api/oauth/token' => Http::response([
                'access_token' => 'cb-token',
                'refresh_token' => 'cb-refresh',
                'expires_in' => 3600,
            ]),
        ]);

        $this->withSession(['jobber_oauth_state' => 'expected'])
            ->get('/jobber/callback?code=abc&state=expected')
            ->assertRedirect('/inspections');

        $token = JobberToken::query()->first();
        $this->assertSame('cb-token', $token->access_token);
        $this->assertSame('cb-refresh', $token->refresh_token);
        $this->assertFalse(Cache::has('jobber:needs-reconnect'));
        $this->assertFalse(Cache::has('jobber:reconnect-alerted'));
    }
}
