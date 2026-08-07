<?php

namespace App\Notifications;

use App\Notifications\Concerns\BroadcastsToDesktop;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent by the "Test Notification" buttons. It travels the real broadcast path,
 * so receiving it proves the token, the channel authorisation and the live
 * socket all work together.
 */
class DesktopTestNotification extends Notification implements ShouldQueue
{
    use BroadcastsToDesktop, Queueable;

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['broadcast'];
    }

    public function broadcastType(): string
    {
        return 'desktop_test';
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return $this->desktopBroadcast([
            'title' => 'Desktop Connection Successful',
            'message' => 'This application can now send notifications to your desktop.',
            'url' => route('settings.desktop-notifications.edit'),
            'priority' => 'normal',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'desktop_test',
            'message' => 'Desktop Connection Successful',
        ];
    }
}
