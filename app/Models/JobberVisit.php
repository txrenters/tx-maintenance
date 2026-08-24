<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobberVisit extends Model
{
    protected $table = 'jobber_visits';

    protected $guarded = [];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'assigned_to' => 'array',
            'is_complete' => 'boolean',
            'notified_14_days' => 'boolean',
            'notified_7_days' => 'boolean',
            'notified_3_days' => 'boolean',
            'notified_1_days' => 'boolean',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Jobber::class, 'jobber_job_id');
    }

    /**
     * The per-visit GraphQL selection both syncs add. One place, so the
     * fallback below stays uniform everywhere it is used.
     */
    public const ASSIGNED_USERS_QUERY = 'assignedUsers(first: 10) { nodes { id name { full } } }';

    /**
     * True when a GraphQL response rejected the assignedUsers selection —
     * the cue to retry the query without it instead of failing the sync.
     *
     * @param  array<string, mixed>|null  $json
     */
    public static function responseRejectsAssignedUsers(?array $json): bool
    {
        foreach (($json['errors'] ?? []) as $error) {
            if (str_contains((string) ($error['message'] ?? ''), 'assignedUsers')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Map a visit's assignedUsers from the Jobber API payload to the
     * [{id, name}, ...] shape stored in assigned_to. Null when the visit has
     * no assignees.
     *
     * @param  array<string, mixed>  $visitData
     * @return list<array{id: string|null, name: string}>|null
     */
    public static function assignedUsersFromApi(array $visitData): ?array
    {
        $users = collect($visitData['assignedUsers']['nodes'] ?? [])
            ->map(fn ($user) => [
                'id' => $user['id'] ?? null,
                'name' => trim((string) data_get($user, 'name.full')),
            ])
            ->filter(fn ($user) => $user['name'] !== '')
            ->values()
            ->all();

        return $users === [] ? null : $users;
    }

    public function scopeFilter($query, array $filter): void
    {
        if (! empty($filter['search'])) {
            $search = $filter['search'];

            $query
                ->whereHas('job', function ($q) use ($search) {
                    $q->where('job_number', $search);
                })
                ->orWhereAny([
                    'title',
                    'visit_status',
                    'instructions',
                ], 'LIKE', "%{$search}%");
        }
    }

    public function scopeTbp($query): void
    {
        $query->where(function ($q) {
            $q->whereRaw('LOWER(title) LIKE ?', ['%tenant benefit%'])
                ->orWhereRaw('LOWER(title) LIKE ?', ['%tbp%']);
        });

    }
}
