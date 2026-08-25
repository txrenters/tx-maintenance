<?php

namespace App\Services;

/**
 * GraphQL selections the Jobber syncs ask for on top of the fields Jobber
 * has always served: who a visit is assigned to, and the coordinates Jobber
 * holds for a property address. Their names come from schema references
 * rather than a live call, so each one is optional — when Jobber rejects
 * it, the sync drops that selection for the rest of the run and retries,
 * and one unknown field never sinks an import or a webhook.
 */
class JobberOptionalSelections
{
    public const ASSIGNED_USERS = 'assignedUsers';

    public const COORDINATES = 'coordinates';

    /**
     * Jobber prices a query by the objects it could return, and a connection
     * costs its `first` times the child — nested three deep inside the jobs
     * page, every extra assignee slot multiplies out across thousands of
     * visits. THMP assigns one technician, occasionally two, so three is
     * plenty and keeps the page far under Jobber's 10,000-point ceiling.
     *
     * @var array<string, string>
     */
    private const FRAGMENTS = [
        self::ASSIGNED_USERS => 'assignedUsers(first: 3) { nodes { id name { full } } }',
        self::COORDINATES => 'coordinates { latitude longitude }',
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
