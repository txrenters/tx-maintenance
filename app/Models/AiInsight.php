<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A read-only AI annotation about a work order: what an inbound message is
 * asking for, a proposed appointment extracted from a text, or a verdict on
 * a vendor's completion photo. Insights never act on anything — they label;
 * humans accept or dismiss the ones that carry a proposal.
 */
class AiInsight extends Model
{
    public const TYPE_MESSAGE_INTENT = 'message_intent';

    public const TYPE_SCHEDULE_SUGGESTION = 'schedule_suggestion';

    public const TYPE_PHOTO_REVIEW = 'photo_review';

    /**
     * The AI's reading of a tenant's reply on the Tenant Easy Fix board: does
     * the tenant say the fix worked? A confident yes labels the card "Ready
     * to close" for a coordinator. The subject is the Conversation row.
     */
    public const TYPE_EASY_FIX_READY = 'easy_fix_ready';

    public const STATUS_OPEN = 'open';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DISMISSED = 'dismissed';

    /**
     * The intents the triage agent may assign to an inbound message. 'none'
     * means the message needs no categorization (a courtesy, an emoji) and is
     * stored so the thread is never re-classified, but renders no chip.
     *
     * @var list<string>
     */
    public const INTENTS = [
        'appointment_confirmed',
        'reschedule_request',
        'complaint',
        'access_issue',
        'job_done',
        'approval',
        'question',
        'none',
    ];

    protected $guarded = [];

    protected $casts = [
        'data' => 'array',
        'resolved_at' => 'datetime',
        'generated_at' => 'datetime',
    ];

    public function work_order(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function resolved_by(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }
}
