<?php

namespace App\Notifications\Concerns;

use App\Services\Desktop\DesktopConnectionService;
use Illuminate\Notifications\Messages\BroadcastMessage;

/**
 * Shapes a notification's broadcast payload into the contract the TexasRenters
 * Desktop client parses.
 *
 * Laravel's own BroadcastNotificationCreated adds the "id" the client
 * deduplicates on, so only the presentational keys are filled in here. Any
 * extra keys passed through are preserved.
 */
trait BroadcastsToDesktop
{
    /**
     * @param  array<string, mixed>  $payload
     */
    protected function desktopBroadcast(array $payload): BroadcastMessage
    {
        $defaults = [
            'title' => config('desktop.name') ?: config('app.name'),
            'message' => null,
            'url' => null,
            'priority' => 'normal',
            'icon' => app(DesktopConnectionService::class)->iconUrl(),
            'created_at' => now()->toIso8601String(),
        ];

        return new BroadcastMessage(array_merge(
            $defaults,
            array_filter($payload, fn ($value) => ! is_null($value)),
        ));
    }
}
