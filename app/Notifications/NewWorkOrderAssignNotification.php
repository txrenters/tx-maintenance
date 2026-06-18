<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewWorkOrderAssignNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $workOrder;

    protected ?string $portalUrl;

    /**
     * Create a new notification instance.
     */
    public function __construct($workOrder, ?string $portalUrl = null)
    {
        $this->workOrder = $workOrder;
        $this->portalUrl = $portalUrl;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New Work Order Assigned: #'.$this->workOrder->work_order_no)
            ->greeting('Hello '.($notifiable->name ?? '').',')
            ->line('You have been assigned a new work order.')
            ->line('*Work Order: #'.$this->workOrder->work_order_no)
            ->line('*Priority: '.ucfirst($this->workOrder->priority))
            ->line('*Description: '.($this->workOrder->description ?? 'No description provided.'))
            ->action('View Work Order', $this->portalUrl ?? url('/'))
            ->line('Use the button above to view the full work order, upload photos, send your estimate, and message your coordinator — no login required.')
            ->salutation('Thank you, — TX Maintenance Team');

    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}
