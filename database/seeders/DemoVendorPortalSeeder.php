<?php

namespace Database\Seeders;

use App\Models\Building;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Models\WorkOrderTask;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Local-only helper: seed a mock vendor + a spread of open work orders so the
 * redesigned Vendor Portal dashboard cards can be seen in every visual state
 * (emergency, overdue, due-today, upcoming, task progress, unread badge).
 *
 * Nothing here is real: the vendor uses a fixed demo portal token and the work
 * orders live in a 99001-99006 number range so re-running just replaces them.
 */
class DemoVendorPortalSeeder extends Seeder
{
    private const PORTAL_TOKEN = 'demo-vendor-portal';

    private const VENDOR_EMAIL = 'demo-portal@texasrenters.local';

    private const WO_START = 99001;

    private const WO_END = 99006;

    public function run(): void
    {
        $user = User::updateOrCreate(
            ['email' => self::VENDOR_EMAIL],
            [
                'name' => 'Ace Handyman Co (DEMO)',
                'phone' => '5125550142',
                'password' => bcrypt(self::VENDOR_EMAIL),
            ]
        );

        $vendor = Vendor::updateOrCreate(
            ['email' => self::VENDOR_EMAIL],
            [
                'propertyware_id' => 999000001,
                'name' => 'Ace Handyman Co (DEMO)',
                'name_on_check' => 'Ace Handyman Co',
                'user_id' => $user->id,
                'is_active' => true,
                'portal_token' => self::PORTAL_TOKEN,
            ]
        );

        // Clean any previous run of this seeder (its work orders + their rows).
        $oldIds = WorkOrder::query()
            ->whereBetween('work_order_no', [self::WO_START, self::WO_END])
            ->pluck('id');

        if ($oldIds->isNotEmpty()) {
            WorkOrderTask::whereIn('work_order_id', $oldIds)->delete();
            Conversation::whereIn('work_order_id', $oldIds)->delete();
            DB::table('work_order_vendors')->whereIn('work_order_id', $oldIds)->delete();
            WorkOrder::whereIn('id', $oldIds)->delete();
        }

        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        $nextWeek = now()->addDays(5)->toDateString();

        $building = fn (string $name, string $city) => Building::firstOrCreate(
            ['name' => $name],
            ['propertyware_id' => 999000000 + (crc32($name) % 900000), 'city' => $city, 'state_region' => 'TX']
        );

        // Each entry: the card variant it demonstrates.
        $cards = [
            [
                // Emergency -> red accent, High priority.
                'work_order_no' => 99001,
                'description' => 'Burst pipe under kitchen sink, water spreading.',
                'location' => '1508 Blackbird Dr',
                'category' => 'Plumbing',
                'priority' => 'High',
                'is_emergency' => true,
                'service_status_id' => 1,
                'building' => $building('Blackbird LLC', 'Baytown'),
                'scheduled_end_date' => null,
                'tasks' => [['pending', $today], ['pending', $today], ['completed', $today]],
                'unread' => 0,
            ],
            [
                // Overdue schedule -> red accent.
                'work_order_no' => 99002,
                'description' => 'Replace tripped breaker in garage panel.',
                'location' => '15102 Cavecreek',
                'category' => 'Electrical',
                'priority' => 'Medium',
                'is_emergency' => false,
                'service_status_id' => 4,
                'building' => $building('Cavecreek Homes', 'Houston'),
                'scheduled_end_date' => $yesterday,
                'tasks' => [['completed', $yesterday], ['pending', $yesterday]],
                'unread' => 0,
            ],
            [
                // Due today -> blue accent.
                'work_order_no' => 99003,
                'description' => 'HVAC not cooling, check compressor.',
                'location' => '5110 Shady Gardens',
                'category' => 'HVAC',
                'priority' => 'Medium',
                'is_emergency' => false,
                'service_status_id' => 2,
                'building' => $building('Shady Gardens', 'Katy'),
                'scheduled_end_date' => $today,
                'tasks' => [['pending', $today]],
                'unread' => 0,
            ],
            [
                // Upcoming schedule + all tasks done -> green accent.
                'work_order_no' => 99004,
                'description' => 'General turnover touch-ups before move-in.',
                'location' => '9931 Chimneys',
                'category' => 'General Maintenance',
                'priority' => 'Low',
                'is_emergency' => false,
                'service_status_id' => 8,
                'building' => $building('Prairie Smoke', 'Cypress'),
                'scheduled_end_date' => $nextWeek,
                'tasks' => [['completed', $nextWeek], ['completed', $nextWeek]],
                'unread' => 0,
            ],
            [
                // No schedule, pending task due later -> green, with unread badge.
                'work_order_no' => 99005,
                'description' => 'Trim hedges and mow back lawn.',
                'location' => '18919 Summer Farm Trl',
                'category' => 'Landscaping',
                'priority' => 'Medium',
                'is_emergency' => false,
                'service_status_id' => 2,
                'building' => $building('Summerfield', 'Richmond'),
                'scheduled_end_date' => null,
                'tasks' => [
                    ['completed', $nextWeek], ['completed', $nextWeek], ['completed', $nextWeek],
                    ['pending', $nextWeek], ['pending', $nextWeek], ['pending', $nextWeek],
                    ['pending', $nextWeek], ['pending', $nextWeek], ['pending', $nextWeek],
                ],
                'unread' => 2,
            ],
            [
                // No tasks at all -> green, High priority.
                'work_order_no' => 99006,
                'description' => 'Roof leak assessment after storm.',
                'location' => '2919 Pepperwood',
                'category' => 'Roofing',
                'priority' => 'High',
                'is_emergency' => false,
                'service_status_id' => 1,
                'building' => $building('Pepperwood', 'Spring'),
                'scheduled_end_date' => null,
                'tasks' => [],
                'unread' => 0,
            ],
        ];

        foreach ($cards as $card) {
            $workOrder = WorkOrder::create([
                'work_order_no' => $card['work_order_no'],
                'description' => $card['description'],
                'location' => $card['location'],
                'category' => $card['category'],
                'priority' => $card['priority'],
                'is_emergency' => $card['is_emergency'],
                'status' => 'Open',
                'type' => 'Repair',
                'service_status_id' => $card['service_status_id'],
                'building_id' => $card['building']->id,
                'scheduled_end_date' => $card['scheduled_end_date'],
                'created_date' => now()->subDays($card['work_order_no'] - self::WO_START),
            ]);

            DB::table('work_order_vendors')->insert([
                'work_order_id' => $workOrder->id,
                'vendor_id' => $vendor->id,
                'access_token' => 'demo-tok-'.$card['work_order_no'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($card['tasks'] as $i => [$statusValue, $dueDate]) {
                WorkOrderTask::create([
                    'work_order_id' => $workOrder->id,
                    'assigned_user_id' => $user->id,
                    'description' => '[DEMO] Task '.($i + 1),
                    'due_date' => $dueDate,
                    'status' => $statusValue,
                ]);
            }

            for ($i = 0; $i < $card['unread']; $i++) {
                Conversation::create([
                    'work_order_id' => $workOrder->id,
                    'vendor_id' => $vendor->id,
                    'conversation_type' => 'vendor',
                    'message' => '[DEMO] Message from coordinator #'.($i + 1),
                    'sender_number' => 'portal',
                    'receiver_number' => 'portal',
                    'is_read' => false,
                    'read_by_vendor' => false,
                ]);
            }
        }

        $url = route('vendor.portal.dashboard', self::PORTAL_TOKEN);

        $this->command?->info('Seeded 6 demo work orders for vendor "Ace Handyman Co (DEMO)".');
        $this->command?->info('Vendor Portal dashboard: '.$url);
    }
}
