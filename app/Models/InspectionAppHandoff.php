<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One work order's hand-off to the technicians' inspection app: the outbox
 * row behind App\Services\InspectionApp\HandoffService. pending = queued or
 * retrying, sent = the app accepted it (external_id is its record), failed =
 * the app refused it and nothing will retry until the work order changes,
 * skipped = it no longer qualifies.
 */
class InspectionAppHandoff extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $guarded = [];

    protected $casts = [
        'attempts' => 'integer',
        'last_attempt_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
