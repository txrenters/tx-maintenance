<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One work order's hand-off to the technicians' inspection app: the outbox
 * row behind App\Services\InspectionApp\HandoffService. pending = queued or
 * retrying, sent = the app accepted it (external_id is its record), failed =
 * the app refused it and nothing will retry until the work order changes,
 * skipped = it no longer qualifies, held = qualifies but the approval
 * filter is waiting (held_reason says why; rule names what let it go).
 */
class InspectionAppHandoff extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    /**
     * Qualifies as ours, but the approval filter has not let it go yet:
     * over the threshold with no owner approval on record or in messages.
     * held_reason says which. Never pushed while held.
     */
    public const STATUS_HELD = 'held';

    public const RULE_EMERGENCY = 'emergency';

    public const RULE_UNDER_THRESHOLD = 'under_threshold';

    public const RULE_OWNER_APPROVED_RECORDED = 'owner_approved_recorded';

    public const RULE_OWNER_APPROVED_IN_MESSAGES = 'owner_approved_in_messages';

    /** Pushed by number from the console: the office's own lever, no filter. */
    public const RULE_MANUAL = 'manual';

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

    /** The AI reading that found the owner's approval, for rule 3a rows. */
    public function aiInsight(): BelongsTo
    {
        return $this->belongsTo(AiInsight::class, 'ai_insight_id');
    }
}
