<?php

namespace Tests\Feature;

use App\Ai\Agents\TenantCompletionJudgeAgent;
use App\Jobs\SendConversationMessageJob;
use App\Models\AppSetting;
use App\Models\Attachments;
use App\Models\Conversation;
use App\Models\Invoice;
use App\Models\Jobber;
use App\Models\JobberClient;
use App\Models\JobberJobInvoice;
use App\Models\JobberProperty;
use App\Models\ServiceStatus;
use App\Models\Task;
use App\Models\TaskDetail;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Models\WorkOrderTask;
use App\Services\PropertyWareService;
use App\Services\TaskAutoCompleteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The coordinator's "Service Completed - Call Tenant for Followup"
 * checklist (Earl, 2026-10-14): the cost line ticks on an invoice, the
 * photo-contact line on the photos or a staff text to the vendor, the
 * tenant line on a tenant reply the AI reads as satisfied, and the closing
 * Yes/No line answers Yes once every other line is ticked. The system
 * sends no text for any of them.
 */
class WocChecklistAutoCompleteTest extends TestCase
{
    use RefreshDatabase;

    private ServiceStatus $serviceCompleted;

    private ServiceStatus $completedVerified;

    private ServiceStatus $notChanged;

    private TaskTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.tasks.auto_complete_enabled' => true,
            'services.tasks.vendor_photo_request_sms' => true,
            'services.tasks.vendor_photo_request_delay_hours' => 24,
            'services.twilio.maintenance_number' => '+12813787957',
            'services.ai.tenant_completion_judge' => true,
            'services.ai.tenant_completion_min_confidence' => 80,
            'ai.providers.openai.key' => 'test-key',
        ]);

        $this->serviceCompleted = ServiceStatus::query()->create(['name' => 'Service Completed - Call Tenant for Followup', 'description' => 'x']);
        $this->completedVerified = ServiceStatus::query()->create(['name' => 'Completed - Verified - Updating Owner', 'description' => 'x']);
        $this->notChanged = ServiceStatus::query()->create(['name' => 'Not Changed', 'description' => 'x']);

        $this->template = TaskTemplate::query()->create([
            'name' => 'Service Completed - Call Tenant for Followup - Non Emergency',
            'current_service_status_id' => $this->serviceCompleted->id,
            'is_current_service_status_emergency' => false,
            'next_service_status_id' => $this->completedVerified->id,
            'is_next_service_status_emergency' => false,
        ]);
    }

    /** A work order in Service Completed with an outside vendor on it. */
    private function workOrder(array $overrides = []): WorkOrder
    {
        Role::findOrCreate('vendor');

        $vendor = Vendor::query()->firstOrCreate(['propertyware_id' => 'V-Acme'], [
            'name' => 'Acme Plumbing',
            'vendor_type' => 'General',
            'is_active' => true,
            'user_id' => User::factory()->create()->assignRole('vendor')->id,
        ]);

        $workOrder = WorkOrder::factory()->create(array_merge([
            'service_status_id' => $this->serviceCompleted->id,
            'work_order_no' => 44700,
            'status' => 'Open',
            'description' => 'Kitchen faucet leaking under the sink',
        ], $overrides));

        $workOrder->vendors()->attach($vendor->id);

        return $workOrder;
    }

    /**
     * A Woc line on the Service Completed template, as a task row on the
     * work order created an hour ago.
     *
     * @param  array<string, mixed>  $taskOverrides
     */
    private function line(WorkOrder $workOrder, ?string $trigger, string $name, array $taskOverrides = []): WorkOrderTask
    {
        $task = Task::query()->create(array_merge([
            'name' => $name,
            'due_date' => 'same day',
            'type' => 'Woc',
            'task_template_id' => $this->template->id,
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

    private function costLine(WorkOrder $workOrder): WorkOrderTask
    {
        return $this->line($workOrder, 'cost_confirmed', 'Confirm Cost of Service with Vendor');
    }

    private function photoContactLine(WorkOrder $workOrder): WorkOrderTask
    {
        return $this->line($workOrder, 'photos_on_file_or_vendor_asked', 'If Pictures Have not been uploaded contact vendor to upload');
    }

    private function tenantLine(WorkOrder $workOrder): WorkOrderTask
    {
        return $this->line($workOrder, 'tenant_confirmed_completion', 'Contact Tenant to Confirm Completion to Tenant Satisfaction');
    }

    /** The Yes/No line whose Yes moves the work order to Completed - Verified. */
    private function checklistLine(WorkOrder $workOrder): WorkOrderTask
    {
        $row = $this->line($workOrder, 'checklist_otherwise_complete', 'Have All Items in Checklist been completed?', [
            'is_optional' => true,
            'next_service_status_id' => null,
        ]);

        TaskDetail::query()->create(['task_id' => $row->task_id, 'task_for' => 'Yes', 'task_service_status_id' => $this->completedVerified->id, 'is_task_service_status_emergency' => false]);
        TaskDetail::query()->create(['task_id' => $row->task_id, 'task_for' => 'No', 'task_service_status_id' => $this->notChanged->id, 'is_task_service_status_emergency' => false]);

        return $row;
    }

    private function invoice(WorkOrder $workOrder, array $overrides = []): Invoice
    {
        return Invoice::query()->create(array_merge([
            'title' => 'Acme invoice',
            'filename' => 'invoices/acme.pdf',
            'filetype' => 'application/pdf',
            'amount' => 185.5,
            'work_order_id' => $workOrder->id,
            'vendor_id' => $workOrder->vendors()->first()->id,
        ], $overrides));
    }

    private function jobberJob(WorkOrder $workOrder): Jobber
    {
        $client = JobberClient::query()->create(['jobber_id' => 'client-'.$workOrder->id, 'name' => 'Client', 'jobber_web_uri' => 'https://example.test']);
        $property = JobberProperty::query()->create(['jobber_id' => 'property-'.$workOrder->id, 'jobber_client_id' => $client->id]);

        $job = Jobber::query()->create([
            'jobber_id' => 'job-'.$workOrder->id,
            'title' => 'Repair - #'.$workOrder->work_order_no,
            'job_status' => 'active',
            'jobber_client_id' => $client->id,
            'jobber_property_id' => $property->id,
        ]);

        $workOrder->update(['jobber_job_gid' => $job->jobber_id]);

        return $job;
    }

    private function attachment(WorkOrder $workOrder, string $type): Attachments
    {
        return Attachments::query()->create([
            'title' => 'Photo',
            'filename' => 'attachments/photo.jpg',
            'filetype' => 'image/jpeg',
            'type' => $type,
            'work_order_id' => $workOrder->id,
            'user_id' => User::factory()->create()->id,
        ]);
    }

    /** is_read is the direction marker: true = we sent it, false = they wrote it. */
    private function text(WorkOrder $workOrder, string $type, bool $outbound, string $message, array $overrides = []): Conversation
    {
        return Conversation::query()->create(array_merge([
            'message' => $message,
            'conversation_type' => $type,
            'sender_number' => $outbound ? '+15125550000' : '+15125551111',
            'receiver_number' => $outbound ? '+15125551111' : '+15125550000',
            'work_order_id' => $workOrder->id,
            'is_read' => $outbound,
        ], $overrides));
    }

    private function judgeSays(bool $satisfied, int $confidence = 91, string $reason = 'The tenant says the faucet no longer leaks and thanked the plumber.'): void
    {
        TenantCompletionJudgeAgent::fake([[
            'satisfied' => $satisfied,
            'confidence' => $confidence,
            'reason' => $reason,
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

    // --- the four keys exist ------------------------------------------------

    public function test_the_four_coordinator_triggers_are_dropdown_options(): void
    {
        $values = array_column(TaskAutoCompleteService::options(), 'value');

        $this->assertSame([
            'tenant_contacted', 'schedule_start_set', 'schedule_end_set', 'repair_completed',
            'before_photo_added', 'after_photo_added',
            'cost_confirmed', 'photos_on_file_or_vendor_asked', 'tenant_confirmed_completion', 'checklist_otherwise_complete',
        ], $values);
    }

    // --- cost of service --------------------------------------------------

    public function test_an_uploaded_invoice_ticks_the_cost_line(): void
    {
        $workOrder = $this->workOrder();
        $line = $this->costLine($workOrder);
        $this->invoice($workOrder);

        $this->tick($workOrder);

        $this->assertTicked($line, 'Invoice on file ($185.50');
    }

    public function test_an_invoice_synced_from_jobber_ticks_the_cost_line(): void
    {
        $workOrder = $this->workOrder();
        $line = $this->costLine($workOrder);
        $job = $this->jobberJob($workOrder);
        JobberJobInvoice::query()->create([
            'jobber_job_id' => $job->id,
            'title' => 'Jobber invoice',
            'amount' => 240,
            'filename' => 'jobber-invoices/acme.pdf',
        ]);

        $this->tick($workOrder);

        $this->assertTicked($line, 'Jobber invoice on file ($240.00');
    }

    public function test_an_archived_invoice_or_a_zero_jobber_invoice_does_not_count(): void
    {
        $workOrder = $this->workOrder();
        $line = $this->costLine($workOrder);
        $this->invoice($workOrder, ['archived_at' => now()]);
        $job = $this->jobberJob($workOrder);
        JobberJobInvoice::query()->create([
            'jobber_job_id' => $job->id,
            'title' => 'Placeholder',
            'amount' => 0,
            'filename' => 'jobber-invoices/empty.pdf',
        ]);

        $this->tick($workOrder);

        $this->assertUntouched($line);
    }

    public function test_no_invoice_leaves_the_cost_line_alone(): void
    {
        $workOrder = $this->workOrder();
        $line = $this->costLine($workOrder);

        $this->assertSame(0, $this->tick($workOrder));
        $this->assertUntouched($line);
    }

    // --- photos or vendor contacted ---------------------------------------

    public function test_before_and_after_photos_on_file_tick_the_photo_contact_line(): void
    {
        $workOrder = $this->workOrder();
        $line = $this->photoContactLine($workOrder);
        $this->attachment($workOrder, 'before');
        $this->attachment($workOrder, 'after');

        $this->tick($workOrder);

        $this->assertTicked($line, 'Before and after photos are on the work order');
    }

    public function test_a_before_photo_alone_does_not_tick_the_photo_contact_line(): void
    {
        $workOrder = $this->workOrder();
        $line = $this->photoContactLine($workOrder);
        $this->attachment($workOrder, 'before');

        $this->tick($workOrder);

        $this->assertUntouched($line);
    }

    public function test_a_staff_text_to_the_vendor_after_the_line_appeared_ticks_it(): void
    {
        $workOrder = $this->workOrder();
        $line = $this->photoContactLine($workOrder);
        $this->text($workOrder, 'vendor', true, 'Please upload the before and after photos for WO 44700.');

        $this->tick($workOrder);

        $this->assertTicked($line, 'Vendor texted by staff');
    }

    public function test_a_vendor_text_before_the_line_appeared_or_from_the_vendor_does_not_count(): void
    {
        $workOrder = $this->workOrder();
        $line = $this->photoContactLine($workOrder);
        $this->text($workOrder, 'vendor', true, 'You are assigned to WO 44700.', ['created_at' => now()->subDays(2)]);
        $this->text($workOrder, 'vendor', false, 'On my way.');

        $this->tick($workOrder);

        $this->assertUntouched($line);
    }

    // --- tenant satisfaction ----------------------------------------------

    public function test_a_tenant_reply_the_judge_reads_as_satisfied_ticks_the_tenant_line(): void
    {
        $workOrder = $this->workOrder();
        $line = $this->tenantLine($workOrder);
        $this->text($workOrder, 'tenant', false, 'The plumber came by and the faucet is fixed, thank you!');
        $this->judgeSays(true);

        $this->tick($workOrder);

        $this->assertTicked($line, 'Tenant replied the repair is done (91%)');
        $this->assertTicked($line, 'no longer leaks');
    }

    public function test_the_judge_reads_the_thread_and_the_work_order(): void
    {
        $workOrder = $this->workOrder(['description' => 'Garbage disposal humming but not spinning']);
        $this->tenantLine($workOrder);
        $this->text($workOrder, 'tenant', true, 'Acme Plumbing was out today. Is everything working now?');
        $this->text($workOrder, 'tenant', false, 'Yes all good now, thanks.');
        $this->judgeSays(true);

        $this->tick($workOrder);

        TenantCompletionJudgeAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'Garbage disposal humming')
            && str_contains($prompt->prompt, 'us: "Acme Plumbing was out today')
            && str_contains($prompt->prompt, 'them (NEWEST, judge this): "Yes all good now'));
    }

    public function test_a_tenant_reply_saying_it_still_leaks_holds_the_tenant_line(): void
    {
        $workOrder = $this->workOrder();
        $line = $this->tenantLine($workOrder);
        $this->text($workOrder, 'tenant', false, 'He left but it is still dripping.');
        $this->judgeSays(false, 94, 'The tenant says the faucet still drips.');

        $this->tick($workOrder);

        $this->assertUntouched($line);
    }

    public function test_a_verdict_below_the_confidence_bar_holds_the_tenant_line(): void
    {
        $workOrder = $this->workOrder();
        $line = $this->tenantLine($workOrder);
        $this->text($workOrder, 'tenant', false, 'ok');
        $this->judgeSays(true, 55);

        $this->tick($workOrder);

        $this->assertUntouched($line);
    }

    public function test_no_tenant_reply_since_the_line_appeared_never_asks_the_judge(): void
    {
        $workOrder = $this->workOrder();
        $line = $this->tenantLine($workOrder);
        $this->text($workOrder, 'tenant', false, 'Fixed last month, thanks', ['created_at' => now()->subDays(30)]);
        $this->text($workOrder, 'tenant', true, 'Acme was out today, is it fixed?');
        $this->judgeSays(true);

        $this->tick($workOrder);

        $this->assertUntouched($line);
        TenantCompletionJudgeAgent::assertNeverPrompted();
    }

    public function test_an_ai_failure_holds_the_tenant_line_and_is_asked_again_next_time(): void
    {
        $workOrder = $this->workOrder();
        $line = $this->tenantLine($workOrder);
        $this->text($workOrder, 'tenant', false, 'All fixed, thanks!');
        TenantCompletionJudgeAgent::fake([new RuntimeException('provider down')]);

        $this->assertSame(0, $this->tick($workOrder));
        $this->assertUntouched($line);
        $this->assertFalse(Cache::has('task-auto-complete:tenant-completion:'.$workOrder->id.':'.Conversation::query()->withoutGlobalScopes()->max('id')));
    }

    public function test_no_ai_provider_holds_the_tenant_line(): void
    {
        config(['ai.providers.openai.key' => null]);

        $workOrder = $this->workOrder();
        $line = $this->tenantLine($workOrder);
        $this->text($workOrder, 'tenant', false, 'All fixed, thanks!');
        $this->judgeSays(true);

        $this->tick($workOrder);

        $this->assertUntouched($line);
        TenantCompletionJudgeAgent::assertNeverPrompted();
    }

    public function test_the_tenant_judge_gate_off_never_ticks_the_tenant_line(): void
    {
        config(['services.ai.tenant_completion_judge' => false]);

        $workOrder = $this->workOrder();
        $line = $this->tenantLine($workOrder);
        $this->text($workOrder, 'tenant', false, 'All fixed, thanks!');
        $this->judgeSays(true);

        $this->tick($workOrder);

        $this->assertUntouched($line);
        TenantCompletionJudgeAgent::assertNeverPrompted();
    }

    public function test_the_system_sends_no_text_for_any_of_these_lines(): void
    {
        $workOrder = $this->workOrder();
        $this->costLine($workOrder);
        $this->photoContactLine($workOrder);
        $this->tenantLine($workOrder);
        $this->checklistLine($workOrder);

        $this->tick($workOrder);

        $this->assertSame(0, Conversation::query()->withoutGlobalScopes()->count());
    }

    // --- the closing Yes/No line ------------------------------------------

    public function test_the_checklist_line_answers_yes_and_moves_on_once_every_other_line_is_ticked(): void
    {
        $this->mock(PropertyWareService::class)->shouldReceive('updateServiceStatus')->once()->andReturn(true);

        $workOrder = $this->workOrder();
        $cost = $this->costLine($workOrder);
        $photos = $this->line($workOrder, null, 'Have Before and After Photos been Uploaded?');
        $checklist = $this->checklistLine($workOrder);
        $photos->update(['status' => 'completed']);
        $this->invoice($workOrder);

        $this->tick($workOrder);

        $this->assertTicked($cost, 'Invoice on file');
        $this->assertTicked($checklist, 'All 2 other task(s) on this checklist are ticked');
        $this->assertSame('Yes', $checklist->fresh()->option);
        $this->assertSame($this->completedVerified->id, $workOrder->fresh()->service_status_id);

        $row = Activity::query()->where('log_name', 'task_automation')->latest('id')->firstOrFail();
        $this->assertSame('Completed - Verified - Updating Owner', $row->properties['moved_to']);
    }

    public function test_the_checklist_line_waits_while_any_other_line_is_pending(): void
    {
        $this->mock(PropertyWareService::class)->shouldReceive('updateServiceStatus')->never();

        $workOrder = $this->workOrder();
        $this->costLine($workOrder);
        $tenant = $this->tenantLine($workOrder);
        $checklist = $this->checklistLine($workOrder);
        $this->invoice($workOrder);

        $this->tick($workOrder);

        $this->assertUntouched($tenant);
        $this->assertUntouched($checklist);
        $this->assertSame($this->serviceCompleted->id, $workOrder->fresh()->service_status_id);
    }

    public function test_the_checklist_line_is_never_answered_no(): void
    {
        $workOrder = $this->workOrder();
        $this->line($workOrder, null, 'Call the tenant');
        $checklist = $this->checklistLine($workOrder);

        $this->tick($workOrder);

        $this->assertUntouched($checklist);
        $this->assertNull($checklist->fresh()->option);
    }

    public function test_a_checklist_line_with_no_siblings_is_left_alone(): void
    {
        $workOrder = $this->workOrder();
        $checklist = $this->checklistLine($workOrder);

        $this->tick($workOrder);

        $this->assertUntouched($checklist);
    }

    public function test_lines_from_another_template_do_not_hold_the_checklist_line(): void
    {
        $this->mock(PropertyWareService::class)->shouldReceive('updateServiceStatus')->once()->andReturn(true);

        $workOrder = $this->workOrder();
        $this->line($workOrder, null, 'Confirm Cost of Service with Vendor')->update(['status' => 'completed']);
        $checklist = $this->checklistLine($workOrder);

        $other = TaskTemplate::query()->create([
            'name' => 'Scheduled - Non Emergency',
            'current_service_status_id' => ServiceStatus::query()->create(['name' => 'Scheduled', 'description' => 'x'])->id,
            'is_current_service_status_emergency' => false,
        ]);
        $stale = Task::query()->create(['name' => 'Old vendor line', 'due_date' => 'same day', 'type' => 'Vendor', 'task_template_id' => $other->id, 'next_service_status_id' => $this->notChanged->id]);
        WorkOrderTask::factory()->create(['work_order_id' => $workOrder->id, 'task_id' => $stale->id, 'description' => 'Old vendor line']);

        $this->tick($workOrder);

        $this->assertTicked($checklist, 'All 1 other task(s)');
        $this->assertSame($this->completedVerified->id, $workOrder->fresh()->service_status_id);
    }

    public function test_the_whole_checklist_falls_in_one_run(): void
    {
        $this->mock(PropertyWareService::class)->shouldReceive('updateServiceStatus')->once()->andReturn(true);

        $workOrder = $this->workOrder();
        $cost = $this->costLine($workOrder);
        $photoContact = $this->photoContactLine($workOrder);
        $tenant = $this->tenantLine($workOrder);
        $photos = $this->line($workOrder, null, 'Have Before and After Photos been Uploaded?');
        $checklist = $this->checklistLine($workOrder);

        $photos->update(['status' => 'completed']);
        $this->invoice($workOrder);
        $this->attachment($workOrder, 'before');
        $this->attachment($workOrder, 'after');
        $this->text($workOrder, 'tenant', false, 'All fixed, thank you!');
        $this->judgeSays(true);

        $this->assertSame(4, $this->tick($workOrder));

        $this->assertTicked($cost, 'Invoice');
        $this->assertTicked($photoContact, 'photos are on the work order');
        $this->assertTicked($tenant, 'repair is done');
        $this->assertTicked($checklist, 'All 4 other task(s)');
        $this->assertSame($this->completedVerified->id, $workOrder->fresh()->service_status_id);
        $this->assertSame(4, Activity::query()->where('log_name', 'task_automation')->count());
    }

    public function test_an_undone_tick_sticks(): void
    {
        $workOrder = $this->workOrder();
        $line = $this->costLine($workOrder);
        $this->invoice($workOrder);

        $this->assertSame(1, $this->tick($workOrder));
        $line->fresh()->update(['status' => 'pending']);

        $this->assertSame(0, $this->tick($workOrder));
        $this->assertSame('pending', $line->fresh()->status);
    }

    // --- the vendor photo-request text -------------------------------------

    /** The feature went live three days ago; the line appeared a day and an hour ago. */
    private function photoRequestDue(WorkOrder $workOrder, string $phone = '(346) 412-2380'): WorkOrderTask
    {
        AppSetting::putValue('vendor_photo_request.started_at', ['started_at' => now()->subDays(3)->toIso8601String()]);
        $workOrder->vendors()->first()->user->forceFill(['phone' => $phone])->save();

        $line = $this->photoContactLine($workOrder);
        $line->forceFill(['created_at' => now()->subHours(25)])->save();

        return $line;
    }

    public function test_missing_photos_a_day_later_text_the_vendor_once_with_their_portal_link_and_tick_the_line(): void
    {
        Queue::fake();

        $workOrder = $this->workOrder();
        $line = $this->photoRequestDue($workOrder);

        $this->assertSame(1, $this->tick($workOrder));

        Queue::assertPushed(SendConversationMessageJob::class, 1);
        $this->assertTicked($line, 'Vendor texted for the missing photos');

        $text = Conversation::query()->withoutGlobalScopes()->where('work_order_id', $workOrder->id)->where('conversation_type', 'vendor')->firstOrFail();
        $this->assertTrue((bool) $text->is_read);
        $this->assertSame('+13464122380', $text->receiver_number);
        $this->assertStringContainsString('before and after photos have not been uploaded', $text->message);
        $this->assertStringContainsString('/vendor-portal/', $text->message);

        $ledger = Activity::query()->where('log_name', 'automated_message')->where('event', 'vendor_photo_request_sms')->firstOrFail();
        $this->assertSame($workOrder->id, (int) $ledger->properties['work_order_id']);
        $this->assertSame('before and after photos', $ledger->properties['missing']);

        // Nothing goes out twice.
        $this->assertSame(0, $this->tick($workOrder));
        Queue::assertPushed(SendConversationMessageJob::class, 1);
    }

    public function test_only_the_after_photo_missing_names_just_the_after_photos(): void
    {
        Queue::fake();

        $workOrder = $this->workOrder();
        $this->photoRequestDue($workOrder);
        $this->attachment($workOrder, 'before');

        $this->tick($workOrder);

        $text = Conversation::query()->withoutGlobalScopes()->where('conversation_type', 'vendor')->firstOrFail();
        $this->assertStringContainsString('the after photos have not been uploaded', $text->message);
    }

    public function test_a_line_that_appeared_before_the_text_went_live_is_never_texted_about(): void
    {
        Queue::fake();

        $workOrder = $this->workOrder();
        $line = $this->photoRequestDue($workOrder);
        AppSetting::putValue('vendor_photo_request.started_at', ['started_at' => now()->subHours(2)->toIso8601String()]);

        $this->assertSame(0, $this->tick($workOrder));

        Queue::assertNothingPushed();
        $this->assertUntouched($line);
        $this->assertSame(0, Conversation::query()->withoutGlobalScopes()->count());
    }

    public function test_no_live_date_recorded_means_no_text(): void
    {
        Queue::fake();

        $workOrder = $this->workOrder();
        $line = $this->photoRequestDue($workOrder);
        AppSetting::query()->where('key', 'vendor_photo_request.started_at')->delete();

        $this->tick($workOrder);

        Queue::assertNothingPushed();
        $this->assertUntouched($line);
    }

    public function test_a_line_younger_than_the_wait_is_not_texted_about_yet(): void
    {
        Queue::fake();

        $workOrder = $this->workOrder();
        $line = $this->photoRequestDue($workOrder);
        $line->forceFill(['created_at' => now()->subHours(23)])->save();

        $this->tick($workOrder);

        Queue::assertNothingPushed();
        $this->assertUntouched($line);
    }

    public function test_the_photo_text_switch_off_leaves_the_line_for_the_coordinator(): void
    {
        Queue::fake();
        config(['services.tasks.vendor_photo_request_sms' => false]);

        $workOrder = $this->workOrder();
        $line = $this->photoRequestDue($workOrder);

        $this->tick($workOrder);

        Queue::assertNothingPushed();
        $this->assertUntouched($line);
    }

    public function test_a_thmp_work_order_is_not_texted_the_crew_uploads_through_jobber(): void
    {
        Queue::fake();

        $workOrder = $this->workOrder();
        $line = $this->photoRequestDue($workOrder);
        $this->jobberJob($workOrder);

        $this->tick($workOrder);

        Queue::assertNothingPushed();
        $this->assertUntouched($line);
    }

    public function test_a_vendor_without_a_phone_number_is_not_texted(): void
    {
        Queue::fake();

        $workOrder = $this->workOrder();
        $line = $this->photoRequestDue($workOrder, '');

        $this->tick($workOrder);

        Queue::assertNothingPushed();
        $this->assertUntouched($line);
        $this->assertSame(0, Activity::query()->where('log_name', 'automated_message')->count());
    }

    public function test_a_dry_run_says_it_would_text_the_vendor_and_sends_nothing(): void
    {
        Queue::fake();

        $workOrder = $this->workOrder();
        $line = $this->photoRequestDue($workOrder);

        $this->artisan('tasks:auto-complete-triggers', ['--dry-run' => true, '--work-order' => $workOrder->id])
            ->expectsOutputToContain('would text the vendor for the before and after photos')
            ->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertUntouched($line);
        $this->assertSame(0, Conversation::query()->withoutGlobalScopes()->count());
    }

    public function test_the_sweep_command_texts_the_vendor_even_when_nothing_else_is_tickable_yet(): void
    {
        Queue::fake();

        $workOrder = $this->workOrder();
        $line = $this->photoRequestDue($workOrder);

        $this->artisan('tasks:auto-complete-triggers')
            ->expectsOutputToContain('texting the vendor for the before and after photos')
            ->expectsOutputToContain('1 task(s) ticked on 1 work order(s)')
            ->assertSuccessful();

        Queue::assertPushed(SendConversationMessageJob::class, 1);
        $this->assertTicked($line, 'Vendor texted for the missing photos');
    }

    public function test_the_live_date_migration_writes_once_and_keeps_the_original(): void
    {
        AppSetting::query()->where('key', 'vendor_photo_request.started_at')->delete();

        $migration = require database_path('migrations/2026_10_14_090200_record_vendor_photo_request_start.php');

        $migration->up();
        $first = AppSetting::getValue('vendor_photo_request.started_at');
        $this->assertNotEmpty($first['started_at']);

        $this->travel(2)->hours();
        $migration->up();
        $this->assertSame($first, AppSetting::getValue('vendor_photo_request.started_at'));

        $migration->down();
        $this->assertNull(AppSetting::getValue('vendor_photo_request.started_at'));
    }

    // --- the seed migration -------------------------------------------------

    public function test_the_seed_puts_the_four_triggers_on_the_existing_lines_by_title(): void
    {
        $names = [
            'Confirm Cost of Service with Vendor' => 'cost_confirmed',
            'If Pictures Have not been uploaded contact vendor to upload' => 'photos_on_file_or_vendor_asked',
            'Contact Tenant to Confirm Completion to Tenant Satisfaction' => 'tenant_confirmed_completion',
            'Have All Items in Checklist  been completed?' => 'checklist_otherwise_complete',
            'Have Before and After Photos been Uploaded?' => null,
        ];

        foreach (array_keys($names) as $name) {
            Task::query()->create(['name' => $name, 'due_date' => 'same day', 'type' => 'Woc', 'task_template_id' => $this->template->id, 'next_service_status_id' => $this->notChanged->id]);
        }

        $picked = Task::query()->create(['name' => 'Confirm Cost of Service with Vendor', 'due_date' => 'same day', 'type' => 'Woc', 'task_template_id' => $this->template->id, 'next_service_status_id' => $this->notChanged->id, 'auto_complete_trigger' => 'after_photo_added']);

        $migration = require database_path('migrations/2026_10_14_090100_seed_woc_completed_task_auto_complete_triggers.php');
        $migration->up();

        foreach ($names as $name => $trigger) {
            $this->assertSame($trigger, Task::query()->where('name', $name)->whereKeyNot($picked->id)->firstOrFail()->auto_complete_trigger, $name);
        }

        $this->assertSame('after_photo_added', $picked->fresh()->auto_complete_trigger);

        $migration->down();

        $this->assertSame(0, Task::query()->whereIn('auto_complete_trigger', ['cost_confirmed', 'photos_on_file_or_vendor_asked', 'tenant_confirmed_completion', 'checklist_otherwise_complete'])->count());
        $this->assertSame('after_photo_added', $picked->fresh()->auto_complete_trigger);
    }
}
