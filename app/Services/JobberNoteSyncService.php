<?php

namespace App\Services;

use App\Models\WorkOrder;
use App\Models\WorkOrderJobberNote;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Copies the notes on a work order's Jobber job onto the work order.
 *
 * The THMP crew writes its field notes, and attaches its photos, in Jobber.
 * None of that reached this dashboard, so a coordinator had to open Jobber in
 * another tab to find out what happened on site. This reads them; nothing is
 * ever written back.
 *
 * Idempotent by Jobber's note id: a note is upserted on
 * (work_order_id, jobber_note_gid), so a re-run updates in place and can
 * never double a note. An edit in Jobber rewrites the message but leaves
 * jobber_created_at alone, so editing an old note does not jump it to the top
 * of the list.
 *
 * Jobber publishes no note webhook — 46 topics, none of them about notes — so
 * this is polled rather than pushed.
 */
class JobberNoteSyncService
{
    /**
     * Notes per page. Jobber prices a query by what it could return and the
     * photo slots multiply against this, so the page stays small and the
     * cursor does the work.
     */
    private const NOTES_PAGE_SIZE = 20;

    /**
     * Pages to walk before giving up, so a cursor Jobber never advances
     * cannot spin here forever.
     */
    private const MAX_PAGES = 25;

    public function __construct(
        private JobberGraphqlClient $client,
        private JobberJobLocator $jobs,
        private JobberNotePhotoDownloader $photos,
    ) {}

    /**
     * Pull the Jobber job's notes onto this work order.
     *
     * Returns the number of notes seen, or null when the work order has no
     * Jobber job or the fetch failed — the two cases a caller must not read
     * as "Jobber has no notes for this".
     */
    public function syncWorkOrder(WorkOrder $workOrder): ?int
    {
        if (! config('services.jobber.note_sync_enabled')) {
            return null;
        }

        $job = $this->jobs->forWorkOrder($workOrder);

        if ($job === null || blank($job->jobber_id)) {
            return null;
        }

        $optional = new JobberOptionalSelections;
        $cursor = null;
        $seenGids = [];
        $complete = false;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = $this->client->post([
                'query' => $this->query((string) $job->jobber_id, $optional),
                'variables' => ['cursor' => $cursor],
            ]);

            if ($response === null) {
                break;
            }

            // An unknown optional field: drop it and ask for this same page
            // again rather than losing the notes over a photo selection.
            if ($optional->rejectedBy($response) !== null) {
                $page--;

                continue;
            }

            if (isset($response['errors'])) {
                Log::warning('Jobber refused the note query', [
                    'work_order_id' => $workOrder->id,
                    'jobber_job_gid' => $job->jobber_id,
                    'errors' => $response['errors'],
                ]);

                break;
            }

            $notes = $response['data']['job']['notes'] ?? null;

            if (! is_array($notes)) {
                Log::warning('Jobber returned no notes block for the job', [
                    'work_order_id' => $workOrder->id,
                    'jobber_job_gid' => $job->jobber_id,
                ]);

                break;
            }

            foreach ($notes['nodes'] ?? [] as $node) {
                $gid = $this->storeNote($workOrder, (string) $job->jobber_id, $node);

                if ($gid !== null) {
                    $seenGids[] = $gid;
                }
            }

            if (! ($notes['pageInfo']['hasNextPage'] ?? false)) {
                $complete = true;

                break;
            }

            $cursor = $notes['pageInfo']['endCursor'] ?? null;

            if ($cursor === null) {
                break;
            }
        }

        // Only a clean walk of every page may delete: a throttled or errored
        // fetch tells us nothing about what Jobber still holds, and treating
        // it as "not reported" would wipe the crew's notes.
        if ($complete) {
            $this->deleteVanished($workOrder, $seenGids);
        }

        return $complete ? count($seenGids) : null;
    }

    /**
     * Store one note from the union, and its photos. Returns its Jobber id,
     * or null for a node with nothing usable on it.
     *
     * @param  array<string, mixed>  $node
     */
    private function storeNote(WorkOrder $workOrder, string $jobGid, array $node): ?string
    {
        $gid = $node['id'] ?? null;

        if (! is_string($gid) || $gid === '') {
            return null;
        }

        $note = WorkOrderJobberNote::updateOrCreate(
            [
                'work_order_id' => $workOrder->id,
                'jobber_note_gid' => $gid,
            ],
            [
                'note_type' => (string) ($node['__typename'] ?? 'JobNote'),
                'jobber_job_gid' => $jobGid,
                'message' => isset($node['message']) ? (string) $node['message'] : null,
                'author_name' => $this->authorName($node['createdBy'] ?? null),
                'author_type' => isset($node['createdBy']['__typename'])
                    ? (string) $node['createdBy']['__typename']
                    : null,
                'pinned' => (bool) ($node['pinned'] ?? false),
                // Jobber's own timestamp, set once: an edit must not re-sort
                // the note to the top of the list.
                'jobber_created_at' => $this->time($node['createdAt'] ?? null),
                'jobber_last_edited_at' => $this->time($node['lastEditedAt'] ?? null),
            ]
        );

        foreach ($node['fileAttachments']['nodes'] ?? [] as $file) {
            if (is_array($file)) {
                $this->photos->store($note, $file);
            }
        }

        return $gid;
    }

    /**
     * Remove notes Jobber no longer lists, so a note deleted there stops
     * showing here. Only ever called after a complete page walk.
     *
     * @param  list<string>  $seenGids
     */
    private function deleteVanished(WorkOrder $workOrder, array $seenGids): void
    {
        $stale = WorkOrderJobberNote::query()
            ->where('work_order_id', $workOrder->id)
            ->when($seenGids !== [], fn ($query) => $query->whereNotIn('jobber_note_gid', $seenGids))
            ->get();

        foreach ($stale as $note) {
            // Unlink the downloaded photos before the rows cascade away,
            // otherwise the files linger on disk with nothing pointing at them.
            $this->photos->forget($note);
            $note->delete();
        }
    }

    /**
     * Who wrote the note. Jobber answers with one of three shapes — a staff
     * user, a client, or an application (which is how a note this app or
     * another integration wrote comes back).
     *
     * @param  array<string, mixed>|null  $createdBy
     */
    private function authorName(?array $createdBy): ?string
    {
        if ($createdBy === null) {
            return null;
        }

        $name = trim((string) (
            $createdBy['name']['full']
            ?? $createdBy['name']
            ?? trim(($createdBy['firstName'] ?? '').' '.($createdBy['lastName'] ?? ''))
        ));

        return $name !== '' ? $name : null;
    }

    /**
     * Jobber's ISO-8601 timestamps, or null when absent or unreadable — a bad
     * date must not cost us the note.
     */
    private function time(mixed $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The notes query for one job.
     *
     * The job id is interpolated and only the cursor is a variable, matching
     * ImportJobberJobs. `notes` returns a union, so every member needs its own
     * inline fragment: asking for `... on JobNote` alone would silently drop
     * the client, quote and request notes a job's feed also carries. Only
     * JobNote has photos.
     */
    private function query(string $jobGid, JobberOptionalSelections $optional): string
    {
        $pageSize = self::NOTES_PAGE_SIZE;
        $attachments = $optional->fragment(JobberOptionalSelections::NOTE_FILE_ATTACHMENTS);
        $pinned = $optional->fragment(JobberOptionalSelections::NOTE_PINNED);

        return <<<GRAPHQL
        query JobNotes(\$cursor: String) {
            job(id: "{$jobGid}") {
                id
                notes(first: {$pageSize}, after: \$cursor) {
                    pageInfo { hasNextPage endCursor }
                    nodes {
                        __typename
                        ... on JobNote {
                            id
                            message
                            createdAt
                            lastEditedAt
                            {$pinned}
                            createdBy {
                                __typename
                                ... on User { id name { full } }
                                ... on Client { id firstName lastName }
                                ... on Application { id name }
                            }
                            {$attachments}
                        }
                        ... on ClientNote { id message createdAt lastEditedAt }
                        ... on QuoteNote { id message createdAt lastEditedAt }
                        ... on RequestNote { id message createdAt lastEditedAt }
                    }
                }
            }
        }
        GRAPHQL;
    }
}
