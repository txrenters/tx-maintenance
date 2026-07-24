<?php

namespace Database\Seeders;

use App\Models\Conversation;
use App\Models\WorkOrder;
use Illuminate\Database\Seeder;

class DemoNotificationsSeeder extends Seeder
{
    /**
     * Generate real "New Message Received" notifications for existing work orders by
     * creating conversation records and activity-log entries the same way the app does
     * for inbound messages. Useful for local testing of the notification panel.
     */
    public function run(): void
    {
        $samples = [
            ['type' => 'tenant', 'text' => 'Thank you for the update.'],
            ['type' => 'tenant', 'text' => 'Hello, sorry for my late reply. When can the technician come by?'],
            ['type' => 'vendor', 'text' => 'On my way to the property now, ETA about 30 minutes.'],
            ['type' => 'tenant', 'text' => 'The issue is still not resolved, please advise.'],
            ['type' => 'vendor', 'text' => 'Parts have arrived. Scheduling the repair for tomorrow morning.'],
            ['type' => 'tenant', 'text' => 'Appreciate the quick response!'],
        ];

        $workOrders = WorkOrder::query()
            ->whereNotNull('work_order_no')
            ->where('status', 'Open')
            ->latest('id')
            ->take(count($samples))
            ->get();

        foreach ($workOrders as $index => $workOrder) {
            $sample = $samples[$index % count($samples)];

            $conversation = Conversation::create([
                'work_order_id' => $workOrder->id,
                'conversation_type' => $sample['type'],
                'message' => $sample['text'],
                'sender_number' => '+1512555'.str_pad((string) (1000 + $index), 4, '0', STR_PAD_LEFT),
                'receiver_number' => '+15125550000',
                'is_read' => false,
            ]);

            activity()
                ->performedOn($conversation)
                ->event('work_order_message_received')
                ->withProperties([
                    'senderNumber' => $conversation->sender_number,
                    'receiverNumber' => $conversation->receiver_number,
                    'message' => $sample['text'],
                    'work_order_id' => $workOrder->id,
                ])
                ->log('Work Order #'.$workOrder->work_order_no.' - New Message Received');
        }

        $this->command?->info('Seeded '.$workOrders->count().' work order notifications.');
    }
}
