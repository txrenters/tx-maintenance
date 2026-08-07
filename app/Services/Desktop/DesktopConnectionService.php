<?php

namespace App\Services\Desktop;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Builds the handshake payload the TexasRenters Desktop client parses.
 *
 * The shape here is a fixed contract with a client that already ships — every
 * key, including the "private-" prefix on the channel, is load bearing.
 */
class DesktopConnectionService
{
    /**
     * @return array{
     *     application: array{name: string, icon_url: string},
     *     user: array{id: int, name: string, email: string},
     *     token: string,
     *     channel: string,
     *     websocket: array{key: string|null, host: string|null, port: int, scheme: string, auth_endpoint: string}
     * }
     */
    public function payload(User $user, string $deviceToken): array
    {
        return [
            'application' => [
                'name' => config('desktop.name') ?: config('app.name'),
                'icon_url' => $this->iconUrl(),
            ],
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'token' => $deviceToken,
            'channel' => 'private-'.$this->notificationChannelFor($user),
            'websocket' => [
                'key' => config('desktop.websocket.key'),
                'host' => config('desktop.websocket.host'),
                'port' => config('desktop.websocket.port'),
                'scheme' => config('desktop.websocket.scheme'),
                'auth_endpoint' => url('/api/broadcasting/auth'),
            ],
        ];
    }

    /**
     * The private channel Laravel broadcasts this user's notifications on.
     *
     * Mirrors Illuminate\Notifications\Channels\BroadcastChannel so the two can
     * never drift apart.
     */
    public function notificationChannelFor(User $user): string
    {
        if (method_exists($user, 'receivesBroadcastNotificationsOn')) {
            return $user->receivesBroadcastNotificationsOn();
        }

        return str_replace('\\', '.', $user::class).'.'.$user->getKey();
    }

    /**
     * Absolute URL of the icon shown against this connection and its
     * notifications in the client's history.
     */
    public function iconUrl(): string
    {
        $path = config('desktop.icon_url');

        if (blank($path)) {
            return url('/logo.png');
        }

        return Str::startsWith($path, ['http://', 'https://']) ? $path : url($path);
    }
}
