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
