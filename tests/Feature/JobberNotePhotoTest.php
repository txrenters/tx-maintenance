<?php

namespace Tests\Feature;

use App\Jobs\UploadAttachment;
use App\Models\Attachments;
use App\Models\Jobber;
use App\Models\JobberClient;
use App\Models\JobberProperty;
use App\Models\JobberToken;
use App\Models\WorkOrder;
use App\Models\WorkOrderJobberNote;
use App\Models\WorkOrderJobberNoteFile;
use App\Services\JobberNoteSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class JobberNotePhotoTest extends TestCase
{
    use RefreshDatabase;

    private const PHOTO_URL = 'https://jobber-files.test/signed/photo.jpg?X-Amz-Expires=3600';

    /** The GraphQL endpoint, kept apart from the file host so each keeps its own stub. */
    private const GRAPHQL = 'https://api.getjobber.com/*';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.jobber.note_sync_enabled', true);
        Storage::fake('public');

        JobberToken::query()->create([
            'access_token' => 'stored-token',
            'refresh_token' => 'stored-refresh',
            'expires_at' => now()->addHour(),
        ]);
    }

    private function linkedWorkOrder(): WorkOrder
    {
        $client = JobberClient::query()->create([
            'jobber_id' => 'client-1',
            'name' => '17026 Cypresswood Glen Trl',
            'jobber_web_uri' => 'https://example.test',
        ]);

        $property = JobberProperty::query()->create([
            'jobber_id' => 'property-1',
            'jobber_client_id' => $client->id,
        ]);

        Jobber::query()->create([
            'jobber_id' => 'job-gid-1',
            'job_number' => '20052',
            'title' => 'Leaking water heater - #43967',
            'job_status' => 'active',
            'jobber_client_id' => $client->id,
            'jobber_property_id' => $property->id,
        ]);

        return WorkOrder::factory()->create([
            'work_order_no' => 43967,
            'jobber_job_gid' => 'job-gid-1',
        ]);
    }

    /**
     * @param  array<string, mixed>  $file
     * @return array<string, mixed>
     */
    private function notesPageWithFile(array $file): array
    {
        return [
            'data' => [
                'job' => [
                    'id' => 'job-gid-1',
                    'notes' => [
                        'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                        'nodes' => [[
                            '__typename' => 'JobNote',
                            'id' => 'note-1',
                            'message' => 'Before and after.',
                            'createdAt' => '2026-09-20T15:04:05Z',
                            'lastEditedAt' => null,
                            'pinned' => false,
                            'createdBy' => ['__typename' => 'User', 'id' => 'u-1', 'name' => ['full' => 'Marco Ruiz']],
                            'fileAttachments' => ['nodes' => [$file]],
                        ]],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function fileNode(array $overrides = []): array
    {
        return array_merge([
            'id' => 'file-1',
            'fileName' => 'heater.jpg',
            'contentType' => 'image/jpeg',
            'fileSize' => 12,
            'status' => 'READY',
            'url' => self::PHOTO_URL,
        ], $overrides);
    }

    private function sync(): JobberNoteSyncService
    {
        return app(JobberNoteSyncService::class);
    }

    public function test_a_ready_photo_lands_on_the_attachments_tab(): void
    {
        $workOrder = $this->linkedWorkOrder();

        Http::fake([
            self::PHOTO_URL => Http::response('binary-image', 200, ['Content-Type' => 'image/jpeg']),
            self::GRAPHQL => Http::response($this->notesPageWithFile($this->fileNode())),
        ]);

        $this->sync()->syncWorkOrder($workOrder);

        $attachment = Attachments::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($workOrder->id, $attachment->work_order_id);
        $this->assertSame('heater.jpg', $attachment->title);
        $this->assertSame('image/jpeg', $attachment->filetype);
        $this->assertSame('attachment', $attachment->type);
        $this->assertSame('file-1', $attachment->jobber_note_file_gid);
        $this->assertStringStartsWith('attachments/', $attachment->filename);
        Storage::disk('public')->assertExists($attachment->filename);

        // The link back to the note that brought it.
        $link = WorkOrderJobberNoteFile::query()->firstOrFail();
        $this->assertSame($attachment->id, $link->attachment_id);
    }

    /**
     * These are the crew's internal working photos. The two portals filter on
     * exactly these columns, so getting them wrong shows an owner or tenant
     * every picture a technician takes.
     */
    public function test_a_jobber_photo_is_not_portal_content(): void
    {
        $workOrder = $this->linkedWorkOrder();

        Http::fake([
            self::PHOTO_URL => Http::response('binary-image', 200, ['Content-Type' => 'image/jpeg']),
            self::GRAPHQL => Http::response($this->notesPageWithFile($this->fileNode())),
        ]);

        $this->sync()->syncWorkOrder($workOrder);

        $attachment = Attachments::withoutGlobalScopes()->firstOrFail();
        $this->assertFalse((bool) $attachment->is_publish_to_owner_portal);
        $this->assertFalse((bool) $attachment->is_publish_to_tenant_portal);
        // Not a label: it is a second way into the tenant portal.
        $this->assertFalse((bool) $attachment->uploaded_via_tenant_portal);
    }

    /**
     * Nothing pushes an attachment to PropertyWare on insert, but the daily
     * repair sweep picks up every row whose pw_file_name is null -- which a
     * Jobber photo's always is. Without the exclusion it would send the
     * crew's whole camera roll to PropertyWare a day after it arrived.
     */
    public function test_the_propertyware_repair_sweep_skips_jobber_photos(): void
    {
        Queue::fake();

        $workOrder = $this->linkedWorkOrder();

        Http::fake([
            self::PHOTO_URL => Http::response('binary-image', 200, ['Content-Type' => 'image/jpeg']),
            self::GRAPHQL => Http::response($this->notesPageWithFile($this->fileNode())),
        ]);

        $this->sync()->syncWorkOrder($workOrder);

        // Age it past the command's one-hour grace period.
        Attachments::withoutGlobalScopes()->update(['created_at' => now()->subDay()]);

        $this->artisan('attachments:repair-pw-uploads')->assertExitCode(0);

        Queue::assertNotPushed(UploadAttachment::class);
    }

    /**
     * Jobber is still making its renditions, so the URLs are not usable yet.
     * The note must still import, and the photo must arrive on a later run.
     */
    public function test_a_processing_photo_is_skipped_and_picked_up_later(): void
    {
        $workOrder = $this->linkedWorkOrder();

        Http::fake([
            self::PHOTO_URL => Http::response('binary-image', 200, ['Content-Type' => 'image/jpeg']),
            self::GRAPHQL => Http::sequence()
                ->push($this->notesPageWithFile($this->fileNode(['status' => 'PROCESSING', 'url' => null])))
                ->push($this->notesPageWithFile($this->fileNode())),
        ]);

        $this->sync()->syncWorkOrder($workOrder);

        $this->assertSame(1, WorkOrderJobberNote::query()->count());
        $this->assertSame(0, Attachments::withoutGlobalScopes()->count());

        $this->sync()->syncWorkOrder($workOrder);

        $this->assertSame(1, Attachments::withoutGlobalScopes()->count());
    }

    /**
     * The dedupe that keeps a re-sync cheap and the tab free of copies.
     */
    public function test_the_same_photo_is_not_downloaded_twice(): void
    {
        $workOrder = $this->linkedWorkOrder();

        Http::fake([
            self::PHOTO_URL => Http::response('binary-image', 200, ['Content-Type' => 'image/jpeg']),
            self::GRAPHQL => Http::response($this->notesPageWithFile($this->fileNode())),
        ]);

        $this->sync()->syncWorkOrder($workOrder);
        $this->sync()->syncWorkOrder($workOrder);
        $this->sync()->syncWorkOrder($workOrder);

        $this->assertSame(1, Attachments::withoutGlobalScopes()->count());
        $this->assertSame(1, WorkOrderJobberNoteFile::query()->count());

        $downloads = 0;
        Http::recorded(function ($request) use (&$downloads) {
            if (str_starts_with($request->url(), 'https://jobber-files.test/')) {
                $downloads++;
            }
        });

        $this->assertSame(1, $downloads);
    }

    /**
     * A photo that will not download must never cost us the note it is on.
     */
    public function test_a_failed_photo_download_does_not_fail_the_note(): void
    {
        $workOrder = $this->linkedWorkOrder();

        Http::fake([
            self::PHOTO_URL => Http::response('gone', 404),
            self::GRAPHQL => Http::response($this->notesPageWithFile($this->fileNode())),
        ]);

        $count = $this->sync()->syncWorkOrder($workOrder);

        $this->assertSame(1, $count);
        $this->assertSame(1, WorkOrderJobberNote::query()->count());
        $this->assertSame(0, Attachments::withoutGlobalScopes()->count());
    }

    /**
     * A note deleted in Jobber takes its photos off the tab with it, rather
     * than leaving pictures nothing explains.
     */
    public function test_removing_a_note_clears_its_photos(): void
    {
        $workOrder = $this->linkedWorkOrder();

        Http::fake([
            self::PHOTO_URL => Http::response('binary-image', 200, ['Content-Type' => 'image/jpeg']),
            self::GRAPHQL => Http::sequence()
                ->push($this->notesPageWithFile($this->fileNode()))
                ->push([
                    'data' => ['job' => ['id' => 'job-gid-1', 'notes' => [
                        'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                        'nodes' => [],
                    ]]],
                ]),
        ]);

        $this->sync()->syncWorkOrder($workOrder);

        $stored = Attachments::withoutGlobalScopes()->value('filename');
        Storage::disk('public')->assertExists($stored);

        $this->sync()->syncWorkOrder($workOrder);

        $this->assertSame(0, WorkOrderJobberNote::query()->count());
        $this->assertSame(0, Attachments::withoutGlobalScopes()->count());
        Storage::disk('public')->assertMissing($stored);
    }

    /**
     * Files.vue calls filetype.startsWith() unguarded, so a null blanks the
     * whole tab rather than hiding one tile.
     */
    public function test_a_photo_with_no_content_type_still_gets_a_filetype(): void
    {
        $workOrder = $this->linkedWorkOrder();

        Http::fake([
            self::PHOTO_URL => Http::response('binary-image', 200),
            self::GRAPHQL => Http::response($this->notesPageWithFile($this->fileNode(['contentType' => null]))),
        ]);

        $this->sync()->syncWorkOrder($workOrder);

        $this->assertNotNull(Attachments::withoutGlobalScopes()->value('filetype'));
    }
}
