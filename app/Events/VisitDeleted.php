<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class VisitDeleted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $visitId;

    /**
     * Create a new event instance.
     */
    public function __construct($visitId)
    {
        $this->visitId = $visitId;
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
        return 'VisitDeleted';
    }

    public function broadcastWith(): array
    {
        return [
            'visitId' => $this->visitId,
        ];
    }
}
