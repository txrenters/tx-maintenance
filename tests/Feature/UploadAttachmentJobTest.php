<?php

namespace Tests\Feature;

use App\Jobs\UploadAttachment;
use App\Models\Attachments;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\PropertyWareService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UploadAttachmentJobTest extends TestCase
{
    use RefreshDatabase;

    private function photoAttachment(WorkOrder $workOrder, array $overrides = []): Attachments
    {
        $attachment = Attachments::withoutGlobalScopes()->create(array_merge([
            'title' => 'Before Repair',
            'filename' => 'attachments/photo.jpg',
            'filetype' => 'image/jpeg',
            'type' => 'before',
            'work_order_id' => $workOrder->id,
            'user_id' => User::factory()->create()->id,
            'is_publish_to_owner_portal' => false,
            'is_publish_to_tenant_portal' => false,
        ], $overrides));

        Storage::disk('public')->put($attachment->filename, 'fake-jpeg-bytes');

        return $attachment;
    }

    private function fakeSuccessfulPropertyWare(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'POST') {
                return Http::response(['id' => 4242]);
            }

            return Http::response([]); // the metadata PUT
        });
    }

    public function test_a_confirmed_upload_records_the_deterministic_propertyware_file_name(): void
    {
        Storage::fake('public');
        $this->fakeSuccessfulPropertyWare();

        $workOrder = WorkOrder::factory()->create(['propertyware_id' => '88999', 'work_order_no' => 43487]);
        $attachment = $this->photoAttachment($workOrder);

        $expected = 'Before_Repair_'.$attachment->id.'_'.$attachment->created_at->format('Ymd').'.jpg';

        (new UploadAttachment($attachment))->handle(app(PropertyWareService::class));

        $this->assertSame($expected, $attachment->refresh()->pw_file_name);
        Http::assertSent(function (Request $request) use ($expected) {
            return $request->method() === 'PUT' && $request['fileName'] === $expected;
        });
    }

    public function test_a_rejected_upload_throws_so_the_queue_retries_and_the_name_stays_null(): void
    {
        Storage::fake('public');
        Http::fake(fn () => Http::response('Service unavailable', 503));

        $workOrder = WorkOrder::factory()->create(['propertyware_id' => '88999', 'work_order_no' => 43487]);
        $attachment = $this->photoAttachment($workOrder);

        $thrown = null;

        try {
            (new UploadAttachment($attachment))->handle(app(PropertyWareService::class));
        } catch (Exception $exception) {
            $thrown = $exception;
        }

        // The old inline call swallowed this; throwing is what arms the retries.
        $this->assertNotNull($thrown);
        $this->assertNull($attachment->refresh()->pw_file_name);
    }

    public function test_a_failed_metadata_put_throws_and_the_name_stays_null(): void
    {
        Storage::fake('public');
        Http::fake(function (Request $request) {
            if ($request->method() === 'POST') {
                return Http::response(['id' => 4242]);
            }

            return Http::response('Bad request', 400);
        });

        $workOrder = WorkOrder::factory()->create(['propertyware_id' => '88999', 'work_order_no' => 43487]);
        $attachment = $this->photoAttachment($workOrder);

        $thrown = null;

        try {
            (new UploadAttachment($attachment))->handle(app(PropertyWareService::class));
        } catch (Exception $exception) {
            $thrown = $exception;
        }

        // Without its portal publish flags the document does not serve its
        // purpose — this branch used to silently return false.
        $this->assertNotNull($thrown);
        $this->assertNull($attachment->refresh()->pw_file_name);
    }

    public function test_before_and_after_photos_are_always_published_to_the_owner_portal(): void
    {
        Storage::fake('public');
        $this->fakeSuccessfulPropertyWare();

        $workOrder = WorkOrder::factory()->create(['propertyware_id' => '88999', 'work_order_no' => 43487]);
        $attachment = $this->photoAttachment($workOrder, [
            'type' => 'after',
            'is_publish_to_owner_portal' => false,
        ]);

        (new UploadAttachment($attachment))->handle(app(PropertyWareService::class));

        Http::assertSent(function (Request $request) {
            return $request->method() === 'PUT' && $request['publishToOwnerPortal'] === 'true';
        });
    }

    public function test_a_plain_attachment_keeps_following_the_owner_portal_checkbox(): void
    {
        Storage::fake('public');
        $this->fakeSuccessfulPropertyWare();

        $workOrder = WorkOrder::factory()->create(['propertyware_id' => '88999', 'work_order_no' => 43487]);
        $attachment = $this->photoAttachment($workOrder, [
            'type' => 'attachment',
            'is_publish_to_owner_portal' => false,
        ]);

        (new UploadAttachment($attachment))->handle(app(PropertyWareService::class));

        Http::assertSent(function (Request $request) {
            return $request->method() === 'PUT' && $request['publishToOwnerPortal'] === 'false';
        });
    }

    public function test_a_missing_file_fails_without_calling_propertyware(): void
    {
        Storage::fake('public');
        Http::fake();

        $workOrder = WorkOrder::factory()->create(['propertyware_id' => '88999', 'work_order_no' => 43487]);
        $attachment = $this->photoAttachment($workOrder);
        Storage::disk('public')->delete($attachment->filename);

        // Retrying cannot conjure the file back — the job fails straight out.
        (new UploadAttachment($attachment))->handle(app(PropertyWareService::class));

        Http::assertNothingSent();
        $this->assertNull($attachment->refresh()->pw_file_name);
    }

    public function test_a_work_order_without_a_propertyware_id_is_skipped(): void
    {
        Storage::fake('public');
        Http::fake();

        $workOrder = WorkOrder::factory()->create(['propertyware_id' => null]);
        $attachment = $this->photoAttachment($workOrder);

        (new UploadAttachment($attachment))->handle(app(PropertyWareService::class));

        Http::assertNothingSent();
        $this->assertNull($attachment->refresh()->pw_file_name);
    }

    public function test_a_deleted_attachment_row_is_skipped_quietly(): void
    {
        Storage::fake('public');
        Http::fake();

        $workOrder = WorkOrder::factory()->create(['propertyware_id' => '88999', 'work_order_no' => 43487]);
        $attachment = $this->photoAttachment($workOrder);

        $job = new UploadAttachment($attachment);
        $attachment->delete();

        $job->handle(app(PropertyWareService::class));

        Http::assertNothingSent();
    }

    public function test_same_title_bulk_uploads_get_distinct_names_and_names_never_drift(): void
    {
        Storage::fake('public');

        $workOrder = WorkOrder::factory()->create(['propertyware_id' => '88999', 'work_order_no' => 43487]);
        $first = $this->photoAttachment($workOrder, ['filename' => 'attachments/photo-1.jpg']);
        $second = $this->photoAttachment($workOrder, ['filename' => 'attachments/photo-2.jpg']);

        // Same title, same second: the embedded id keeps the PropertyWare
        // names apart (the old per-attempt timestamp collided here).
        $this->assertNotSame(
            UploadAttachment::propertyWareFileName($first),
            UploadAttachment::propertyWareFileName($second)
        );

        // Deterministic from row data: a re-dispatch computes the same name.
        $this->assertSame(
            (new UploadAttachment($first))->pwFileName,
            (new UploadAttachment($first))->pwFileName
        );
    }

    public function test_a_title_that_sanitizes_to_nothing_falls_back_to_a_generic_name(): void
    {
        Storage::fake('public');

        $workOrder = WorkOrder::factory()->create(['propertyware_id' => '88999', 'work_order_no' => 43487]);
        $attachment = $this->photoAttachment($workOrder, ['title' => '()']);

        $this->assertSame(
            'Attachment_'.$attachment->id.'_'.$attachment->created_at->format('Ymd').'.jpg',
            UploadAttachment::propertyWareFileName($attachment)
        );
    }
}
