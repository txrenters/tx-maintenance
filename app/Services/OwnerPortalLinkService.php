<?php

namespace App\Services;

use App\Models\Owner;
use App\Models\OwnerPortalToken;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\Log;

/**
 * Issues (and reuses) an owner's magic-link token for a work order, and builds
 * the portal URL that the automated owner texts and emails carry.
 *
 * Every caller is an automated message that must not break if token creation
 * fails, so link() is log-never-throw and returns null; callers simply omit the
 * link line rather than losing the whole notification.
 */
class OwnerPortalLinkService
{
    /**
     * This owner's token for this work order, created on first use.
     */
    public function tokenFor(WorkOrder $workOrder, Owner $owner): OwnerPortalToken
    {
        $token = OwnerPortalToken::query()->firstOrCreate(
            ['work_order_id' => $workOrder->id, 'owner_id' => $owner->id],
            ['token' => OwnerPortalToken::generateUniqueToken()],
        );

        return $token;
    }

    /**
     * The owner's portal URL, or null when a token could not be issued.
     */
    public function link(WorkOrder $workOrder, Owner $owner): ?string
    {
        try {
            return route('owner.portal.show', $this->tokenFor($workOrder, $owner)->token);
        } catch (\Throwable $exception) {
            Log::error('Owner portal link could not be issued.', [
                'work_order_id' => $workOrder->id,
                'owner_id' => $owner->id,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
