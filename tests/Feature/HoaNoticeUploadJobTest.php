<?php

namespace Tests\Feature;

use App\Jobs\UploadHoaNoticeToPropertyWare;
use App\Models\Attachments;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\PropertyWareService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HoaNoticeUploadJobTest extends TestCase
{
    use RefreshDatabase;

    private function noticeAttachment(WorkOrder $workOrder, string $storedPath = 'attachments/notice.pdf'): Attachments
    {
        return Attachments::withoutGlobalScopes()->create([
            'title' => 'HOA violation notice',
            'filename' => $storedPath,
            'filetype' => 'application/pdf',
            'type' => 'attachment',
            'work_order_id' => $workOrder->id,
            'user_id' => User::factory()->create()->id,
            'viewed_by_staff_at' => now(),
        ]);
    }

    public function test_a_confirmed_upload_records_the_propertyware_file_name(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('attachments/notice.pdf', '%PDF-1.4 fake notice');

        Http::fake(function ($request) {
            if ($request->method() === 'POST') {
                return Http::response(['id' => 4242]);
            }

            return Http::response([]); // the metadata PUT
        });

        $workOrder = WorkOrder::factory()->create(['propertyware_id' => '88999', 'work_order_no' => 55123]);
        $attachment = $this->noticeAttachment($workOrder);
        $fileName = 'HOA Notice - WO55123 - 2026-08-11.pdf';

        (new UploadHoaNoticeToPropertyWare($attachment->id, $fileName))
            ->handle(app(PropertyWareService::class));

        $this->assertSame($fileName, $attachment->refresh()->pw_file_name);
    }

    public function test_a_rejected_upload_throws_so_the_queue_retries_and_the_name_stays_null(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('attachments/notice.pdf', '%PDF-1.4 fake notice');

        Http::fake(fn () => Http::response('Service unavailable', 503));

        $workOrder = WorkOrder::factory()->create(['propertyware_id' => '88999', 'work_order_no' => 55123]);
        $attachment = $this->noticeAttachment($workOrder);

        $thrown = null;

        try {
            (new UploadHoaNoticeToPropertyWare($attachment->id, 'HOA Notice - WO55123 - 2026-08-11.pdf'))
                ->handle(app(PropertyWareService::class));
        } catch (Exception $exception) {
            $thrown = $exception;
        }

        // The old inline call swallowed this; throwing is what arms the retries.
        $this->assertNotNull($thrown);
        $this->assertNull($attachment->refresh()->pw_file_name);
    }

    public function test_a_missing_file_fails_without_calling_propertyware(): void
    {
        Storage::fake('public');
        Http::fake();

        $workOrder = WorkOrder::factory()->create(['propertyware_id' => '88999', 'work_order_no' => 55123]);
        $attachment = $this->noticeAttachment($workOrder, 'attachments/gone.pdf');

        // Retrying cannot conjure the file back — the job fails straight out.
        (new UploadHoaNoticeToPropertyWare($attachment->id, 'HOA Notice - WO55123 - 2026-08-11.pdf'))
            ->handle(app(PropertyWareService::class));

        Http::assertNothingSent();
        $this->assertNull($attachment->refresh()->pw_file_name);
    }

    public function test_a_work_order_without_a_propertyware_id_is_skipped(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('attachments/notice.pdf', '%PDF-1.4 fake notice');
        Http::fake();

        $workOrder = WorkOrder::factory()->create(['propertyware_id' => null]);
        $attachment = $this->noticeAttachment($workOrder);

        (new UploadHoaNoticeToPropertyWare($attachment->id, 'HOA Notice - WO0 - 2026-08-11.pdf'))
            ->handle(app(PropertyWareService::class));

        Http::assertNothingSent();
        $this->assertNull($attachment->refresh()->pw_file_name);
    }
}
