<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Archiving for the two invoice tables: an invoice is hidden from the normal
 * views but keeps its row and its uploaded file, and the office can restore
 * it. Shared so the work-order and Jobber invoice paths cannot drift apart.
 */
trait Archivable
{
    public function scopeArchived(Builder $query): void
    {
        $query->whereNotNull('archived_at');
    }

    public function scopeNotArchived(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by_user_id');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Hide the invoice. The stored file is deliberately left in place so a
     * restored invoice still opens.
     */
    public function archive(?User $user = null): void
    {
        $this->update([
            'archived_at' => now(),
            'archived_by_user_id' => $user?->id,
        ]);
    }

    public function unarchive(): void
    {
        $this->update([
            'archived_at' => null,
            'archived_by_user_id' => null,
        ]);
    }
}
