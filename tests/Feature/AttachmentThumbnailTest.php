<?php

namespace Tests\Feature;

use App\Jobs\UploadAttachment;
use App\Models\Attachments;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AttachmentThumbnailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Fake only the PropertyWare upload so GenerateThumbnail still runs
        // inline on the sync connection. A bare Queue::fake() would swallow it
        // and every assertion below would pass vacuously.
        Queue::fake([UploadAttachment::class]);
        Storage::fake('public');
    }

    private function upload(UploadedFile $file, string $title = 'Kitchen leak'): TestResponse
    {
        return $this->actingAs(User::factory()->create())->post(route('api.attachments.store'), [
            'title' => $title,
            'type' => 'attachment',
            'work_order_id' => WorkOrder::factory()->create()->id,
            'filename' => $file,
        ]);
    }

    public function test_uploading_an_image_generates_a_webp_thumbnail(): void
    {
        $response = $this->upload(UploadedFile::fake()->image('photo.jpg', 1600, 1200));

        $response->assertSessionHasNoErrors();

        $attachment = Attachments::sole();

        $this->assertNotNull($attachment->thumb_path);
        $this->assertMatchesRegularExpression('#^attachments/thumbs/.+\.webp$#', $attachment->thumb_path);
        Storage::disk('public')->assertExists($attachment->thumb_path);

        $info = getimagesizefromstring(Storage::disk('public')->get($attachment->thumb_path));
        $this->assertNotFalse($info);
        $this->assertSame('image/webp', $info['mime']);
        $this->assertSame([400, 300], [$info[0], $info[1]]);
    }

    public function test_the_thumbnail_is_much_smaller_than_the_original(): void
    {
        $this->upload(UploadedFile::fake()->image('photo.jpg', 2400, 1800));

        $attachment = Attachments::sole();
        $disk = Storage::disk('public');

        $this->assertLessThan($disk->size($attachment->filename), $disk->size($attachment->thumb_path));
    }

    public function test_the_thumbnail_url_points_at_the_thumbnail(): void
    {
        $this->upload(UploadedFile::fake()->image('photo.jpg', 800, 800));

        $attachment = Attachments::sole();

        $this->assertSame(asset('storage/'.$attachment->thumb_path), $attachment->thumbnail_url);
        $this->assertNotSame($attachment->attachment_url, $attachment->thumbnail_url);
    }

    public function test_a_small_image_is_not_upscaled(): void
    {
        $this->upload(UploadedFile::fake()->image('tiny.png', 100, 100));

        $attachment = Attachments::sole();

        $info = getimagesizefromstring(Storage::disk('public')->get($attachment->thumb_path));
        $this->assertSame([100, 100], [$info[0], $info[1]]);
    }

    public function test_a_pdf_gets_no_thumbnail_and_falls_back_to_the_original(): void
    {
        $response = $this->upload(UploadedFile::fake()->create('scope.pdf', 128, 'application/pdf'));

        $response->assertSessionHasNoErrors();

        $attachment = Attachments::sole();

        $this->assertNull($attachment->thumb_path);
        $this->assertSame($attachment->attachment_url, $attachment->thumbnail_url);
    }

    public function test_a_video_upload_is_skipped_without_failing(): void
    {
        $response = $this->upload(UploadedFile::fake()->create('walkthrough.mp4', 512, 'video/mp4'));

        $response->assertSessionHasNoErrors();
        $this->assertNull(Attachments::sole()->thumb_path);
    }

    public function test_a_heic_upload_does_not_fail_the_request(): void
    {
        // UploadedMediaFile accepts HEIC by extension, but GD cannot decode it.
        $response = $this->upload(UploadedFile::fake()->create('photo.heic', 512, 'application/octet-stream'));

        $response->assertSessionHasNoErrors();

        $attachment = Attachments::sole();
        $this->assertNull($attachment->thumb_path);
        $this->assertSame($attachment->attachment_url, $attachment->thumbnail_url);
    }

    public function test_multiple_store_generates_a_thumbnail_per_file(): void
    {
        $workOrder = WorkOrder::factory()->create();

        $response = $this->actingAs(User::factory()->create())->post(route('api.attachments.multiple_store'), [
            'title' => 'Before photos',
            'type' => 'before',
            'work_order_id' => $workOrder->id,
            'tenant_portal' => 'Yes',
            'owner_portal' => 'Yes',
            'files' => [
                ['file' => UploadedFile::fake()->image('one.jpg', 900, 600), 'name' => 'one.jpg', 'type' => 'image/jpeg'],
                ['file' => UploadedFile::fake()->image('two.png', 900, 600), 'name' => 'two.png', 'type' => 'image/png'],
                ['file' => UploadedFile::fake()->create('three.pdf', 64, 'application/pdf'), 'name' => 'three.pdf', 'type' => 'application/pdf'],
            ],
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame(3, Attachments::count());
        $this->assertSame(2, Attachments::whereNotNull('thumb_path')->count());

        foreach (Attachments::whereNotNull('thumb_path')->get() as $attachment) {
            Storage::disk('public')->assertExists($attachment->thumb_path);
        }
    }

    public function test_deleting_an_attachment_removes_its_thumbnail(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('api.attachments.store'), [
            'title' => 'Kitchen leak',
            'type' => 'attachment',
            'work_order_id' => WorkOrder::factory()->create()->id,
            'filename' => UploadedFile::fake()->image('photo.jpg', 800, 600),
        ]);

        $attachment = Attachments::sole();
        $thumbPath = $attachment->thumb_path;
        Storage::disk('public')->assertExists($thumbPath);

        $this->actingAs($user)->delete(route('api.attachments.destroy', $attachment));

        Storage::disk('public')->assertMissing($thumbPath);
        Storage::disk('public')->assertMissing($attachment->filename);
    }

    public function test_a_row_without_a_thumbnail_still_renders(): void
    {
        // Rows created before this feature shipped: no backfill was run, so the
        // accessor must keep pointing at the full-size original.
        $attachment = Attachments::create([
            'title' => 'Legacy photo',
            'filename' => 'attachments/legacy.jpg',
            'filetype' => 'image/jpeg',
            'type' => 'attachment',
            'work_order_id' => WorkOrder::factory()->create()->id,
            'user_id' => User::factory()->create()->id,
        ]);

        $this->assertNull($attachment->thumb_path);
        $this->assertSame(asset('storage/attachments/legacy.jpg'), $attachment->thumbnail_url);
    }
}
