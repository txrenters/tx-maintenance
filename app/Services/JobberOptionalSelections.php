<?php

namespace App\Services;

/**
 * GraphQL selections the Jobber syncs ask for on top of the fields Jobber
 * has always served: who a visit is assigned to, the coordinates Jobber
 * holds for a property address, and a note's photos and pinned flag. Their
 * names come from schema references rather than a live call, so each one is
 * optional — when Jobber rejects it, the sync drops that selection for the
 * rest of the run and retries, and one unknown field never sinks an import
 * or a webhook.
 *
 * Matching is a substring test against the error message, so a field named
 * here has to be a word Jobber would only mention when complaining about
 * that selection. Anything shorter or more common than these would start
 * disabling itself on unrelated errors.
 */
class JobberOptionalSelections
{
    public const ASSIGNED_USERS = 'assignedUsers';

    public const COORDINATES = 'coordinates';

    public const NOTE_FILE_ATTACHMENTS = 'fileAttachments';

    public const NOTE_PINNED = 'pinned';

    /**
     * Jobber prices a query by what it could return, and nested three deep
     * inside the jobs page every assignee slot multiplies out across
     * thousands of visits: measured on prod, three slots on ten visits per
     * job put a 50-job page at 12,305 points against a 10,000 ceiling. THMP
     * assigns one technician, occasionally two, so two slots it is.
     *
     * @var array<string, string>
     */
    private const FRAGMENTS = [
        self::ASSIGNED_USERS => 'assignedUsers(first: 2) { nodes { id name { full } } }',
        self::COORDINATES => 'coordinates { latitude longitude }',
        // A note's photos. Ten slots per note: a crew note carries a handful
        // of phone pictures, and the count multiplies against every note on
        // the page. Dropping this leaves the notes themselves importing.
        self::NOTE_FILE_ATTACHMENTS => 'fileAttachments(first: 10) { nodes { id fileName contentType fileSize status url } }',
        self::NOTE_PINNED => 'pinned',
    ];

    /**
     * @var list<string>
     */
    private array $rejected = [];

    /**
     * The selection to splice into a query — empty once Jobber rejected it.
     */
    public function fragment(string $field): string
    {
        return in_array($field, $this->rejected, true) ? '' : self::FRAGMENTS[$field];
    }

    /**
     * The first still-active optional field a response's errors name, now
     * recorded as rejected — the cue to send the same query again without
     * it. Null when the errors are about something else, or there are none.
     *
     * @param  array<string, mixed>|null  $json
     */
    public function rejectedBy(?array $json): ?string
    {
        foreach (($json['errors'] ?? []) as $error) {
            $message = (string) ($error['message'] ?? '');

            foreach (array_keys(self::FRAGMENTS) as $field) {
                if (! in_array($field, $this->rejected, true) && str_contains($message, $field)) {
                    $this->rejected[] = $field;

                    return $field;
                }
            }
        }

        return null;
    }
}
