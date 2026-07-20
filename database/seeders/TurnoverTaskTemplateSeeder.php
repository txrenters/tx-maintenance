<?php

namespace Database\Seeders;

use App\Models\ServiceStatus;
use App\Models\TaskTemplate;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TurnoverTaskTemplateSeeder extends Seeder
{
    private const WORK_ORDER_TYPE = 'Turnover';

    private const COORDINATOR_EMAIL = 'service@txhomemp.com'; // John Carlo

    private const ACCOUNTING_EMAIL = 'oa@texasrenters.com'; // Cris Mary

    /**
     * Seed the streamlined Turnover work order workflow (per John's approved
     * "Turnover Work Order Workflow" sheet, 2026-07-20): 6 non-emergency
     * templates keyed to the Turnover work order type. Statuses the sheet
     * skips intentionally have no template — TaskService generates no tasks
     * there instead of falling back to the generic workflow.
     *
     * Idempotent: existing Turnover templates are replaced on re-run.
     */
    public function run(): void
    {
        $statusIds = ServiceStatus::pluck('id', 'name');

        $required = [
            'New',
            'Assigned - Waiting on Scheduling',
            'Scheduled',
            'Completed - Verified - Waiting on Bill',
            'Bill Attached - Waiting on Approval',
            'Approved - Waiting on Payment',
            'Closed',
            'Not Changed',
        ];

        foreach ($required as $statusName) {
            if (! isset($statusIds[$statusName])) {
                Log::error("TurnoverTaskTemplateSeeder: missing service status '{$statusName}', aborting.");

                return;
            }
        }

        $coordinatorId = User::where('email', self::COORDINATOR_EMAIL)->value('id');
        $accountingId = User::where('email', self::ACCOUNTING_EMAIL)->value('id');

        if (! $coordinatorId || ! $accountingId) {
            Log::warning('TurnoverTaskTemplateSeeder: assignee user missing, tasks will fall back to WOC routing.', [
                self::COORDINATOR_EMAIL => $coordinatorId,
                self::ACCOUNTING_EMAIL => $accountingId,
            ]);
        }

        $templates = [
            [
                'name' => 'Turnover - New - Non-emergency',
                'current' => 'New',
                'next' => 'Assigned - Waiting on Scheduling',
                'tasks' => [
                    ['name' => 'Update Zone for Service Request (Look at Property)', 'for' => 'Woc', 'user' => $coordinatorId, 'due' => 'same day', 'done' => 'Not Changed'],
                    ['name' => 'Update Management Plan (Important for Charges)', 'for' => 'Woc', 'user' => $coordinatorId, 'due' => 'same day', 'done' => 'Assigned - Waiting on Scheduling'],
                ],
            ],
            [
                'name' => 'Turnover - Assigned Waiting on Scheduling - Non-emergency',
                'current' => 'Assigned - Waiting on Scheduling',
                'next' => 'Scheduled',
                'tasks' => [
                    ['name' => 'Assign to Appropriate Vendor', 'for' => 'Woc', 'user' => $coordinatorId, 'due' => 'same day', 'done' => 'Not Changed'],
                    ['name' => 'Fill in Scheduled Start Date', 'for' => 'Woc', 'user' => $coordinatorId, 'due' => 'same day', 'done' => 'Not Changed'],
                    ['name' => 'Fill in Projected Service End Date', 'for' => 'Woc', 'user' => $coordinatorId, 'due' => 'same day', 'done' => 'Scheduled'],
                ],
            ],
            [
                'name' => 'Turnover - Scheduled - Non-emergency',
                'current' => 'Scheduled',
                'next' => 'Completed - Verified - Waiting on Bill',
                'tasks' => [
                    ['name' => 'After Completing Service - take "After Photos" in App', 'for' => 'Vendor', 'user' => null, 'due' => '7 days from start date', 'done' => 'Completed - Verified - Waiting on Bill'],
                ],
            ],
            [
                'name' => 'Turnover - Completed Verified Waiting on Bill - Non-emergency',
                'current' => 'Completed - Verified - Waiting on Bill',
                'next' => 'Bill Attached - Waiting on Approval',
                'tasks' => [
                    ['name' => 'Upload Invoice to app for payment', 'for' => 'Vendor', 'user' => null, 'due' => 'same day', 'done' => 'Bill Attached - Waiting on Approval'],
                ],
            ],
            [
                'name' => 'Turnover - Bill Attached Waiting on Approval - Non-emergency',
                'current' => 'Bill Attached - Waiting on Approval',
                'next' => 'Approved - Waiting on Payment',
                'tasks' => [
                    ['name' => 'Confirm that Bill has synced to PW', 'for' => 'Woc', 'user' => $coordinatorId, 'due' => 'same day', 'done' => 'Not Changed'],
                    ['name' => 'Confirm that All Pictures have synced to PW (Auto publish)', 'for' => 'Woc', 'user' => $coordinatorId, 'due' => 'same day', 'done' => 'Not Changed'],
                    ['name' => 'Publish Vendor Invoice to Owner Portal (Only the THMP, not the 3rd party vendor invoices)', 'for' => 'Woc', 'user' => $coordinatorId, 'due' => 'same day', 'done' => 'Not Changed'],
                    ['name' => 'Inform Owner Service is completed', 'for' => 'Woc', 'user' => $coordinatorId, 'due' => 'same day', 'done' => 'Approved - Waiting on Payment'],
                ],
            ],
            [
                'name' => 'Turnover - Approved Waiting on Payment - Non-emergency',
                'current' => 'Approved - Waiting on Payment',
                'next' => 'Closed',
                'tasks' => [
                    ['name' => 'Make sure that move out inspection has been completed', 'for' => 'Woc', 'user' => $coordinatorId, 'due' => 'same day', 'done' => 'Not Changed'],
                    ['name' => 'Confirm Bill has been paid', 'for' => 'Woc', 'user' => $accountingId, 'due' => 'same day', 'done' => 'Not Changed'],
                    ['name' => 'Close Work Order', 'for' => 'Woc', 'user' => $accountingId, 'due' => 'same day', 'done' => 'Closed'],
                ],
            ],
        ];

        DB::transaction(function () use ($templates, $statusIds) {
            TaskTemplate::where('work_order_type', self::WORK_ORDER_TYPE)->delete();

            $now = now();

            foreach ($templates as $template) {
                $taskTemplate = TaskTemplate::create([
                    'name' => $template['name'],
                    'work_order_type' => self::WORK_ORDER_TYPE,
                    'is_available' => true,
                    'is_default' => false,
                    'current_service_status_id' => $statusIds[$template['current']],
                    'is_current_service_status_emergency' => false,
                    'next_service_status_id' => $statusIds[$template['next']],
                    'is_next_service_status_emergency' => false,
                    'description' => 'Streamlined Turnover workflow',
                ]);

                $tasks = [];

                foreach ($template['tasks'] as $task) {
                    $tasks[] = [
                        'name' => $task['name'],
                        'is_mandatory' => true,
                        'is_optional' => false,
                        'is_emergency' => false,
                        'type' => $task['for'],
                        'assigned_user_id' => $task['for'] === 'Woc' ? $task['user'] : null,
                        'due_date' => $task['due'],
                        'next_service_status_id' => $statusIds[$task['done']],
                        'task_template_id' => $taskTemplate->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                DB::table('tasks')->insert($tasks);
            }
        });

        Log::info('TurnoverTaskTemplateSeeder: Turnover task templates seeded.');
    }
}
