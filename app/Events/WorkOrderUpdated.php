<?php

namespace App\Events;

use App\Models\WorkOrder;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WorkOrderUpdated implements ShouldBroadcast, ShouldQueue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $workOrder;
    /**
     * Create a new event instance.
     */
    public function __construct(WorkOrder $workOrder)
    {
        $this->workOrder = $workOrder->load('service_status');
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('workOrders'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'WorkOrderUpdated';
    }

    public function broadcastWith(): array
    {
        return [
            'workOrder' => [
                'id' => $this->workOrder->id,
                'work_order_no' => $this->workOrder->work_order_no,
                'service_status_id' => $this->workOrder->service_status_id,
                'service_status' => $this->workOrder->service_status,
                'status' => $this->workOrder->status,
                'local_status' => $this->workOrder->local_status,
                'updated_at' => $this->workOrder->updated_at,
            ],
        ];
       
    }
}
