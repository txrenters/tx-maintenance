<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class WorkOrderNotes extends Model
{
    protected $table = 'work_order_notes';

    /**
     * What the note dialog's name picker sends when the person typing is not
     * on the technician roster (THMP office staff on the shared login).
     */
    public const TECHNICIAN_NOT_LISTED = 'not_listed';

    protected $guarded = [];

    protected $casts = [
        'is_private' => 'boolean',
        'is_default' => 'boolean',
    ];

    /**
     * Serialised with every note so the dashboard can show when it was written.
     *
     * @var list<string>
     */
    protected $appends = ['added_at'];

    /**
     * When the note was written, as an ISO-8601 UTC instant.
     *
     * A note that came from PropertyWare carries PropertyWare's own note date;
     * created_at on that row is only when the sync copied it here. A dashboard
     * note never gets a PropertyWare date (the sync leaves rows with a user
     * alone), so its created_at is the real moment it was added. A blank or
     * unreadable PropertyWare date falls back to created_at rather than failing
     * the whole notes payload.
     */
    protected function addedAt(): Attribute
    {
        return Attribute::make(
            get: function (): ?string {
                if (! blank($this->date)) {
                    try {
                        return Carbon::parse((string) $this->date)->utc()->toISOString();
                    } catch (\Throwable) {
                        // Not a date PropertyWare should have sent; use the row's own time.
                    }
                }

                return $this->created_at?->utc()->toISOString();
            },
        );
    }

    /**
     * Whether this database can keep the technician a THMP note was signed
     * with. The name picker stays off until the columns exist, so the code can
     * be live before its migration has run without failing a single note.
     */
    public static function recordsTechnician(): bool
    {
        return Schema::hasColumn('work_order_notes', 'technician_name')
            && Schema::hasColumn('work_order_notes', 'technician_id');
    }

    /**
     * Whether this login has to say which technician is typing a note.
     *
     * True for a login a crew shares: the THMP vendor login, and the logins
     * listed by email in services.work_order.note_technician_logins (THMP's
     * crew works from a staff-type login no vendor rule can recognise). The
     * one rule behind both the picker the dialog shows and the check the
     * save makes, so a login is never asked for a name it was not shown.
     */
    public static function asksTechnicianOf(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        $email = Str::lower(trim((string) $user->email));

        $listed = $email !== '' && collect(explode(',', (string) config('services.work_order.note_technician_logins', '')))
            ->map(fn (string $login): string => Str::lower(trim($login)))
            ->contains($email);

        if (! $listed && ! ($user->hasRole('vendor') && $user->vendor?->isThmp())) {
            return false;
        }

        return self::recordsTechnician();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function work_order(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /**
     * Notes the given user may read. Staff and vendors see everything; anyone
     * else (owner and tenant logins) only sees notes PropertyWare would also
     * show on its portals, i.e. the non-private ones. Fails closed for a user
     * with no role at all.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user?->hasAnyRole(['admin', 'woc', 'accounting', 'vendor'])) {
            return $query;
        }

        return $query->where('is_private', false);
    }
}
