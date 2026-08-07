<?php

namespace App\Services;

use App\Models\WorkOrder;
use Illuminate\Support\Facades\Log;

/**
 * The two steps every "create a work order in PropertyWare" flow has to get
 * right: resolving the location/unit pair PropertyWare validates the create
 * against, and importing the new work order straight back so the local row
 * carries the tenant, owner and lease data every downstream feature expects.
 *
 * Shared by HOA violation intake and the tenant portal's new-request button.
 * It lives on its own because the location rule below is the most expensive
 * piece of institutional knowledge in this codebase — a second copy is how it
 * drifts and silently breaks again.
 */
class PropertyWareWorkOrderCreator
{
    public function __construct(private readonly PropertyWareService $propertyWare) {}

    /**
     * PropertyWare validates a create against its "Location" (the unit) and
     * fails the whole call with "Location is invalid" unless the piped
     * "PORTFOLIO | BUILDING" location string and the unit ID both match what it
     * has on record — omitting them is what silently turned every HOA intake
     * into a local-only work order until 2026-08-04.
     *
     * Rather than reconstruct either value (the local rows only carry the REST
     * variant of the string, without the pipe), copy both off the newest work
     * order PropertyWare itself holds for the building. A building with no work
     * order history yields nulls, and createWorkOrder then refuses rather than
     * send a payload PropertyWare is known to reject.
     *
     * @return array{0: ?string, 1: int|string|null}
     */
    public function locationFor(int|string|null $buildingPropertywareId): array
    {
        if (blank($buildingPropertywareId)) {
            return [null, null];
        }

        $latest = $this->propertyWare->getLatestWorkOrderForBuilding($buildingPropertywareId);

        // A work order with a single unit can come back with unitIDs as a bare
        // scalar rather than a list. Indexing that would hand PropertyWare the
        // first *character* of the unit ID — a create it rejects, in the silent
        // way this whole method exists to prevent.
        $unitIds = $latest['unitIDs'] ?? null;

        return [
            $latest['location'] ?? null,
            is_array($unitIds) ? ($unitIds[0] ?? null) : $unitIds,
        ];
    }

    /**
     * Create the work order in PropertyWare and import it back, returning the
     * local row. Returns null when PropertyWare refuses the create or the
     * import cannot find the row, so callers can fall back to a local-only
     * work order rather than losing the request.
     *
     * Intake automations are deliberately never dispatched from here: a work
     * order created this way comes back without its requesting tenant (the
     * create payload carries no requestedByContact), so callers have to stamp
     * the row before any tenant-facing job runs against it.
     *
     * @param  array{building_id: mixed, portfolio_id: mixed, category: ?string, description: string, type: ?string, location: ?string, unit_id: mixed}  $payload
     */
    public function createAndImport(array $payload): ?WorkOrder
    {
        $propertywareId = $this->propertyWare->createWorkOrder($payload);

        if ($propertywareId === null) {
            return null;
        }

        return $this->importCreatedWorkOrder($propertywareId);
    }

    private function importCreatedWorkOrder(string $propertywareId): ?WorkOrder
    {
        try {
            $pwWorkOrder = $this->propertyWare->getWorkOrder($propertywareId);
            $number = is_array($pwWorkOrder) ? ($pwWorkOrder['number'] ?? null) : null;

            if ($number) {
                $workOrders = $this->propertyWare->getWorkOrderByNumber((int) $number);

                if (is_array($workOrders) && $workOrders !== []) {
                    (new WorkOrderService)->handle($workOrders);
                }
            }
        } catch (\Throwable $exception) {
            Log::error('Importing the created work order back from PropertyWare failed.', [
                'propertyware_id' => $propertywareId,
                'error' => $exception->getMessage(),
            ]);
        }

        // withoutGlobalScopes: this also runs inside the tenant portal's guest
        // web request, where the work order scope would otherwise hide the row.
        return WorkOrder::query()->withoutGlobalScopes()->where('propertyware_id', $propertywareId)->first();
    }
}
