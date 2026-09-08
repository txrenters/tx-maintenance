<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A PropertyWare lease. Synced by `sync:leases`; nothing in the app writes one.
 *
 * Carries no global scope on purpose: which invoices a user may see is already
 * settled upstream by InvoiceScope, so a scope here would narrow a lookup that
 * has already been authorized.
 */
class Lease extends Model
{
    protected $guarded = [];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'synced_at' => 'datetime',
    ];

    /**
     * Buildings join on the PropertyWare id, not the local primary key, the
     * same way WorkOrder::building() does.
     */
    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class, 'building_id', 'propertyware_id');
    }

    /**
     * Compared case-insensitively because PropertyWare varies picklist casing.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereRaw('LOWER(TRIM(status)) = ?', ['active']);
    }
}
