<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class JobDeleted implements ShouldBroadcast, ShouldQueue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $jobId;

    /**
     * Create a new event instance.
     */
    public function __construct($jobId)
    {
        $this->jobId = $jobId;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('jobs'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'JobDeleted';
    }

    public function broadcastWith(): array
    {
        return [
            'jobId' => $this->jobId,
        ];
    }
}
