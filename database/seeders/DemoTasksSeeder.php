<?php

namespace Database\Seeders;

use App\Models\Task;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderTask;
use Illuminate\Database\Seeder;

/**
 * Local-only helper: seed a work order's Tasks tab with pure mock tasks so the
 * bulk "Complete All" / "Select all" flow can be tested visually.
 *
 * These are intentionally NOT linked to any task template (task_id is null), so
 * completing them only flips their status — it never triggers a service-status
 * change, task regeneration, or a PropertyWare sync. Nothing real is touched.
 */
class DemoTasksSeeder extends Seeder
{
    public function run(): void
    {
        $workOrder = WorkOrder::query()
            ->where('status', 'Open')
            ->latest('id')
            ->first();

        if (! $workOrder) {
            $this->command?->warn('No open work order found to attach demo tasks to.');

            return;
        }

        $user = User::query()->latest('id')->first();

        // Only remove our own mock tasks so we never touch real ones.
        WorkOrderTask::where('work_order_id', $workOrder->id)
            ->where('description', 'like', '[TEST]%')
            ->delete();

        $now = now();

        // Use only safe "Not Changed" template tasks. These never trigger a service
        // status change, task regeneration, or a PropertyWare sync — completing them
        // just flips their status, and (being template-based) they show the undo (↩)
        // icon once completed. That lets the full cycle be tested: select →
        // Complete All → undo. All local, nothing real is touched.
        $notChangedTemplateIds = [2, 3, 4, 5, 6, 8, 9, 10];

        $pending = 0;
        $completed = 0;

        foreach ($notChangedTemplateIds as $index => $templateId) {
            $template = Task::with('nextServiceStatus')->find($templateId);

            if (! $template || ($template->nextServiceStatus->name ?? null) !== 'Not Changed') {
                continue;
            }

            // Seed the last two already completed so the undo icon is visible up
            // front; the rest start pending for the select / Complete All flow.
            $preCompleted = $index >= count($notChangedTemplateIds) - 2;

            WorkOrderTask::create([
                'work_order_id' => $workOrder->id,
                'task_id' => $template->id,
                'assigned_user_id' => $user?->id,
                'description' => '[TEST] '.($template->name ?? 'Task'),
                'due_date' => $now->toDateString(),
                'status' => $preCompleted ? 'completed' : 'pending',
                'option' => null,
            ]);

            $preCompleted ? $completed++ : $pending++;
        }

        $this->command?->info("Seeded {$pending} pending + {$completed} completed (undo) tasks on work order #{$workOrder->work_order_no} (id {$workOrder->id}).");
    }
}
