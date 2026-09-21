<?php

namespace Tests\Feature;

use App\Models\Jobber;
use App\Models\JobberClient;
use App\Models\JobberProperty;
use App\Models\JobberToken;
use App\Models\WorkOrder;
use App\Models\WorkOrderJobberNote;
use App\Services\JobberNoteSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class JobberNoteSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.jobber.note_sync_enabled', true);

        JobberToken::query()->create([
            'access_token' => 'stored-token',
            'refresh_token' => 'stored-refresh',
            'expires_at' => now()->addHour(),
        ]);
    }

    /**
     * A work order tied to a Jobber job by the stored GID, the ordinary case
     * for a THMP work order this app created the job for.
     */
    private function linkedWorkOrder(string $gid = 'job-gid-1', int $workOrderNo = 43967): WorkOrder
    {
        $this->makeJob($gid);

        return WorkOrder::factory()->create([
            'work_order_no' => $workOrderNo,
            'jobber_job_gid' => $gid,
        ]);
    }

    /**
     * A Jobber job with the client and property rows it cannot exist without.
     */
    private function makeJob(string $gid): Jobber
    {
        $client = JobberClient::query()->create([
            'jobber_id' => 'client-'.uniqid(),
            'name' => '17026 Cypresswood Glen Trl',
            'jobber_web_uri' => 'https://example.test',
        ]);

        $property = JobberProperty::query()->create([
            'jobber_id' => 'property-'.uniqid(),
            'jobber_client_id' => $client->id,
        ]);

        return Jobber::query()->create([
            'jobber_id' => $gid,
            'job_number' => '20052',
            'title' => 'Leaking water heater - #43967',
            'job_status' => 'active',
            'jobber_client_id' => $client->id,
            'jobber_property_id' => $property->id,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return array<string, mixed>
     */
    private function notesPage(array $nodes, bool $hasNextPage = false, ?string $endCursor = null): array
    {
        return [
            'data' => [
                'job' => [
                    'id' => 'job-gid-1',
                    'notes' => [
                        'pageInfo' => ['hasNextPage' => $hasNextPage, 'endCursor' => $endCursor],
                        'nodes' => $nodes,
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function noteNode(string $id, string $message, array $overrides = []): array
    {
        return array_merge([
            '__typename' => 'JobNote',
            'id' => $id,
            'message' => $message,
            'createdAt' => '2026-09-20T15:04:05Z',
            'lastEditedAt' => null,
            'pinned' => false,
            'createdBy' => ['__typename' => 'User', 'id' => 'u-1', 'name' => ['full' => 'Marco Ruiz']],
            'fileAttachments' => ['nodes' => []],
        ], $overrides);
    }

    private function sync(): JobberNoteSyncService
    {
        return app(JobberNoteSyncService::class);
    }

    public function test_it_imports_job_notes_onto_the_work_order(): void
    {
        $workOrder = $this->linkedWorkOrder();

        Http::fake(['*' => Http::response($this->notesPage([
            $this->noteNode('note-1', 'Replaced the thermocouple.'),
            $this->noteNode('note-2', 'Tenant was home, all good.'),
        ]))]);

        $count = $this->sync()->syncWorkOrder($workOrder);

        $this->assertSame(2, $count);
        $this->assertSame(2, WorkOrderJobberNote::query()->count());

        $note = WorkOrderJobberNote::query()->where('jobber_note_gid', 'note-1')->firstOrFail();
        $this->assertSame('Replaced the thermocouple.', $note->message);
        $this->assertSame('Marco Ruiz', $note->author_name);
        $this->assertSame('User', $note->author_type);
        $this->assertSame('JobNote', $note->note_type);
        $this->assertSame($workOrder->id, $note->work_order_id);
        $this->assertSame('2026-09-20T15:04:05.000000Z', $note->jobber_created_at->toISOString());
    }

    /**
     * The point of the feature: syncing again must not double anything.
     */
    public function test_running_twice_does_not_duplicate_notes(): void
    {
        $workOrder = $this->linkedWorkOrder();

        Http::fake(['*' => Http::response($this->notesPage([
            $this->noteNode('note-1', 'Replaced the thermocouple.'),
            $this->noteNode('note-2', 'Tenant was home, all good.'),
        ]))]);

        $this->sync()->syncWorkOrder($workOrder);
        $this->sync()->syncWorkOrder($workOrder);
        $this->sync()->syncWorkOrder($workOrder);

        $this->assertSame(2, WorkOrderJobberNote::query()->count());
    }

    public function test_an_edited_note_is_updated_not_duplicated(): void
    {
        $workOrder = $this->linkedWorkOrder();

        // A second Http::fake() merges rather than replaces, so a run that
        // must see a different answer the second time needs a sequence.
        Http::fakeSequence()
            ->push($this->notesPage([
                $this->noteNode('note-1', 'Replaced the thermocouple.'),
            ]))
            ->push($this->notesPage([
                $this->noteNode('note-1', 'Replaced the thermocouple and the valve.', [
                    'lastEditedAt' => '2026-09-21T09:00:00Z',
                ]),
            ]));

        $this->sync()->syncWorkOrder($workOrder);
        $this->sync()->syncWorkOrder($workOrder);

        $this->assertSame(1, WorkOrderJobberNote::query()->count());

        $note = WorkOrderJobberNote::query()->firstOrFail();
        $this->assertSame('Replaced the thermocouple and the valve.', $note->message);
        $this->assertSame('2026-09-21T09:00:00.000000Z', $note->jobber_last_edited_at->toISOString());
        // The original write time survives the edit, so the note keeps its
        // place in the list instead of jumping to the top.
        $this->assertSame('2026-09-20T15:04:05.000000Z', $note->jobber_created_at->toISOString());
    }

    public function test_client_quote_and_request_notes_are_imported_with_their_type(): void
    {
        $workOrder = $this->linkedWorkOrder();

        Http::fake(['*' => Http::response($this->notesPage([
            $this->noteNode('note-1', 'Crew note.'),
            ['__typename' => 'ClientNote', 'id' => 'note-2', 'message' => 'Client called.', 'createdAt' => '2026-09-20T16:00:00Z', 'lastEditedAt' => null],
            ['__typename' => 'QuoteNote', 'id' => 'note-3', 'message' => 'Quote sent.', 'createdAt' => '2026-09-20T17:00:00Z', 'lastEditedAt' => null],
            ['__typename' => 'RequestNote', 'id' => 'note-4', 'message' => 'Request logged.', 'createdAt' => '2026-09-20T18:00:00Z', 'lastEditedAt' => null],
        ]))]);

        $this->sync()->syncWorkOrder($workOrder);

        $this->assertSame(4, WorkOrderJobberNote::query()->count());
        $this->assertSame('ClientNote', WorkOrderJobberNote::query()->where('jobber_note_gid', 'note-2')->value('note_type'));
        $this->assertSame('QuoteNote', WorkOrderJobberNote::query()->where('jobber_note_gid', 'note-3')->value('note_type'));
        $this->assertSame('RequestNote', WorkOrderJobberNote::query()->where('jobber_note_gid', 'note-4')->value('note_type'));
    }

    public function test_a_note_deleted_in_jobber_is_removed_locally(): void
    {
        $workOrder = $this->linkedWorkOrder();

        Http::fakeSequence()
            ->push($this->notesPage([
                $this->noteNode('note-1', 'Kept.'),
                $this->noteNode('note-2', 'Deleted in Jobber later.'),
            ]))
            ->push($this->notesPage([
                $this->noteNode('note-1', 'Kept.'),
            ]));

        $this->sync()->syncWorkOrder($workOrder);
        $this->assertSame(2, WorkOrderJobberNote::query()->count());

        $this->sync()->syncWorkOrder($workOrder);

        $this->assertSame(1, WorkOrderJobberNote::query()->count());
        $this->assertSame('note-1', WorkOrderJobberNote::query()->value('jobber_note_gid'));
    }

    /**
     * A fetch that did not complete says nothing about what Jobber still
     * holds, so it must never be read as "these notes are gone".
     */
    public function test_a_failed_page_deletes_nothing(): void
    {
        $workOrder = $this->linkedWorkOrder();

        Http::fakeSequence()
            ->push($this->notesPage([
                $this->noteNode('note-1', 'Written before the outage.'),
            ]))
            ->push('upstream is down', 500);

        $this->sync()->syncWorkOrder($workOrder);
        $this->assertSame(1, WorkOrderJobberNote::query()->count());

        $result = $this->sync()->syncWorkOrder($workOrder);

        $this->assertNull($result);
        $this->assertSame(1, WorkOrderJobberNote::query()->count());
    }

    /**
     * A second page must be walked, and its notes kept, before anything is
     * judged missing.
     */
    public function test_it_walks_every_page_before_deleting(): void
    {
        $workOrder = $this->linkedWorkOrder();

        Http::fakeSequence()
            ->push($this->notesPage([$this->noteNode('note-1', 'Page one.')], true, 'cursor-1'))
            ->push($this->notesPage([$this->noteNode('note-2', 'Page two.')]));

        $count = $this->sync()->syncWorkOrder($workOrder);

        $this->assertSame(2, $count);
        $this->assertSame(2, WorkOrderJobberNote::query()->count());
    }

    public function test_a_work_order_with_no_jobber_job_is_skipped(): void
    {
        $workOrder = WorkOrder::factory()->create([
            'work_order_no' => 44001,
            'jobber_job_gid' => null,
        ]);

        Http::fake();

        $this->assertNull($this->sync()->syncWorkOrder($workOrder));
        Http::assertNothingSent();
    }

    public function test_the_same_job_on_two_work_orders_gives_each_its_own_copy(): void
    {
        $first = $this->linkedWorkOrder();
        $second = WorkOrder::factory()->create([
            'work_order_no' => 43968,
            'jobber_job_gid' => 'job-gid-1',
        ]);

        Http::fake(['*' => Http::response($this->notesPage([
            $this->noteNode('note-1', 'Shared job, two work orders.'),
        ]))]);

        $this->sync()->syncWorkOrder($first);
        $this->sync()->syncWorkOrder($second);

        $this->assertSame(2, WorkOrderJobberNote::query()->count());
        $this->assertSame(1, WorkOrderJobberNote::query()->where('work_order_id', $first->id)->count());
        $this->assertSame(1, WorkOrderJobberNote::query()->where('work_order_id', $second->id)->count());
    }

    public function test_the_sync_is_a_no_op_when_disabled(): void
    {
        config()->set('services.jobber.note_sync_enabled', false);

        $workOrder = $this->linkedWorkOrder();
        Http::fake();

        $this->assertNull($this->sync()->syncWorkOrder($workOrder));
        Http::assertNothingSent();
        $this->assertSame(0, WorkOrderJobberNote::query()->count());
    }

    /**
     * Jobber rejecting the photo selection must cost us the photos, not the
     * notes: the query is re-sent without it.
     */
    public function test_a_rejected_photo_selection_still_imports_the_notes(): void
    {
        $workOrder = $this->linkedWorkOrder();

        Http::fakeSequence()
            ->push(['errors' => [['message' => "Field 'fileAttachments' doesn't exist on type 'JobNote'"]]])
            ->push($this->notesPage([$this->noteNode('note-1', 'Still imported.')]));

        $count = $this->sync()->syncWorkOrder($workOrder);

        $this->assertSame(1, $count);
        $this->assertSame('Still imported.', WorkOrderJobberNote::query()->value('message'));
    }

    /**
     * A permissions or scope refusal must not be read as an empty job, or the
     * first run after a scope lapse would wipe every note we hold.
     */
    public function test_a_refused_query_deletes_nothing(): void
    {
        $workOrder = $this->linkedWorkOrder();

        Http::fakeSequence()
            ->push($this->notesPage([
                $this->noteNode('note-1', 'Held before the scope lapsed.'),
            ]))
            ->push(['errors' => [['message' => 'You do not have permission to read notes']]]);

        $this->sync()->syncWorkOrder($workOrder);

        $result = $this->sync()->syncWorkOrder($workOrder);

        $this->assertNull($result);
        $this->assertSame(1, WorkOrderJobberNote::query()->count());
    }
}
