<?php

namespace Tests\Feature;

use App\Jobs\UploadHoaNoticeToPropertyWare;
use App\Models\Attachments;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\PropertyWareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class RepairHoaNoticeUploadsTest extends TestCase
{
    use RefreshDatabase;

    private function noticeAttachment(WorkOrder $workOrder, ?string $pwFileName): Attachments
    {
        return Attachments::withoutGlobalScopes()->create([
            'title' => 'HOA violation notice',
            'filename' => 'attachments/notice-'.$workOrder->id.'.pdf',
            'filetype' => 'application/pdf',
            'type' => 'attachment',
            'work_order_id' => $workOrder->id,
            'user_id' => User::factory()->create()->id,
            'pw_file_name' => $pwFileName,
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
}
