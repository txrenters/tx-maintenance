<?php

namespace App\Events;

use App\Models\JobberVisit;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class VisitUpdated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $visit;
    /**
     * Create a new event instance.
     */
    public function __construct(JobberVisit $visit)
    {
        $this->visit = $visit;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('visits'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'VisitUpdated';
    }
}
