<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

/**
 * An in-house field technician's profile: who they are, what they do,
 * whether they are active, and the photo the tenant appointment text
 * attaches so the tenant knows who is coming to their door.
 *
 * Scheduling itself lives in the separate scheduling app — this roster
 * exists for the profile and the tenant-facing photo.
 */
class Technician extends Model
{
    use HasFactory;

    public const ROLE_INSPECTOR = 'inspector';

    public const ROLE_REPAIR = 'repair';

    public const ROLE_BOTH = 'both';

    /**
     * @var list<string>
     */
    public const ROLES = [self::ROLE_INSPECTOR, self::ROLE_REPAIR, self::ROLE_BOTH];

    /**
     * @var array<string, string>
     */
    public const ROLE_LABELS = [
        self::ROLE_INSPECTOR => 'Inspector',
        self::ROLE_REPAIR => 'Repair technician',
        self::ROLE_BOTH => 'Inspector + repair',
    ];

    protected $fillable = [
        'name',
        'role',
        'is_active',
        'phone',
        'email',
        'address',
        'specialty',
        'notes',
        'photo_path',
        'photo_content_type',
    ];

    protected $attributes = [
        'role' => self::ROLE_REPAIR,
        'is_active' => true,
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * The service schedules this technician was named on.
     */
    public function serviceSchedules(): BelongsToMany
    {
        return $this->belongsToMany(ServiceSchedule::class, 'service_schedule_technicians', 'technician_id', 'service_schedule_id')
            ->withTimestamps();
    }

    /**
     * The active roster as the names a THMP field note can be signed with,
     * in the order the note dialog lists them.
     *
     * @return Collection<int, array{id: int, name: string}>
     */
    public static function noteAuthorOptions(): Collection
    {
        return static::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Technician $technician): array => [
                'id' => $technician->id,
                'name' => $technician->name,
            ])
            ->values();
    }

    /**
     * Whether a photo is on file — the one the tenant appointment text
     * attaches so the tenant knows who is coming to their door.
     */
    public function hasPhoto(): bool
    {
        return filled($this->photo_path);
    }

    /**
     * Two-letter initials for the card avatar when no photo is on file:
     * the first letters of the first and last words of the name.
     */
    public function initials(): string
    {
        $words = preg_split('/\s+/', trim((string) $this->name)) ?: [];
        $words = array_values(array_filter($words));

        if ($words === []) {
            return '?';
        }

        $first = mb_substr($words[0], 0, 1);
        $last = count($words) > 1 ? mb_substr(end($words), 0, 1) : '';

        return mb_strtoupper($first.$last);
    }
}
