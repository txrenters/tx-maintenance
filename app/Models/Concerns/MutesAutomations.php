<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * A per-person "do not send automated messages" switch for owners and
 * tenants, set from the Do Not Message tab on IT Tools → Automated Messages.
 *
 * Only the automated senders consult it, the same way they consult a work
 * order's per-channel pause. A WOC typing into a conversation tab and hitting
 * Send is never affected.
 *
 * @property Carbon|null $automations_muted_at
 */
trait MutesAutomations
{
    public function initializeMutesAutomations(): void
    {
        $this->mergeCasts(['automations_muted_at' => 'datetime']);
    }

    public function automationsMuted(): bool
    {
        return $this->automations_muted_at !== null;
    }

    public function setAutomationsMuted(bool $muted): void
    {
        $this->automations_muted_at = $muted ? ($this->automations_muted_at ?? now()) : null;
        $this->save();
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeAutomationsMuted(Builder $query): void
    {
        $query->whereNotNull('automations_muted_at');
    }
}
