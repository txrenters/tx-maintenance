<?php

namespace Tests\Feature;

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

    public function test_a_ready_photo_is_downloaded_to_the_public_disk(): void
    {
        $workOrder = $this->linkedWorkOrder();

        Http::fake([
            self::PHOTO_URL => Http::response('binary-image', 200, ['Content-Type' => 'image/jpeg']),
            self::GRAPHQL => Http::response($this->notesPageWithFile($this->fileNode())),
        ]);

        $this->sync()->syncWorkOrder($workOrder);

        $file = WorkOrderJobberNoteFile::query()->firstOrFail();
        $this->assertNotNull($file->filename);
        $this->assertStringStartsWith('jobber-note-photos/', $file->filename);
        $this->assertStringEndsWith('.jpg', $file->filename);
        Storage::disk('public')->assertExists($file->filename);
        $this->assertSame('heater.jpg', $file->file_name);
        $this->assertSame('image/jpeg', $file->content_type);
        $this->assertTrue($file->isImage());
    }

    /**
     * Jobber is still making its renditions, so the URLs are not usable yet.
     * The note must still import, and the photo must arrive on a later run.
     */
    public function test_a_processing_photo_is_skipped_and_picked_up_later(): void
    {
        $workOrder = $this->linkedWorkOrder();

        // The photo URL keeps its own stub; only the notes answer changes, so
        // the GraphQL endpoint gets the sequence and the file host does not.
        Http::fake([
            self::PHOTO_URL => Http::response('binary-image', 200, ['Content-Type' => 'image/jpeg']),
            self::GRAPHQL => Http::sequence()
                ->push($this->notesPageWithFile($this->fileNode(['status' => 'PROCESSING', 'url' => null])))
                ->push($this->notesPageWithFile($this->fileNode())),
        ]);

        $this->sync()->syncWorkOrder($workOrder);

        $this->assertSame(1, WorkOrderJobberNote::query()->count());
        $this->assertSame(0, WorkOrderJobberNoteFile::query()->whereNotNull('filename')->count());

        $this->sync()->syncWorkOrder($workOrder);

        $file = WorkOrderJobberNoteFile::query()->firstOrFail();
        $this->assertNotNull($file->filename);
        Storage::disk('public')->assertExists($file->filename);
    }

    /**
     * The dedupe that keeps a re-sync cheap: the bytes are fetched once.
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
        $this->assertNull(WorkOrderJobberNoteFile::query()->value('filename'));
    }

    /**
     * A note deleted in Jobber takes its photos with it, rather than leaving
     * files on disk with nothing pointing at them.
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

        $stored = WorkOrderJobberNoteFile::query()->value('filename');
        Storage::disk('public')->assertExists($stored);

        $this->sync()->syncWorkOrder($workOrder);

        $this->assertSame(0, WorkOrderJobberNote::query()->count());
        $this->assertSame(0, WorkOrderJobberNoteFile::query()->count());
        Storage::disk('public')->assertMissing($stored);
    }
}
