<?php

namespace App\Models;

use App\Models\Scopes\ConversationScope;
use App\Services\AwaitingReplyCounter;
use App\Services\CourtesyCloserService;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ScopedBy([ConversationScope::class])]
class Conversation extends Model
{
    protected $table = 'work_order_conversations';

    protected $guarded = [];

    /**
     * Any new or removed message can change which threads are awaiting a reply,
     * so drop the count behind the Messages badge. Hooking the model rather than
     * each caller covers the send endpoint, the inbound webhook and the importer
     * alike.
     *
     * An arriving message that is obviously a courtesy closer ("thank you!")
     * is also judged on the spot — locally, no AI — so it never inflates the
     * badge while waiting for the scheduled classifier. Both hooks are
     * log-never-throw inside their services.
     */
    protected static function booted(): void
    {
        // Guarded here too, not only inside the services: a container or
        // cache-store error during resolution would otherwise fail the very
        // message write that triggered the hook.
        $forget = function (): void {
            try {
                app(AwaitingReplyCounter::class)->forget();
            } catch (\Throwable) {
                // The badge's 60s TTL corrects the count on its own.
            }
        };

        static::created(function (Conversation $message) use ($forget) {
            try {
                app(CourtesyCloserService::class)->recordObviousCloser($message);
            } catch (\Throwable) {
                // Fail-open: the thread simply counts as awaiting.
            }

            $forget();
        });
        static::deleted($forget);
    }

    public function work_order(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(ConversationMedia::class, 'message_id');
    }

    /**
     * Constrain a conversation query to a single vendor's thread so one vendor
     * never sees another vendor's messages on a shared work order. Messages are
     * matched strictly by their vendor_id. Legacy messages that predate that
     * column (vendor_id IS NULL) are attributed either by phone number or, when
     * their work order has only this one vendor, because they cannot belong to
     * anyone else. An explicitly tagged message is never matched by phone, which
     * keeps two vendors on a shared number isolated.
     */
    public function scopeForVendorThread(Builder $query, Vendor $vendor): Builder
    {
        $numbers = collect([$vendor->twilio_number, $vendor->user?->phone])
            ->map(fn ($number) => self::lastTenDigits($number))
            ->filter()
            ->unique()
            ->values();

        return $query->where(function (Builder $scoped) use ($vendor, $numbers) {
            $scoped->where('vendor_id', $vendor->id)
                ->orWhere(function (Builder $legacy) use ($numbers) {
                    $legacy->whereNull('vendor_id')
                        ->where(function (Builder $attributable) use ($numbers) {
                            foreach ($numbers as $digits) {
                                $attributable->orWhere('sender_number', 'LIKE', '%'.$digits)
                                    ->orWhere('receiver_number', 'LIKE', '%'.$digits);
                            }

                            // A work order with a single vendor leaves no ambiguity.
                            $attributable->orWhereHas('work_order', function ($q) {
                                $q->has('vendors', '=', 1);
                            });
                        });
                });
        });
    }

    /**
     * Constrain a conversation query to a single owner's thread so one owner
     * never sees a co-owner's messages on a shared work order. Mirrors
     * scopeForVendorThread: messages are matched strictly by owner_id, and
     * legacy messages that predate that column (owner_id IS NULL) are
     * attributed either by phone number or, when their work order has only this
     * one owner, because they cannot belong to anyone else.
     */
    public function scopeForOwnerThread(Builder $query, Owner $owner): Builder
    {
        $digits = self::lastTenDigits(filled($owner->mobile) ? $owner->mobile : $owner->phone);

        return $query->where(function (Builder $scoped) use ($owner, $digits) {
            $scoped->where('owner_id', $owner->id)
                ->orWhere(function (Builder $legacy) use ($digits) {
                    $legacy->whereNull('owner_id')
                        ->where(function (Builder $attributable) use ($digits) {
                            if ($digits !== null) {
                                $attributable->orWhere('sender_number', 'LIKE', '%'.$digits)
                                    ->orWhere('receiver_number', 'LIKE', '%'.$digits);
                            }

                            // A work order with a single owner leaves no ambiguity.
                            $attributable->orWhereHas('work_order', function ($q) {
                                $q->has('owners', '=', 1);
                            });
                        });
                });
        });
    }

    /**
     * Reduce a phone number to its final 10 digits for tolerant matching.
     */
    public static function lastTenDigits(?string $number): ?string
    {
        if (! $number) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $number);

        return strlen($digits) >= 10 ? substr($digits, -10) : null;
    }
}
