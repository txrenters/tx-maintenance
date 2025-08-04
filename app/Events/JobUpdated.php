<?php

namespace App\Events;

use App\Models\Jobber;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class JobUpdated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $job;
    /**
     * Create a new event instance.
     */
    public function __construct(Jobber $job)
    {
        $this->job = $job;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('jobs'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'JobUpdated';
    }
}
