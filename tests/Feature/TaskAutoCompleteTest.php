<?php

namespace Tests\Feature;

use App\Models\Attachments;
use App\Models\Conversation;
use App\Models\Jobber;
use App\Models\JobberClient;
use App\Models\JobberProperty;
use App\Models\JobberVisit;
use App\Models\ServiceSchedule;
use App\Models\ServiceStatus;
use App\Models\Task;
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
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Service THMP asked (2026-10-08) for six of their checklist tasks to be
 * ticked by the system once the thing they describe has happened. Earl's
 * rule: tick the box only — the work order's status never moves and nothing
 * is pushed to PropertyWare.
 */
class TaskAutoCompleteTest extends TestCase
{
    use RefreshDatabase;

    private ServiceStatus $waiting;

    private ServiceStatus $scheduled;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.tasks.auto_complete_enabled' => true]);

        $this->waiting = ServiceStatus::query()->create(['name' => 'Assigned - Waiting on Scheduling', 'description' => 'x']);
        $this->scheduled = ServiceStatus::query()->create(['name' => 'Scheduled', 'description' => 'x']);
    }

    private function thmpVendor(): Vendor
    {
        return Vendor::query()->firstOrCreate(['propertyware_id' => 'V-THMP'], [
            'name' => Vendor::THMP_NAME,
            'vendor_type' => 'General',
            'is_active' => true,
            'user_id' => User::factory()->create()->id,
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
        $template = TaskTemplate::query()->create([
            'name' => 'Template '.$name,
            'current_service_status_id' => $workOrder->service_status_id,
        ]);

        $task = Task::query()->create(array_merge([
            'name' => $name,
            'due_date' => 'same day',
            'type' => 'Vendor',
            'task_template_id' => $template->id,
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
        $task = $this->templateTask($workOrder, 'jobber_job_completed', 'Have you Completed the Repair', ['is_optional' => true]);
        $this->jobberJob($workOrder, ['completed_at' => now()->subHour()]);

        $this->tick($workOrder);

        $this->assertTicked($task, 'Jobber job completed');
        $this->assertSame('Yes', $task->fresh()->option);
    }

    public function test_a_completed_visit_counts_even_before_the_job_is_marked_done(): void
    {
        $workOrder = $this->thmpWorkOrder(['service_status_id' => $this->scheduled->id]);
        $task = $this->templateTask($workOrder, 'jobber_job_completed', 'Have you Completed the Repair', ['is_optional' => true]);
        $job = $this->jobberJob($workOrder);
        $this->visit($job, ['completed_at' => now()->subHour()]);

        $this->tick($workOrder);

        $this->assertTicked($task, 'Jobber job completed');
    }

    public function test_an_open_jobber_job_leaves_the_repair_task_alone(): void
    {
        $workOrder = $this->thmpWorkOrder(['service_status_id' => $this->scheduled->id]);
        $task = $this->templateTask($workOrder, 'jobber_job_completed', 'Have you Completed the Repair', ['is_optional' => true]);
        $job = $this->jobberJob($workOrder);
        $this->visit($job);

        $this->assertSame(0, $this->tick($workOrder));
        $this->assertUntouched($task);
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

    public function test_ticking_never_moves_the_status_or_touches_propertyware(): void
    {
        $this->mock(PropertyWareService::class)->shouldNotReceive('updateServiceStatus');

        $workOrder = $this->thmpWorkOrder();
        $endTask = $this->templateTask($workOrder, 'schedule_end_set', 'Fill in Projected Service End Date', [
            'next_service_status_id' => $this->scheduled->id,
        ]);
        $other = $this->templateTask($workOrder, null, 'Call the owner');
        $this->schedule($workOrder, ['scheduled_end_date' => '2026-10-13 17:00:00']);

        $this->tick($workOrder);

        $this->assertTicked($endTask, 'end date');
        $this->assertUntouched($other);
        $this->assertSame($this->waiting->id, $workOrder->fresh()->service_status_id);
        $this->assertSame(2, WorkOrderTask::query()->where('work_order_id', $workOrder->id)->count());
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
        $task = $this->templateTask($workOrder, 'jobber_job_completed', 'Have you Completed the Repair', ['is_optional' => true]);

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
