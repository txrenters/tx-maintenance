<?php

namespace App\Services\Desktop;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Issues and revokes the Sanctum tokens the TexasRenters Desktop client uses.
 *
 * Two kinds of token live under the "desktop:" name prefix:
 *
 *  - "desktop:setup"  – the short-lived connection code a user copies out of
 *                       Settings, carrying only the "desktop:connect" ability.
 *  - "desktop:{device}" – the long-lived device token the handshake swaps it
 *                       for, carrying only the "desktop:listen" ability.
 */
class DesktopTokenService
{
    public const NAME_PREFIX = 'desktop:';

    public const SETUP_TOKEN_NAME = 'desktop:setup';

    public const CONNECT_ABILITY = 'desktop:connect';

    public const LISTEN_ABILITY = 'desktop:listen';

    /**
     * Create the short-lived connection code shown once in Settings.
     *
     * Any earlier code is retired first. Its plain text is unrecoverable once
     * the modal closes, so leaving it live buys nothing and keeps a usable
     * credential in play for the rest of its window.
     */
    public function issueSetupToken(User $user): NewAccessToken
    {
        $user->tokens()->where('name', self::SETUP_TOKEN_NAME)->delete();

        $token = $user->createToken(
            self::SETUP_TOKEN_NAME,
            [self::CONNECT_ABILITY],
            now()->addMinutes(config('desktop.setup_token_ttl')),
        );

        Log::info('Desktop connection token created', [
            'user_id' => $user->id,
            'token_id' => $token->accessToken->id,
            'token_name' => self::SETUP_TOKEN_NAME,
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'ip' => request()->ip(),
        ]);

        return $token;
    }

    /**
     * Swap a setup code for the device's long-lived listening token.
     *
     * Any previous token for the same device is deleted first so reconnecting
     * a laptop never leaves an orphaned token behind.
     */
    public function issueDeviceToken(User $user, string $deviceName): NewAccessToken
    {
        $tokenName = self::NAME_PREFIX.$deviceName;

        $user->tokens()->where('name', $tokenName)->delete();

        $token = $user->createToken($tokenName, [self::LISTEN_ABILITY]);

        Log::info('Desktop device token issued', [
            'user_id' => $user->id,
            'token_id' => $token->accessToken->id,
            'token_name' => $tokenName,
            'ip' => request()->ip(),
        ]);

        return $token;
    }

    /**
     * Disconnect every desktop client belonging to this user.
     */
    public function revokeAll(User $user): int
    {
        $deleted = $user->tokens()
            ->where('name', 'like', self::NAME_PREFIX.'%')
            ->delete();

        Log::info('Desktop connection tokens revoked', [
            'user_id' => $user->id,
            'tokens_deleted' => $deleted,
            'ip' => request()->ip(),
        ]);

        return $deleted;
    }

    /**
     * Connection status for the Settings page.
     *
     * "connected" means a device has completed the handshake — not merely that
     * a setup code is outstanding. Testing a notification before that point
     * would broadcast to nobody, so the two states are kept apart.
     *
     * @return array{connected: bool, awaiting_connection: bool, device_name: string|null, last_used_at: string|null, created_at: string|null, expires_at: string|null}
     */
    public function status(User $user): array
    {
        /** @var PersonalAccessToken|null $device */
        $device = $this->liveTokens($user)
            ->where('name', '!=', self::SETUP_TOKEN_NAME)
            ->orderByRaw('last_used_at is null')
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->first();

        if ($device) {
            return [
                'connected' => true,
                'awaiting_connection' => false,
                'device_name' => str($device->name)->after(self::NAME_PREFIX)->toString(),
                'last_used_at' => $device->last_used_at?->toIso8601String(),
                'created_at' => $device->created_at?->toIso8601String(),
                'expires_at' => $device->expires_at?->toIso8601String(),
            ];
        }

        /** @var PersonalAccessToken|null $setup */
        $setup = $this->liveTokens($user)
            ->where('name', self::SETUP_TOKEN_NAME)
            ->orderByDesc('id')
            ->first();

        return [
            'connected' => false,
            'awaiting_connection' => (bool) $setup,
            'device_name' => null,
            'last_used_at' => null,
            'created_at' => $setup?->created_at?->toIso8601String(),
            'expires_at' => $setup?->expires_at?->toIso8601String(),
        ];
    }

    /**
     * Desktop tokens for this user that have not expired.
     */
    private function liveTokens(User $user): MorphMany
    {
        return $user->tokens()
            ->where('name', 'like', self::NAME_PREFIX.'%')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}
