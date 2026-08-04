<?php

namespace App\Services;

use App\Exceptions\JobberReconnectRequiredException;
use App\Models\JobberToken;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The single owner of the Jobber OAuth token lifecycle.
 *
 * Jobber rotates refresh tokens: every refresh invalidates the previous one, so
 * two concurrent refreshes permanently kill the stored token (the exact outage
 * that flooded the logs with "Refresh token is invalid"). All reads and
 * refreshes must go through this service — it serializes refreshes behind a
 * cache lock and re-reads the row after acquiring it so a token another process
 * just rotated is reused instead of burned.
 *
 * When Jobber rejects the refresh token outright, the service flags the
 * connection dead in cache (no migration needed), alerts staff once through the
 * notification bell, and fails fast on every call until an admin re-authorizes
 * at /jobber-connect (which clears the flag via markConnected()).
 */
class JobberTokenService
{
    private const REFRESH_LOCK_KEY = 'jobber:token-refresh-lock';

    private const NEEDS_RECONNECT_KEY = 'jobber:needs-reconnect';

    private const RECONNECT_ALERTED_KEY = 'jobber:reconnect-alerted';

    private const OAUTH_TOKEN_URL = 'https://api.getjobber.com/api/oauth/token';

    /**
     * A valid access token, refreshed first when it expires within 5 minutes.
     *
     * @throws JobberReconnectRequiredException
     */
    public function getAccessToken(): string
    {
        if ($this->needsReconnect()) {
            throw new JobberReconnectRequiredException;
        }

        $token = JobberToken::query()->first();

        if (! $token || blank($token->access_token)) {
            $this->markDisconnected();

            throw new JobberReconnectRequiredException('No Jobber token found. Please connect to Jobber at /jobber-connect.');
        }

        if (! $token->expires_at || now()->addMinutes(5)->isAfter($token->expires_at)) {
            return $this->refreshAccessToken();
        }

        return $token->access_token;
    }

    /**
     * Request headers for a Jobber GraphQL call using a valid access token.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->getAccessToken(),
            'X-JOBBER-GRAPHQL-VERSION' => (string) config('services.jobber.api_version'),
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * POST a GraphQL body to Jobber. On a 401 the token is force-refreshed once
     * and the request replayed, so a token revoked mid-lifetime self-heals
     * instead of failing the caller. Network errors retry; HTTP error statuses
     * are returned to the caller to interpret.
     *
     * @param  array<string, mixed>  $body
     *
     * @throws JobberReconnectRequiredException
     */
    public function graphql(array $body): Response
    {
        $accessToken = $this->getAccessToken();
        $response = $this->postGraphql($body, $accessToken);

        if ($response->status() === 401) {
            Log::warning('Jobber GraphQL returned 401 - refreshing token and replaying once.');

            $accessToken = $this->refreshAccessToken($accessToken);
            $response = $this->postGraphql($body, $accessToken);
        }

        return $response;
    }

    /**
     * Refresh the access token behind a mutex.
     *
     * $staleAccessToken is the token a caller just got a 401 with; if the stored
     * token already differs, another process rotated it while we waited for the
     * lock and we return that instead of spending the single-use refresh token
     * again. Without it (expiry-driven refresh) the same guard uses expires_at.
     *
     * @throws JobberReconnectRequiredException when Jobber rejects the refresh token
     */
    public function refreshAccessToken(?string $staleAccessToken = null): string
    {
        try {
            return Cache::lock(self::REFRESH_LOCK_KEY, 30)->block(15, function () use ($staleAccessToken): string {
                return $this->refreshWhileHoldingLock($staleAccessToken);
            });
        } catch (LockTimeoutException) {
            // Whoever held the lock for 15s+ was refreshing; use their result if fresh.
            $token = JobberToken::query()->first();

            if ($token && filled($token->access_token) && $token->expires_at && now()->addMinutes(5)->isBefore($token->expires_at)) {
                return $token->access_token;
            }

            throw new \RuntimeException('Timed out waiting for the Jobber token refresh lock.');
        }
    }

    private function refreshWhileHoldingLock(?string $staleAccessToken): string
    {
        $token = JobberToken::query()->first();

        if (! $token || blank($token->refresh_token)) {
            $this->markDisconnected();

            throw new JobberReconnectRequiredException('No Jobber refresh token found. Please connect to Jobber at /jobber-connect.');
        }

        // Another process may have already refreshed while we waited on the lock.
        if ($staleAccessToken !== null && $token->access_token !== $staleAccessToken) {
            return $token->access_token;
        }

        if ($staleAccessToken === null && $token->expires_at && now()->addMinutes(5)->isBefore($token->expires_at)) {
            return $token->access_token;
        }

        $response = Http::asForm()->post(self::OAUTH_TOKEN_URL, [
            'client_id' => config('services.jobber.client_id'),
            'client_secret' => config('services.jobber.client_secret'),
            'grant_type' => 'refresh_token',
            'refresh_token' => $token->refresh_token,
        ]);

        if ($response->ok()) {
            $data = $response->json();

            $token->access_token = $data['access_token'];

            if (isset($data['refresh_token'])) {
                $token->refresh_token = $data['refresh_token'];
            }

            $token->expires_at = isset($data['expires_in'])
                ? now()->addSeconds((int) $data['expires_in'])
                : now()->addHour();

            $token->save();

            Log::info('Jobber access and refresh tokens updated.');

            return $token->access_token;
        }

        Log::error('Failed to refresh Jobber token', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        if ($response->status() === 401) {
            $this->markDisconnected();

            throw new JobberReconnectRequiredException;
        }

        throw new \RuntimeException('Unable to refresh Jobber access token: '.$response->body());
    }

    /**
     * Store the token pair returned by the OAuth authorization-code exchange
     * and clear the disconnected state.
     *
     * @param  array<string, mixed>  $data
     */
    public function storeTokensFromOAuth(array $data): void
    {
        JobberToken::updateOrCreate([], [
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'],
            'expires_at' => isset($data['expires_in'])
                ? now()->addSeconds((int) $data['expires_in'])
                : now()->addHour(),
        ]);

        $this->markConnected();
    }

    /**
     * Flag the connection dead and alert staff once (notification bell). The
     * flag makes every subsequent call fail fast instead of re-burning the
     * dead refresh token and flooding the logs.
     */
    public function markDisconnected(): void
    {
        Cache::forever(self::NEEDS_RECONNECT_KEY, now()->toIso8601String());

        // Cache::add is atomic: only the first caller per incident alerts.
        if (! Cache::add(self::RECONNECT_ALERTED_KEY, true, now()->addDays(7))) {
            return;
        }

        try {
            activity()
                ->event('jobber_reconnect_required')
                ->withProperties([
                    'message' => 'The Jobber connection was lost. THMP job sync and Jobber webhooks are paused until an admin reconnects on the IT Tools page.',
                    'read' => false,
                ])
                ->log('Jobber disconnected - reconnect required');
        } catch (\Throwable $exception) {
            Log::error('Failed to raise the Jobber reconnect alert.', ['error' => $exception->getMessage()]);
        }
    }

    public function markConnected(): void
    {
        Cache::forget(self::NEEDS_RECONNECT_KEY);
        Cache::forget(self::RECONNECT_ALERTED_KEY);
    }

    public function needsReconnect(): bool
    {
        return Cache::has(self::NEEDS_RECONNECT_KEY);
    }

    /**
     * Connection state for the IT Tools page.
     *
     * @return array{connected: bool, needs_reconnect: bool, disconnected_since: string|null, expires_at: string|null, updated_at: string|null, has_refresh_token: bool}
     */
    public function status(): array
    {
        $token = JobberToken::query()->first();

        return [
            'connected' => $token !== null && filled($token->access_token) && ! $this->needsReconnect(),
            'needs_reconnect' => $this->needsReconnect(),
            'disconnected_since' => Cache::get(self::NEEDS_RECONNECT_KEY),
            'expires_at' => $token?->expires_at,
            'updated_at' => $token?->updated_at?->toDateTimeString(),
            'has_refresh_token' => filled($token?->refresh_token),
        ];
    }

    private function postGraphql(array $body, string $accessToken): Response
    {
        return Http::withHeaders([
            'Authorization' => 'Bearer '.$accessToken,
            'X-JOBBER-GRAPHQL-VERSION' => (string) config('services.jobber.api_version'),
            'Content-Type' => 'application/json',
        ])
            ->timeout(60)
            ->retry(3, 2000, fn (\Throwable $e): bool => $e instanceof ConnectionException, false)
            ->post((string) config('services.jobber.graphql_url'), $body);
    }
}
