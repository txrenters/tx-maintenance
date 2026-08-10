<?php

namespace Tests\Feature;

use App\Jobs\UploadAttachment;
use App\Models\Attachments;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\PropertyWareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class RepairAttachmentPwUploadsTest extends TestCase
{
    use RefreshDatabase;

    private function photoAttachment(WorkOrder $workOrder, array $overrides = []): Attachments
    {
        $attachment = Attachments::withoutGlobalScopes()->create(array_merge([
            'title' => 'Before Repair',
            'filename' => 'attachments/photo-'.uniqid().'.jpg',
            'filetype' => 'image/jpeg',
            'type' => 'before',
            'work_order_id' => $workOrder->id,
            'user_id' => User::factory()->create()->id,
            'pw_file_name' => null,
            'created_at' => now()->subHours(2),
        ], $overrides));

        Storage::disk('public')->put($attachment->filename, 'fake-jpeg-bytes');

        return $attachment;
    }

    private function mockDocuments(array $byPwId): void
    {
        $mock = Mockery::mock(PropertyWareService::class);

        foreach ($byPwId as $pwId => $documents) {
            $mock->shouldReceive('getWorkOrderDocuments')
                ->with($pwId)
                ->andReturn($documents);
        }

        $this->app->instance(PropertyWareService::class, $mock);
    }

    public function test_an_unconfirmed_attachment_missing_from_propertyware_is_redispatched(): void
    {
        Queue::fake();
        Storage::fake('public');

        $workOrder = WorkOrder::factory()->create(['propertyware_id' => '111', 'work_order_no' => 43487]);
        $attachment = $this->photoAttachment($workOrder);
        $expected = UploadAttachment::propertyWareFileName($attachment);

        $this->mockDocuments(['111' => []]);

        $this->artisan('attachments:repair-pw-uploads')->assertExitCode(0);

        Queue::assertPushed(UploadAttachment::class, function (UploadAttachment $job) use ($attachment, $expected) {
            return $job->attachmentId === $attachment->id
                && $job->pwFileName === $expected;
        });
    }

    public function test_an_upload_that_landed_without_confirmation_is_backfilled_not_redispatched(): void
    {
        Queue::fake();
        Storage::fake('public');

        $workOrder = WorkOrder::factory()->create(['propertyware_id' => '222', 'work_order_no' => 43487]);
        $attachment = $this->photoAttachment($workOrder);
        $expected = UploadAttachment::propertyWareFileName($attachment);

        $this->mockDocuments(['222' => [
            ['id' => 'doc-1', 'fileName' => $expected],
        ]]);

        $this->artisan('attachments:repair-pw-uploads')->assertExitCode(0);

        Queue::assertNotPushed(UploadAttachment::class);
        $this->assertSame($expected, $attachment->refresh()->pw_file_name);
    }

    public function test_hoa_violation_notices_are_left_to_their_own_repair_command(): void
    {
        Queue::fake();
        Storage::fake('public');

        $workOrder = WorkOrder::factory()->create(['propertyware_id' => '333', 'work_order_no' => 43749]);
        $this->photoAttachment($workOrder, [
            'title' => 'HOA violation notice',
            'filetype' => 'application/pdf',
            'type' => 'attachment',
        ]);

        // No getWorkOrderDocuments expectation: the mock throws if it is called.
        $this->mockDocuments([]);

        $this->artisan('attachments:repair-pw-uploads')->assertExitCode(0);

        Queue::assertNotPushed(UploadAttachment::class);
    }

    public function test_rows_outside_the_window_or_inside_the_grace_period_are_excluded(): void
    {
        Queue::fake();
        Storage::fake('public');

        $workOrder = WorkOrder::factory()->create(['propertyware_id' => '444', 'work_order_no' => 43487]);
        // Predates the pw_file_name column era — probably already in
        // PropertyWare under an old timestamped name we cannot match.
        $this->photoAttachment($workOrder, ['created_at' => now()->subDays(30)]);
        // Just uploaded — its own queued job may still be pending.
        $this->photoAttachment($workOrder, ['created_at' => now()->subMinutes(10)]);

        $this->mockDocuments([]);

        $this->artisan('attachments:repair-pw-uploads')->assertExitCode(0);

        Queue::assertNotPushed(UploadAttachment::class);
    }

    public function test_an_attachment_whose_file_is_gone_is_reported_not_dispatched(): void
    {
        Queue::fake();
        Storage::fake('public');

        // The photo was uploaded from another machine: the row exists but the
        // file is not on this disk. Nothing to push — staff must re-upload.
        $workOrder = WorkOrder::factory()->create(['propertyware_id' => '555', 'work_order_no' => 43487]);
        $attachment = $this->photoAttachment($workOrder);
        Storage::disk('public')->delete($attachment->filename);

        $this->mockDocuments(['555' => []]);

        $this->artisan('attachments:repair-pw-uploads')
            ->expectsOutputToContain('missing on disk')
            ->assertExitCode(0);

        Queue::assertNotPushed(UploadAttachment::class);
    }

    public function test_dry_run_changes_nothing(): void
    {
        Queue::fake();
        Storage::fake('public');

        $workOrder = WorkOrder::factory()->create(['propertyware_id' => '666', 'work_order_no' => 43487]);
        $missing = $this->photoAttachment($workOrder);
        $landed = $this->photoAttachment($workOrder);

        $this->mockDocuments(['666' => [
            ['id' => 'doc-1', 'fileName' => UploadAttachment::propertyWareFileName($landed)],
        ]]);

        $this->artisan('attachments:repair-pw-uploads --dry-run')->assertExitCode(0);

        Queue::assertNotPushed(UploadAttachment::class);
        // Not even the backfill of the landed upload is written on a dry run.
        $this->assertNull($missing->refresh()->pw_file_name);
        $this->assertNull($landed->refresh()->pw_file_name);
    }

    public function test_the_work_order_option_scopes_the_sweep_to_that_work_order_only(): void
    {
        Queue::fake();
        Storage::fake('public');

        $target = WorkOrder::factory()->create(['propertyware_id' => '777', 'work_order_no' => 43487]);
        $other = WorkOrder::factory()->create(['propertyware_id' => '888', 'work_order_no' => 55123]);
        $targetAttachment = $this->photoAttachment($target);
        $this->photoAttachment($other);

        // Only the target's documents are listed; the mock throws if the
        // other work order is queried.
        $this->mockDocuments(['777' => []]);

        $this->artisan('attachments:repair-pw-uploads --work-order=43487')->assertExitCode(0);

        Queue::assertPushed(UploadAttachment::class, 1);
        Queue::assertPushed(UploadAttachment::class, fn (UploadAttachment $job) => $job->attachmentId === $targetAttachment->id);
    }

    public function test_an_unknown_work_order_number_fails_without_doing_anything(): void
    {
        Queue::fake();
        Storage::fake('public');

        $workOrder = WorkOrder::factory()->create(['propertyware_id' => '999', 'work_order_no' => 43487]);
        $this->photoAttachment($workOrder);

        $this->mockDocuments([]);

        $this->artisan('attachments:repair-pw-uploads --work-order=99999')->assertExitCode(1);

        Queue::assertNotPushed(UploadAttachment::class);
    }

    public function test_local_only_work_orders_are_skipped_without_calling_propertyware(): void
    {
        Queue::fake();
        Storage::fake('public');

        $workOrder = WorkOrder::factory()->create(['propertyware_id' => null]);
        $this->photoAttachment($workOrder);

        // No getWorkOrderDocuments expectation: the mock throws if it is called.
        $this->mockDocuments([]);

        $this->artisan('attachments:repair-pw-uploads')->assertExitCode(0);

        Queue::assertNotPushed(UploadAttachment::class);
    }
}
