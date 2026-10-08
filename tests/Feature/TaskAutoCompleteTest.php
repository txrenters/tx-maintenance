<?php

namespace Tests\Feature;

use App\Ai\Agents\RepairCompletionJudgeAgent;
use App\Models\Attachments;
use App\Models\Conversation;
use App\Models\Jobber;
use App\Models\JobberClient;
use App\Models\JobberProperty;
use App\Models\JobberVisit;
use App\Models\ServiceSchedule;
use App\Models\ServiceStatus;
use App\Models\Task;
use App\Models\TaskDetail;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Models\WorkOrderJobberNote;
use App\Models\WorkOrderJobberNoteFile;
use App\Models\WorkOrderTask;
use App\Services\PropertyWareService;
use App\Services\TaskAutoCompleteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Service THMP asked (2026-10-08) for six of their checklist tasks to be
 * ticked by the system once the thing they describe has happened. Earl's
 * rule (10-09): follow the task template — a "Not Changed" line only gets
 * ticked; a line whose Task Done Service Status names a status moves the
 * work order exactly as a manual tick would.
 */
class TaskAutoCompleteTest extends TestCase
{
    use RefreshDatabase;

    private ServiceStatus $waiting;

    private ServiceStatus $scheduled;

    private ServiceStatus $serviceCompleted;

    private ServiceStatus $notChanged;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.tasks.auto_complete_enabled' => true,
            'services.ai.repair_judge' => true,
            'services.ai.repair_min_confidence' => 80,
            'ai.providers.openai.key' => 'test-key',
        ]);

        $this->waiting = ServiceStatus::query()->create(['name' => 'Assigned - Waiting on Scheduling', 'description' => 'x']);
        $this->scheduled = ServiceStatus::query()->create(['name' => 'Scheduled', 'description' => 'x']);
        $this->serviceCompleted = ServiceStatus::query()->create(['name' => 'Service Completed - Call Tenant for Followup', 'description' => 'x']);
        $this->notChanged = ServiceStatus::query()->create(['name' => 'Not Changed', 'description' => 'x']);
    }

    /** THMP with a vendor-role login, which is what generated vendor tasks are assigned to. */
    private function thmpVendor(): Vendor
    {
        Role::findOrCreate('vendor');

        return Vendor::query()->firstOrCreate(['propertyware_id' => 'V-THMP'], [
            'name' => Vendor::THMP_NAME,
            'vendor_type' => 'General',
            'is_active' => true,
            'user_id' => User::factory()->create()->assignRole('vendor')->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function thmpWorkOrder(array $overrides = []): WorkOrder
    {
        $workOrder = WorkOrder::factory()->create(array_merge([
            'service_status_id' => $this->waiting->id,
            'work_order_no' => 44500,
            'status' => 'Open',
        ], $overrides));

        $workOrder->vendors()->attach($this->thmpVendor()->id);

        return $workOrder;
    }

    /**
     * @param  array<string, mixed>  $taskOverrides
     */
    private function templateTask(WorkOrder $workOrder, ?string $trigger, string $name = 'Fill in Scheduled Start Date', array $taskOverrides = []): WorkOrderTask
    {
        $template = TaskTemplate::query()->firstOrCreate(
            ['current_service_status_id' => $workOrder->service_status_id, 'is_current_service_status_emergency' => false],
            ['name' => 'Template for status '.$workOrder->service_status_id],
        );

        $task = Task::query()->create(array_merge([
            'name' => $name,
            'due_date' => 'same day',
            'type' => 'Vendor',
            'task_template_id' => $template->id,
            'next_service_status_id' => $this->notChanged->id,
            'auto_complete_trigger' => $trigger,
        ], $taskOverrides));

        return WorkOrderTask::factory()->create([
            'work_order_id' => $workOrder->id,
            'task_id' => $task->id,
            'description' => $name,
            'created_at' => now()->subHour(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function schedule(WorkOrder $workOrder, array $overrides = []): ServiceSchedule
    {
        return ServiceSchedule::query()->create(array_merge([
            'title' => 'Service Schedule for '.$workOrder->work_order_no,
            'scheduled_date' => '2026-10-12 09:00:00',
            'work_order_id' => $workOrder->id,
            'vendor_id' => $workOrder->vendors()->first()->id,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function jobberJob(WorkOrder $workOrder, array $overrides = []): Jobber
    {
        $client = JobberClient::query()->create(['jobber_id' => 'client-'.$workOrder->id, 'name' => 'Client', 'jobber_web_uri' => 'https://example.test']);
        $property = JobberProperty::query()->create(['jobber_id' => 'property-'.$workOrder->id, 'jobber_client_id' => $client->id]);

        $job = Jobber::query()->create(array_merge([
            'jobber_id' => 'job-'.$workOrder->id,
            'title' => 'Repair - #'.$workOrder->work_order_no,
            'job_status' => 'active',
            'jobber_client_id' => $client->id,
            'jobber_property_id' => $property->id,
        ], $overrides));

        $workOrder->update(['jobber_job_gid' => $job->jobber_id]);

        return $job;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function visit(Jobber $job, array $overrides = []): JobberVisit
    {
        return JobberVisit::query()->create(array_merge([
            'jobber_id' => 'visit-'.$job->id.'-'.fake()->unique()->numberBetween(1, 9999),
            'jobber_job_id' => $job->id,
            'jobber_client_id' => $job->jobber_client_id,
            'jobber_property_id' => $job->jobber_property_id,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function attachment(WorkOrder $workOrder, string $type, array $overrides = []): Attachments
    {
        return Attachments::query()->create(array_merge([
            'title' => 'Photo',
            'filename' => 'attachments/photo.jpg',
            'filetype' => 'image/jpeg',
            'type' => $type,
            'work_order_id' => $workOrder->id,
            'user_id' => User::factory()->create()->id,
        ], $overrides));
    }

    /** A photo the crew took in the Jobber app, as the note sync stores it. */
    private function jobberPhoto(WorkOrder $workOrder, string $takenAt): Attachments
    {
        $gid = 'file-'.fake()->unique()->numberBetween(1, 9999);

        $attachment = $this->attachment($workOrder, 'attachment', ['jobber_note_file_gid' => $gid, 'user_id' => null]);

        $note = WorkOrderJobberNote::query()->create([
            'work_order_id' => $workOrder->id,
            'jobber_note_gid' => 'note-'.$gid,
            'jobber_job_gid' => $workOrder->jobber_job_gid,
            'jobber_created_at' => $takenAt,
        ]);

        WorkOrderJobberNoteFile::query()->create([
            'work_order_jobber_note_id' => $note->id,
            'jobber_file_gid' => $gid,
            'attachment_id' => $attachment->id,
        ]);

        return $attachment;
    }

    /**
     * "Have you Completed the Repair" as the Scheduled template defines it:
     * a Yes/No line whose Yes moves to Service Completed.
     */
    private function optionalRepairTask(WorkOrder $workOrder): WorkOrderTask
    {
        $row = $this->templateTask($workOrder, 'jobber_job_completed', 'Have you Completed the Repair', [
            'is_optional' => true,
            'next_service_status_id' => null,
        ]);

        TaskDetail::query()->create(['task_id' => $row->task_id, 'task_for' => 'Yes', 'task_service_status_id' => $this->serviceCompleted->id, 'is_task_service_status_emergency' => false]);
        TaskDetail::query()->create(['task_id' => $row->task_id, 'task_for' => 'No', 'task_service_status_id' => $this->notChanged->id, 'is_task_service_status_emergency' => false]);

        return $row;
    }

    /** A line on the Scheduled template, generated when a work order moves there. */
    private function scheduledTemplateLine(string $name, ?string $trigger = null): Task
    {
        $template = TaskTemplate::query()->firstOrCreate(
            ['current_service_status_id' => $this->scheduled->id, 'is_current_service_status_emergency' => false],
            ['name' => 'Scheduled - Non Emergency'],
        );

        return Task::query()->create([
            'name' => $name,
            'due_date' => 'same day',
            'type' => 'Vendor',
            'task_template_id' => $template->id,
            'next_service_status_id' => $this->notChanged->id,
            'auto_complete_trigger' => $trigger,
        ]);
    }

    /** A note the technician wrote in the Jobber app, as the note sync stores it. */
    private function techNote(WorkOrder $workOrder, string $message, string $at = '2026-10-12 15:10:00'): WorkOrderJobberNote
    {
        return WorkOrderJobberNote::query()->create([
            'work_order_id' => $workOrder->id,
            'jobber_note_gid' => 'note-'.fake()->unique()->numberBetween(1, 9999),
            'jobber_job_gid' => $workOrder->jobber_job_gid,
            'message' => $message,
            'author_name' => 'Emanuel Hall',
            'jobber_created_at' => $at,
        ]);
    }

    /** The judge reading that note as "finished". */
    private function judgeSaysDone(int $confidence = 92): void
    {
        RepairCompletionJudgeAgent::fake([[
            'completed' => true,
            'confidence' => $confidence,
            'reason' => 'The note says the unit is repaired and cooling.',
        ]]);
    }

    private function tick(WorkOrder $workOrder): int
    {
        return app(TaskAutoCompleteService::class)->run($workOrder->fresh());
    }

    private function assertTicked(WorkOrderTask $task, string $label): void
    {
        $task = $task->fresh();

        $this->assertSame('completed', $task->status);
        $this->assertNotNull($task->auto_completed_at);
        $this->assertStringContainsString($label, (string) $task->auto_complete_reason);
    }

    private function assertUntouched(WorkOrderTask $task): void
    {
        $task = $task->fresh();

        $this->assertSame('pending', $task->status);
        $this->assertNull($task->auto_completed_at);
    }

    // --- schedule dates -------------------------------------------------

    public function test_a_schedule_with_a_start_date_ticks_the_start_task(): void
    {
        $workOrder = $this->thmpWorkOrder();
        $task = $this->templateTask($workOrder, 'schedule_start_set');
        $this->schedule($workOrder);

        $this->assertSame(1, $this->tick($workOrder));
        $this->assertTicked($task, 'Service Schedule has a start date');
    }

    public function test_a_schedule_with_an_end_date_ticks_the_end_task(): void
    {
        $workOrder = $this->thmpWorkOrder();
        $task = $this->templateTask($workOrder, 'schedule_end_set', 'Fill in Projected Service End Date');
        $this->schedule($workOrder, ['scheduled_end_date' => '2026-10-13 17:00:00']);

        $this->tick($workOrder);

        $this->assertTicked($task, 'Service Schedule has an end date');
    }

    public function test_a_schedule_without_an_end_date_leaves_the_end_task_alone(): void
    {
        $workOrder = $this->thmpWorkOrder(['scheduled_end_date' => null]);
        $start = $this->templateTask($workOrder, 'schedule_start_set');
        $end = $this->templateTask($workOrder, 'schedule_end_set', 'Fill in Projected Service End Date');
        $this->schedule($workOrder);

        $this->tick($workOrder);

        $this->assertTicked($start, 'start date');
        $this->assertUntouched($end);
    }

    public function test_a_cancelled_schedule_does_not_count(): void
    {
        $workOrder = $this->thmpWorkOrder();
        $task = $this->templateTask($workOrder, 'schedule_start_set');
        $this->schedule($workOrder, ['status' => 'cancelled']);

        $this->assertSame(0, $this->tick($workOrder));
        $this->assertUntouched($task);
    }

    // --- tenant contacted ----------------------------------------------

    public function test_the_appointment_text_ticks_the_contact_tenant_task(): void
    {
        $workOrder = $this->thmpWorkOrder();
        $task = $this->templateTask($workOrder, 'tenant_contacted', 'Contact Tenant to Schedule Appointment between  2 and 3 days from today');
        $this->schedule($workOrder, ['tenant_notified_at' => now()]);

        $this->tick($workOrder);

        $this->assertTicked($task, 'Tenant texted about the appointment');
    }

    public function test_an_outbound_tenant_text_after_the_task_was_created_counts(): void
    {
        $workOrder = $this->thmpWorkOrder();
        $task = $this->templateTask($workOrder, 'tenant_contacted', 'Contact Tenant to Schedule Appointment');

        Conversation::query()->create([
            'message' => 'We have assigned Texas Home Maintenance Pros to handle the repairs.',
            'conversation_type' => 'tenant',
            'sender_number' => '+15125550000',
            'receiver_number' => '+15125551111',
            'work_order_id' => $workOrder->id,
            'is_read' => true,
        ]);

        $this->tick($workOrder);

        $this->assertTicked($task, 'Tenant texted');
    }

    public function test_an_incoming_tenant_text_or_an_older_one_does_not_count(): void
    {
        $workOrder = $this->thmpWorkOrder();
        $task = $this->templateTask($workOrder, 'tenant_contacted', 'Contact Tenant to Schedule Appointment');

        // Inbound (is_read false is the direction marker).
        Conversation::query()->create([
            'message' => 'When are you coming?',
            'conversation_type' => 'tenant',
            'sender_number' => '+15125551111',
            'receiver_number' => '+15125550000',
            'work_order_id' => $workOrder->id,
            'is_read' => false,
        ]);
        // Outbound, but from before this task existed.
        Conversation::query()->create([
            'message' => 'Your request was received.',
            'conversation_type' => 'tenant',
            'sender_number' => '+15125550000',
            'receiver_number' => '+15125551111',
            'work_order_id' => $workOrder->id,
            'is_read' => true,
            'created_at' => now()->subDays(2),
        ]);

        $this->assertSame(0, $this->tick($workOrder));
        $this->assertUntouched($task);
    }

    // --- Jobber job completed ------------------------------------------

    public function test_a_completed_jobber_job_ticks_the_repair_task_with_yes(): void
    {
        $workOrder = $this->thmpWorkOrder(['service_status_id' => $this->scheduled->id]);
        $task = $this->optionalRepairTask($workOrder);
        $this->jobberJob($workOrder, ['completed_at' => now()->subHour()]);
        $this->techNote($workOrder, 'Replaced the capacitor, unit is cooling again. Job done.');
        $this->judgeSaysDone();

        $this->tick($workOrder);

        $this->assertTicked($task, 'Jobber job completed');
        $this->assertStringContainsString('tech note says done (92%)', $task->fresh()->auto_complete_reason);
        $this->assertSame('Yes', $task->fresh()->option);

        RepairCompletionJudgeAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'Replaced the capacitor')
            && str_contains($prompt->prompt, 'Emanuel Hall'));
    }

    public function test_a_completed_visit_counts_even_before_the_job_is_marked_done(): void
    {
        $workOrder = $this->thmpWorkOrder(['service_status_id' => $this->scheduled->id]);
        $task = $this->optionalRepairTask($workOrder);
        $job = $this->jobberJob($workOrder);
        $this->visit($job, ['completed_at' => now()->subHour()]);
        $this->techNote($workOrder, 'Done, tested, all good.');
        $this->judgeSaysDone();

        $this->tick($workOrder);

        $this->assertTicked($task, 'Jobber job completed');
    }

    public function test_an_open_jobber_job_leaves_the_repair_task_alone(): void
    {
        $workOrder = $this->thmpWorkOrder(['service_status_id' => $this->scheduled->id]);
        $task = $this->optionalRepairTask($workOrder);
        $job = $this->jobberJob($workOrder);
        $this->visit($job);
        $this->techNote($workOrder, 'Done, tested, all good.');
        $this->judgeSaysDone();

        $this->assertSame(0, $this->tick($workOrder));
        $this->assertUntouched($task);
        RepairCompletionJudgeAgent::assertNeverPrompted();
    }

    // --- the repair judge --------------------------------------------------

    public function test_a_completed_job_with_no_tech_note_yet_is_left_for_a_person(): void
    {
        $workOrder = $this->thmpWorkOrder(['service_status_id' => $this->scheduled->id]);
        $task = $this->optionalRepairTask($workOrder);
        $this->jobberJob($workOrder, ['completed_at' => now()->subHour()]);
        $this->judgeSaysDone();

        $this->assertSame(0, $this->tick($workOrder));
        $this->assertUntouched($task);
        RepairCompletionJudgeAgent::assertNeverPrompted();
    }

    public function test_a_note_saying_the_tech_must_return_holds_the_tick(): void
    {
        $workOrder = $this->thmpWorkOrder(['service_status_id' => $this->scheduled->id]);
        $task = $this->optionalRepairTask($workOrder);
        $this->jobberJob($workOrder, ['completed_at' => now()->subHour()]);
        $this->techNote($workOrder, 'Temporary fix, need to come back with the new motor next week.');
        RepairCompletionJudgeAgent::fake([[
            'completed' => false,
            'confidence' => 95,
            'reason' => 'The note says a return visit with a new motor is needed.',
        ]]);

        $this->assertSame(0, $this->tick($workOrder));
        $this->assertUntouched($task);
        $this->assertSame($this->scheduled->id, $workOrder->fresh()->service_status_id);
    }

    public function test_a_verdict_below_the_confidence_bar_holds_the_tick(): void
    {
        $workOrder = $this->thmpWorkOrder(['service_status_id' => $this->scheduled->id]);
        $task = $this->optionalRepairTask($workOrder);
        $this->jobberJob($workOrder, ['completed_at' => now()->subHour()]);
        $this->techNote($workOrder, 'Looked at it.');
        $this->judgeSaysDone(60);

        $this->assertSame(0, $this->tick($workOrder));
        $this->assertUntouched($task);
    }

    public function test_an_ai_failure_holds_the_tick(): void
    {
        $workOrder = $this->thmpWorkOrder(['service_status_id' => $this->scheduled->id]);
        $task = $this->optionalRepairTask($workOrder);
        $this->jobberJob($workOrder, ['completed_at' => now()->subHour()]);
        $this->techNote($workOrder, 'Repair complete.');
        RepairCompletionJudgeAgent::fake([fn () => throw new RuntimeException('provider timed out')]);

        $this->assertSame(0, $this->tick($workOrder));
        $this->assertUntouched($task);
    }

    public function test_no_ai_provider_holds_the_tick(): void
    {
        config(['ai.providers.openai.key' => null]);

        $workOrder = $this->thmpWorkOrder(['service_status_id' => $this->scheduled->id]);
        $task = $this->optionalRepairTask($workOrder);
        $this->jobberJob($workOrder, ['completed_at' => now()->subHour()]);
        $this->techNote($workOrder, 'Repair complete.');
        $this->judgeSaysDone();

        $this->assertSame(0, $this->tick($workOrder));
        $this->assertUntouched($task);
        RepairCompletionJudgeAgent::assertNeverPrompted();
    }

    public function test_the_judge_reads_the_newest_notes_and_the_work_order(): void
    {
        $workOrder = $this->thmpWorkOrder(['service_status_id' => $this->scheduled->id, 'description' => 'AC not cooling upstairs']);
        $this->optionalRepairTask($workOrder);
        $this->jobberJob($workOrder, ['completed_at' => '2026-10-12 15:00:00']);
        $this->techNote($workOrder, 'Arrived, diagnosing.', '2026-10-12 13:00:00');
        $this->techNote($workOrder, 'Capacitor replaced, cooling at 58F supply.', '2026-10-12 15:05:00');
        $this->judgeSaysDone();

        $this->tick($workOrder);

        RepairCompletionJudgeAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'AC not cooling upstairs')
            && str_contains($prompt->prompt, 'Capacitor replaced')
            && str_contains($prompt->prompt, 'Arrived, diagnosing')
            && strpos($prompt->prompt, 'Capacitor replaced') < strpos($prompt->prompt, 'Arrived, diagnosing'));
    }

    public function test_with_the_judge_switched_off_the_jobber_completion_decides_alone(): void
    {
        config(['services.ai.repair_judge' => false]);

        $workOrder = $this->thmpWorkOrder(['service_status_id' => $this->scheduled->id]);
        $task = $this->optionalRepairTask($workOrder);
        $this->jobberJob($workOrder, ['completed_at' => now()->subHour()]);
        $this->judgeSaysDone();

        $this->assertSame(1, $this->tick($workOrder));
        $this->assertTicked($task, 'Jobber job completed');
        RepairCompletionJudgeAgent::assertNeverPrompted();
    }

    // --- photos ----------------------------------------------------------

    public function test_a_before_photo_on_the_work_order_ticks_the_before_task(): void
    {
        $workOrder = $this->thmpWorkOrder(['service_status_id' => $this->scheduled->id]);
        $task = $this->templateTask($workOrder, 'before_photo_added', 'Upload "before pictures of problem"');
        $this->attachment($workOrder, 'before');

        $this->tick($workOrder);

        $this->assertTicked($task, 'before photo');
    }

    public function test_a_jobber_photo_taken_before_the_visit_was_completed_is_a_before_photo(): void
    {
        $workOrder = $this->thmpWorkOrder(['service_status_id' => $this->scheduled->id]);
        $before = $this->templateTask($workOrder, 'before_photo_added', 'Upload "before pictures of problem"');
        $after = $this->templateTask($workOrder, 'after_photo_added', 'After Completing Service - take "After Photos in App"');
        $job = $this->jobberJob($workOrder);
        $this->visit($job, ['completed_at' => '2026-10-12 15:00:00']);
        $this->jobberPhoto($workOrder, '2026-10-12 13:30:00');

        $this->tick($workOrder);

        $this->assertTicked($before, 'before photo');
        $this->assertUntouched($after);
    }

    public function test_a_jobber_photo_taken_after_the_visit_was_completed_is_an_after_photo(): void
    {
        $workOrder = $this->thmpWorkOrder(['service_status_id' => $this->scheduled->id]);
        $before = $this->templateTask($workOrder, 'before_photo_added', 'Upload "before pictures of problem"');
        $after = $this->templateTask($workOrder, 'after_photo_added', 'After Completing Service - take "After Photos in App"');
        $job = $this->jobberJob($workOrder);
        $this->visit($job, ['completed_at' => '2026-10-12 15:00:00']);
        $this->jobberPhoto($workOrder, '2026-10-12 15:20:00');

        $this->tick($workOrder);

        $this->assertTicked($after, 'after photo');
        $this->assertUntouched($before);
    }

    public function test_a_jobber_photo_with_no_completion_yet_is_a_before_photo_only(): void
    {
        $workOrder = $this->thmpWorkOrder(['service_status_id' => $this->scheduled->id]);
        $before = $this->templateTask($workOrder, 'before_photo_added', 'Upload "before pictures of problem"');
        $after = $this->templateTask($workOrder, 'after_photo_added', 'After Completing Service - take "After Photos in App"');
        $this->jobberJob($workOrder);
        $this->jobberPhoto($workOrder, '2026-10-12 13:30:00');

        $this->tick($workOrder);

        $this->assertTicked($before, 'before photo');
        $this->assertUntouched($after);
    }

    public function test_an_after_photo_from_the_vendor_portal_ticks_the_after_task(): void
    {
        $workOrder = $this->thmpWorkOrder(['service_status_id' => $this->scheduled->id]);
        $task = $this->templateTask($workOrder, 'after_photo_added', 'After Completing Service - take "After Photos in App"');
        $this->attachment($workOrder, 'after');

        $this->tick($workOrder);

        $this->assertTicked($task, 'after photo');
    }

    // --- guard rails -----------------------------------------------------

    public function test_a_not_changed_line_only_ticks_the_box(): void
    {
        $this->mock(PropertyWareService::class)->shouldNotReceive('updateServiceStatus');

        $workOrder = $this->thmpWorkOrder();
        $start = $this->templateTask($workOrder, 'schedule_start_set');
        $other = $this->templateTask($workOrder, null, 'Call the owner');
        $this->schedule($workOrder);

        $this->tick($workOrder);

        $this->assertTicked($start, 'start date');
        $this->assertUntouched($other);
        $this->assertSame($this->waiting->id, $workOrder->fresh()->service_status_id);
        $this->assertSame(2, WorkOrderTask::query()->where('work_order_id', $workOrder->id)->count());
    }

    /**
     * The template line the crew ticks to reach Scheduled: the automation
     * moves the work order the same way — status, PropertyWare, and the
     * Scheduled template's tasks generated on top of the open ones.
     */
    public function test_a_line_that_moves_the_status_moves_it_like_a_manual_tick(): void
    {
        $this->mock(PropertyWareService::class)->shouldReceive('updateServiceStatus')->once()->andReturn(true);

        $workOrder = $this->thmpWorkOrder();
        $endTask = $this->templateTask($workOrder, 'schedule_end_set', 'Fill in Projected Service End Date', [
            'next_service_status_id' => $this->scheduled->id,
        ]);
        $other = $this->templateTask($workOrder, null, 'Call the owner');
        $this->scheduledTemplateLine('Have you Completed the Repair');
        $this->schedule($workOrder, ['scheduled_end_date' => '2026-10-13 17:00:00']);

        $this->tick($workOrder);

        $this->assertTicked($endTask, 'end date');
        $this->assertUntouched($other);
        $this->assertSame($this->scheduled->id, $workOrder->fresh()->service_status_id);
        $this->assertDatabaseHas('work_order_tasks', [
            'work_order_id' => $workOrder->id,
            'description' => 'Have you Completed the Repair',
            'status' => 'pending',
        ]);

        $row = Activity::query()->where('log_name', 'task_automation')->firstOrFail();
        $this->assertSame('Scheduled', $row->properties['moved_to']);
    }

    public function test_the_repair_answered_yes_moves_on_and_clears_the_rest_like_a_manual_yes(): void
    {
        $this->mock(PropertyWareService::class)->shouldReceive('updateServiceStatus')->once()->andReturn(true);

        $workOrder = $this->thmpWorkOrder(['service_status_id' => $this->scheduled->id]);
        $repair = $this->optionalRepairTask($workOrder);
        $leftover = $this->templateTask($workOrder, null, 'Call the owner');
        $this->jobberJob($workOrder, ['completed_at' => now()->subHour()]);
        $this->techNote($workOrder, 'Repair complete.');
        $this->judgeSaysDone();

        $this->tick($workOrder);

        $this->assertTicked($repair, 'Jobber job completed');
        $this->assertSame('Yes', $repair->fresh()->option);
        $this->assertSame($this->serviceCompleted->id, $workOrder->fresh()->service_status_id);
        $this->assertSoftDeleted('work_order_tasks', ['id' => $leftover->id]);
    }

    /**
     * The photo lines sit beside the repair question on the Scheduled
     * template. Its Yes clears whatever is still pending, so the photos
     * must be ticked first or they would vanish instead.
     */
    public function test_photo_lines_are_ticked_before_the_repair_answer_clears_the_rest(): void
    {
        $this->mock(PropertyWareService::class)->shouldReceive('updateServiceStatus')->once()->andReturn(true);

        $workOrder = $this->thmpWorkOrder(['service_status_id' => $this->scheduled->id]);
        $repair = $this->optionalRepairTask($workOrder);
        $before = $this->templateTask($workOrder, 'before_photo_added', 'Upload "before pictures of problem"');
        $after = $this->templateTask($workOrder, 'after_photo_added', 'After Completing Service - take "After Photos in App"');
        $this->jobberJob($workOrder, ['completed_at' => now()->subHour()]);
        $this->techNote($workOrder, 'Repair complete.');
        $this->judgeSaysDone();
        $this->attachment($workOrder, 'before');
        $this->attachment($workOrder, 'after');

        $this->assertSame(3, $this->tick($workOrder));

        $this->assertTicked($before, 'before photo');
        $this->assertTicked($after, 'after photo');
        $this->assertTicked($repair, 'Jobber job completed');
        $this->assertSame($this->serviceCompleted->id, $workOrder->fresh()->service_status_id);
    }

    public function test_tasks_generated_by_a_status_move_are_checked_in_the_same_run(): void
    {
        $this->mock(PropertyWareService::class)->shouldReceive('updateServiceStatus')->once()->andReturn(true);

        $workOrder = $this->thmpWorkOrder();
        $endTask = $this->templateTask($workOrder, 'schedule_end_set', 'Fill in Projected Service End Date', [
            'next_service_status_id' => $this->scheduled->id,
        ]);
        $this->scheduledTemplateLine('Upload "before pictures of problem"', 'before_photo_added');
        $this->schedule($workOrder, ['scheduled_end_date' => '2026-10-13 17:00:00']);
        $this->attachment($workOrder, 'before');

        $this->assertSame(2, $this->tick($workOrder));

        $this->assertTicked($endTask, 'end date');
        $this->assertSame($this->scheduled->id, $workOrder->fresh()->service_status_id);
        $this->assertDatabaseHas('work_order_tasks', [
            'work_order_id' => $workOrder->id,
            'description' => 'Upload "before pictures of problem"',
            'status' => 'completed',
        ]);
    }

    public function test_a_work_order_without_thmp_is_left_alone(): void
    {
        $vendor = Vendor::query()->create([
            'propertyware_id' => 'V-Acme',
            'name' => 'Acme Plumbing',
            'vendor_type' => 'General',
            'is_active' => true,
            'user_id' => User::factory()->create()->id,
        ]);
        $workOrder = WorkOrder::factory()->create(['service_status_id' => $this->waiting->id, 'status' => 'Open']);
        $workOrder->vendors()->attach($vendor->id);
        $task = $this->templateTask($workOrder, 'schedule_start_set');
        ServiceSchedule::query()->create([
            'title' => 'Service Schedule',
            'scheduled_date' => '2026-10-12 09:00:00',
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
        ]);

        $this->assertSame(0, $this->tick($workOrder));
        $this->assertUntouched($task);
    }

    public function test_a_work_order_with_a_jobber_job_but_no_thmp_vendor_row_is_in_scope(): void
    {
        $workOrder = WorkOrder::factory()->create(['service_status_id' => $this->waiting->id, 'status' => 'Open', 'work_order_no' => 44501]);
        $this->jobberJob($workOrder, ['completed_at' => now()]);
        $this->techNote($workOrder, 'Repair complete.');
        $this->judgeSaysDone();
        $task = $this->optionalRepairTask($workOrder);

        $this->assertSame(1, $this->tick($workOrder));
        $this->assertTicked($task, 'Jobber job completed');
    }

    public function test_a_closed_work_order_is_left_alone(): void
    {
        $workOrder = $this->thmpWorkOrder(['status' => 'Closed']);
        $task = $this->templateTask($workOrder, 'schedule_start_set');
        $this->schedule($workOrder);

        $this->assertSame(0, $this->tick($workOrder));
        $this->assertUntouched($task);
    }

    public function test_the_gate_turns_it_off(): void
    {
        config(['services.tasks.auto_complete_enabled' => false]);

        $workOrder = $this->thmpWorkOrder();
        $task = $this->templateTask($workOrder, 'schedule_start_set');
        $this->schedule($workOrder);

        $this->assertSame(0, $this->tick($workOrder));
        $this->assertUntouched($task);
    }

    public function test_a_second_run_changes_nothing_and_an_undo_sticks(): void
    {
        $workOrder = $this->thmpWorkOrder();
        $task = $this->templateTask($workOrder, 'schedule_start_set');
        $this->schedule($workOrder);

        $this->assertSame(1, $this->tick($workOrder));
        $this->assertSame(0, $this->tick($workOrder));

        // A person un-ticks it: the evidence is still there, but a box a
        // person opened on purpose is never re-ticked.
        $task->fresh()->update(['status' => 'pending']);

        $this->assertSame(0, $this->tick($workOrder));
        $this->assertSame('pending', $task->fresh()->status);
        $this->assertSame(1, Activity::query()->where('log_name', 'task_automation')->count());
    }

    public function test_each_tick_is_written_to_the_activity_log(): void
    {
        $workOrder = $this->thmpWorkOrder();
        $task = $this->templateTask($workOrder, 'schedule_start_set');
        $this->schedule($workOrder);

        $this->tick($workOrder);

        $row = Activity::query()->where('log_name', 'task_automation')->firstOrFail();

        $this->assertSame('task.auto_completed', $row->event);
        $this->assertSame($workOrder->id, (int) $row->subject_id);
        $this->assertSame($task->id, (int) $row->properties['work_order_task_id']);
        $this->assertSame('schedule_start_set', $row->properties['trigger']);
    }

    // --- entry points ----------------------------------------------------

    public function test_saving_a_schedule_ticks_the_date_tasks_at_once(): void
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $workOrder = $this->thmpWorkOrder();
        $start = $this->templateTask($workOrder, 'schedule_start_set');
        $end = $this->templateTask($workOrder, 'schedule_end_set', 'Fill in Projected Service End Date');

        $this->actingAs($admin)->post(route('work_order.service_schedule.create'), [
            'title' => 'Service Schedule for 44500',
            'date' => '2026-10-12',
            'end_date' => '2026-10-13',
            'vendor_id' => $workOrder->vendors()->first()->id,
            'work_order_id' => $workOrder->id,
            'notify_tenant' => false,
        ])->assertRedirect();

        $this->assertTicked($start, 'start date');
        $this->assertTicked($end, 'end date');
    }

    public function test_the_sweep_command_ticks_every_thmp_work_order(): void
    {
        $one = $this->thmpWorkOrder(['work_order_no' => 44510]);
        $two = $this->thmpWorkOrder(['work_order_no' => 44511]);
        $taskOne = $this->templateTask($one, 'schedule_start_set');
        $taskTwo = $this->templateTask($two, 'schedule_start_set');
        $this->schedule($one);
        $this->schedule($two);

        $this->artisan('tasks:auto-complete-thmp')
            ->expectsOutputToContain('2 task(s) ticked on 2 work order(s)')
            ->assertExitCode(0);

        $this->assertTicked($taskOne, 'start date');
        $this->assertTicked($taskTwo, 'start date');
    }

    public function test_the_sweep_dry_run_writes_nothing(): void
    {
        $workOrder = $this->thmpWorkOrder();
        $task = $this->templateTask($workOrder, 'schedule_start_set');
        $this->schedule($workOrder);

        $this->artisan('tasks:auto-complete-thmp', ['--dry-run' => true])
            ->expectsOutputToContain('#44500: would tick "Fill in Scheduled Start Date"')
            ->assertExitCode(0);

        $this->assertUntouched($task);
        $this->assertSame(0, Activity::query()->where('log_name', 'task_automation')->count());
    }

    public function test_the_sweep_can_target_one_work_order(): void
    {
        $one = $this->thmpWorkOrder(['work_order_no' => 44510]);
        $two = $this->thmpWorkOrder(['work_order_no' => 44511]);
        $taskOne = $this->templateTask($one, 'schedule_start_set');
        $taskTwo = $this->templateTask($two, 'schedule_start_set');
        $this->schedule($one);
        $this->schedule($two);

        $this->artisan('tasks:auto-complete-thmp', ['--work-order' => $two->id])->assertExitCode(0);

        $this->assertUntouched($taskOne);
        $this->assertTicked($taskTwo, 'start date');
    }

    public function test_the_sweep_says_so_when_the_gate_is_off(): void
    {
        config(['services.tasks.auto_complete_enabled' => false]);

        $this->artisan('tasks:auto-complete-thmp')
            ->expectsOutputToContain('TASK_AUTO_COMPLETE_ENABLED')
            ->assertExitCode(0);
    }
}
