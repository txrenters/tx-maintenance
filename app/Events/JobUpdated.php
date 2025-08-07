<?php

namespace App\Events;

use App\Models\Jobber;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class JobUpdated implements ShouldBroadcastNow
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
            new Channel('jobs'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'JobUpdated';
    }

    public function broadcastWith(): array
    {
        return [
            'job' => [
                'id' => $this->job->id,
                'job_number' => $this->job->job_number,
                'status' => $this->job->status,
                'updated_at' => $this->job->updated_at,
            ],
        ];
    }
}
