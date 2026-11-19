<?php

namespace App\Services\InspectionApp;

use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Which work orders go to the technicians' inspection app: the ones our own
 * crews handle. A work order qualifies when one of its vendors is in-house
 * (Texas Home Maintenance Pros, Crystal Creek Air, Crystal Creek Pest) and
 * none of its vendors is on the skip list. Emergency or routine, any
 * category: as long as it is a work order of ours.
 *
 * In-house vendors are keyed on PropertyWare vendor id first
 * (services.inspection_app.handoff_vendor_ids), because the vendors table
 * names a row after PropertyWare's company name and that gets edited; exact
 * names (handoff_vendor_names, separated by "|" since a name can carry a
 * comma) are honoured as well for a vendor whose id is not to hand. Never
 * a prefix match: "Crystal Creek Construction" is not ours.
 *
 * The skip list (handoff_skip_vendor_ids) is for outside companies staff tag
 * THMP onto only so the Jobber job gets created — Jimmie Gendke SFA by
 * default; the work is theirs, so it must not reach our technicians' queue.
 *
 * Whether a qualifying work order may be *sent yet* is ApprovalGate's
 * question, not this one's.
 */
class HandoffRule
{
    public function qualifies(WorkOrder $workOrder): bool
    {
        $vendors = $workOrder->relationLoaded('vendors') ? $workOrder->vendors : $workOrder->vendors()->get();

        if ($vendors->contains(fn (Vendor $vendor): bool => $this->isSkipped($vendor))) {
            return false;
        }

        return $vendors->contains(fn (Vendor $vendor): bool => $this->isInHouse($vendor));
    }

    public function isInHouse(Vendor $vendor): bool
    {
        $propertywareId = trim((string) $vendor->propertyware_id);

        if ($propertywareId !== '' && $this->list('services.inspection_app.handoff_vendor_ids')->contains($propertywareId)) {
            return true;
        }

        $name = $this->normalizeName((string) $vendor->name);

        return $name !== '' && $this->list('services.inspection_app.handoff_vendor_names', '|')
            ->map(fn (string $listed): string => $this->normalizeName($listed))
            ->contains($name);
    }

    public function isSkipped(Vendor $vendor): bool
    {
        $propertywareId = trim((string) $vendor->propertyware_id);

        return $propertywareId !== '' && $this->list('services.inspection_app.handoff_skip_vendor_ids')->contains($propertywareId);
    }

    /**
     * Whether PropertyWare still has the work order open. A work order that
     * is already closed is not handed off for the first time (the scheduler
     * has nothing to do with it), but one that was handed off earlier is
     * updated so the app can drop it.
     */
    public function isOpen(WorkOrder $workOrder): bool
    {
        $status = Str::lower(trim((string) $workOrder->status));

        return ! collect(WorkOrder::CLOSED_STATUSES)
            ->map(fn (string $closed): string => Str::lower($closed))
            ->contains($status);
    }

    /**
     * @return Collection<int, string>
     */
    private function list(string $configKey, string $separator = ','): Collection
    {
        return collect(explode($separator, (string) config($configKey, '')))
            ->map(fn (string $entry): string => trim($entry))
            ->filter()
            ->unique()
            ->values();
    }

    private function normalizeName(string $name): string
    {
        return Str::lower(trim((string) preg_replace('/\s+/', ' ', $name)));
    }
}
