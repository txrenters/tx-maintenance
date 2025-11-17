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

    /**
     * Create a new notification instance.
     */
    public function __construct($workOrder)
    {
        $this->workOrder = $workOrder;
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
            ->action('View Work Order', url('/'))
            ->line('Please review the details and begin work as soon as possible.')
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
