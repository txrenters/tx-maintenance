<?php

namespace App\Notifications;

use App\Notifications\Concerns\BroadcastsToDesktop;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * A staff-facing event, broadcast to one recipient's private channel.
 *
 * Deliberately parameterised rather than one class per event: the twelve
 * events in config/staff_notifications.php differ only in wording, priority
 * and audience, and a class apiece would be twelve places for the desktop
 * payload contract to drift.
 *
 * The array is passed in already flattened so the queued payload never carries
 * an Activity model — these fire on the Twilio webhook path, at inbound-SMS
 * volume.
 */
class StaffActivityNotification extends Notification implements ShouldQueue
{
    use BroadcastsToDesktop, Queueable;

    /**
     * @param  array{event: string, title: string, message: string, url: string|null, priority: string, work_order_id: int|string|null, activity_id: int|string|null}  $event
     */
    public function __construct(private readonly array $event) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['broadcast'];
    }

    public function broadcastType(): string
    {
        return $this->event['event'];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return $this->desktopBroadcast([
            'title' => $this->event['title'],
            'message' => $this->event['message'],
            'url' => $this->event['url'],
            'priority' => $this->event['priority'],
            // Carried through for the in-app bell, which keys off both.
            'type' => $this->event['event'],
            'work_order_id' => $this->event['work_order_id'],
            'activity_id' => $this->event['activity_id'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->event;
    }
}
