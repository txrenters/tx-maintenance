<?php

namespace Tests\Feature;

use App\Jobs\UploadHoaNoticeToPropertyWare;
use App\Models\Attachments;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\PropertyWareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class RepairHoaNoticeUploadsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Notices are aged past the command's one-hour grace period by default —
     * a fresh row is assumed to still have its upload job on the queue.
     */
    private function noticeAttachment(
        WorkOrder $workOrder,
        ?string $pwFileName,
        string $extension = 'pdf',
        string $mime = 'application/pdf',
        ?Carbon $createdAt = null,
    ): Attachments {
        return Attachments::withoutGlobalScopes()->create([
            'title' => 'HOA violation notice',
            'filename' => 'attachments/notice-'.$workOrder->id.'.'.$extension,
            'filetype' => $mime,
            'type' => 'attachment',
            'work_order_id' => $workOrder->id,
            'user_id' => User::factory()->create()->id,
            'pw_file_name' => $pwFileName,
            'created_at' => $createdAt ?? now()->subDay(),
        ]);
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

    public function test_a_notice_missing_from_propertyware_is_redispatched_with_a_cleared_name(): void
    {
        Queue::fake();
        Storage::fake('public');

        $workOrder = WorkOrder::factory()->create(['propertyware_id' => '111', 'work_order_no' => 43749]);
        $attachment = $this->noticeAttachment($workOrder, 'HOA Notice - WO43749 - 2026-08-06.pdf');
        Storage::disk('public')->put($attachment->filename, '%PDF-1.4 fake notice');

        // The old code wrote pw_file_name eagerly, but PropertyWare never got
        // the file — the exact WO#43749 situation.
        $this->mockDocuments(['111' => []]);

        $this->artisan('hoa:repair-notice-uploads')->assertExitCode(0);

        Queue::assertPushed(UploadHoaNoticeToPropertyWare::class, function (UploadHoaNoticeToPropertyWare $job) use ($attachment) {
            return $job->attachmentId === $attachment->id
                && $job->pwFileName === 'HOA Notice - WO43749 - 2026-08-06.pdf';
        });

        // Cleared so the document sync cannot skip the doc if this push fails too.
        $this->assertNull($attachment->refresh()->pw_file_name);
    }

    public function test_a_notice_already_in_propertyware_is_left_alone(): void
    {
        Queue::fake();
        Storage::fake('public');

        $workOrder = WorkOrder::factory()->create(['propertyware_id' => '222', 'work_order_no' => 55123]);
        $attachment = $this->noticeAttachment($workOrder, 'HOA Notice - WO55123 - 2026-08-11.pdf');
        Storage::disk('public')->put($attachment->filename, '%PDF-1.4 fake notice');

        $this->mockDocuments(['222' => [
            ['id' => 'doc-1', 'fileName' => 'HOA Notice - WO55123 - 2026-08-11.pdf'],
        ]]);

        $this->artisan('hoa:repair-notice-uploads')->assertExitCode(0);

        Queue::assertNotPushed(UploadHoaNoticeToPropertyWare::class);
        $this->assertSame('HOA Notice - WO55123 - 2026-08-11.pdf', $attachment->refresh()->pw_file_name);
    }

    public function test_a_notice_whose_file_is_gone_is_reported_not_dispatched(): void
    {
        Queue::fake();
        Storage::fake('public');

        // The notice was uploaded from another machine: the row may exist but
        // the PDF is not on this disk. Nothing to push — staff must re-upload.
        $workOrder = WorkOrder::factory()->create(['propertyware_id' => '333', 'work_order_no' => 43749]);
        $this->noticeAttachment($workOrder, 'HOA Notice - WO43749 - 2026-08-06.pdf');

        $this->mockDocuments(['333' => []]);

        $this->artisan('hoa:repair-notice-uploads')
            ->expectsOutputToContain('missing on disk')
            ->assertExitCode(0);

        Queue::assertNotPushed(UploadHoaNoticeToPropertyWare::class);
    }

    public function test_dry_run_changes_nothing(): void
    {
        Queue::fake();
        Storage::fake('public');

        $workOrder = WorkOrder::factory()->create(['propertyware_id' => '444', 'work_order_no' => 55124]);
        $attachment = $this->noticeAttachment($workOrder, 'HOA Notice - WO55124 - 2026-08-06.pdf');
        Storage::disk('public')->put($attachment->filename, '%PDF-1.4 fake notice');

        $this->mockDocuments(['444' => []]);

        $this->artisan('hoa:repair-notice-uploads --dry-run')->assertExitCode(0);

        Queue::assertNotPushed(UploadHoaNoticeToPropertyWare::class);
        $this->assertSame('HOA Notice - WO55124 - 2026-08-06.pdf', $attachment->refresh()->pw_file_name);
    }

    public function test_a_photo_notice_is_swept_too_and_keeps_its_extension(): void
    {
        Queue::fake();
        Storage::fake('public');

        // The WO#43822 case: a photographed notice was never pushed at all, and
        // the attachment sweep skips this title — so if this command passes it
        // over as well, nothing in the system will ever find it.
        $workOrder = WorkOrder::factory()->create(['propertyware_id' => '555', 'work_order_no' => 43822]);
        $attachment = $this->noticeAttachment($workOrder, null, 'jpg', 'image/jpeg');
        Storage::disk('public')->put($attachment->filename, 'fake photo bytes');

        $this->mockDocuments(['555' => []]);

        $this->artisan('hoa:repair-notice-uploads')->assertExitCode(0);

        // Named .jpg, not .pdf — PropertyWare types the document off the name.
        Queue::assertPushed(UploadHoaNoticeToPropertyWare::class, function (UploadHoaNoticeToPropertyWare $job) use ($attachment) {
            return $job->attachmentId === $attachment->id
                && str_starts_with($job->pwFileName, 'HOA Notice - WO43822 - ')
                && str_ends_with($job->pwFileName, '.jpg');
        });
    }

    public function test_the_work_order_option_scopes_the_sweep(): void
    {
        Queue::fake();
        Storage::fake('public');

        $target = WorkOrder::factory()->create(['propertyware_id' => '666', 'work_order_no' => 43822]);
        $other = WorkOrder::factory()->create(['propertyware_id' => '777', 'work_order_no' => 43823]);

        $wanted = $this->noticeAttachment($target, null, 'jpg', 'image/jpeg');
        $untouched = $this->noticeAttachment($other, null);
        Storage::disk('public')->put($wanted->filename, 'fake photo bytes');
        Storage::disk('public')->put($untouched->filename, '%PDF-1.4 fake notice');

        $this->mockDocuments(['666' => [], '777' => []]);

        $this->artisan('hoa:repair-notice-uploads --work-order=43822')->assertExitCode(0);

        Queue::assertPushed(
            UploadHoaNoticeToPropertyWare::class,
            fn (UploadHoaNoticeToPropertyWare $job) => $job->attachmentId === $wanted->id,
        );
        Queue::assertNotPushed(
            UploadHoaNoticeToPropertyWare::class,
            fn (UploadHoaNoticeToPropertyWare $job) => $job->attachmentId === $untouched->id,
        );
    }

    public function test_an_unknown_work_order_number_is_an_error(): void
    {
        Queue::fake();

        $this->artisan('hoa:repair-notice-uploads --work-order=99999')
            ->expectsOutputToContain('No work order found with number 99999.')
            ->assertExitCode(1);

        Queue::assertNotPushed(UploadHoaNoticeToPropertyWare::class);
    }

    public function test_a_notice_older_than_the_window_is_left_to_a_manual_run(): void
    {
        Queue::fake();
        Storage::fake('public');

        // The nightly run stays cheap by only looking back a fortnight — every
        // candidate costs a PropertyWare document listing call. An older notice
        // is still reachable, but only when someone asks for it.
        $workOrder = WorkOrder::factory()->create(['propertyware_id' => '888', 'work_order_no' => 41001]);
        $attachment = $this->noticeAttachment($workOrder, null, 'pdf', 'application/pdf', Carbon::now()->subDays(60));
        Storage::disk('public')->put($attachment->filename, '%PDF-1.4 fake notice');

        $this->mockDocuments(['888' => []]);

        $this->artisan('hoa:repair-notice-uploads')->assertExitCode(0);
        Queue::assertNotPushed(UploadHoaNoticeToPropertyWare::class);

        $this->artisan('hoa:repair-notice-uploads --days=90')->assertExitCode(0);
        Queue::assertPushed(UploadHoaNoticeToPropertyWare::class);
    }

    public function test_a_just_uploaded_notice_is_left_to_its_own_queued_job(): void
    {
        Queue::fake();
        Storage::fake('public');

        // Its upload job may still be waiting in the queue; dispatching a second
        // one would put the notice into PropertyWare twice.
        $workOrder = WorkOrder::factory()->create(['propertyware_id' => '999', 'work_order_no' => 43900]);
        $attachment = $this->noticeAttachment($workOrder, null, 'pdf', 'application/pdf', Carbon::now()->subMinutes(5));
        Storage::disk('public')->put($attachment->filename, '%PDF-1.4 fake notice');

        $this->mockDocuments(['999' => []]);

        $this->artisan('hoa:repair-notice-uploads')->assertExitCode(0);

        Queue::assertNotPushed(UploadHoaNoticeToPropertyWare::class);
    }
}
