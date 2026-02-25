<?php

namespace App\Http\Controllers;

use App\Models\Building;
use App\Models\WorkOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $query = $request->get('query', '');
        $buildingId = $request->get('building_id');

        if (strlen($query) < 2 && ! $buildingId) {
            return response()->json(['results' => []]);
        }

        $workOrders = WorkOrder::query()
            ->leftJoin('buildings', 'buildings.propertyware_id', '=', 'work_orders.building_id')
            ->select('work_orders.*', 'buildings.name as building_name')
            ->with('service_status:id,name')
            ->when(strlen($query) >= 2, fn ($q) => $q->where(fn ($q2) => $q2
                ->orWhere('work_orders.description', 'LIKE', "%{$query}%")
                ->orWhere('work_orders.location', 'LIKE', "%{$query}%")
                ->orWhere('work_orders.category', 'LIKE', "%{$query}%")
                ->orWhere('work_orders.specific_location', 'LIKE', "%{$query}%")
                ->orWhere('work_orders.work_order_no', 'LIKE', "%{$query}%")
            ))
            ->when($buildingId, fn ($q) => $q->where('work_orders.building_id', $buildingId))
            ->orderBy('work_orders.created_date', 'desc')
            ->limit(15)
            ->get();

        $results = $workOrders->map(fn ($wo) => [
            'id' => $wo->id,
            'work_order_no' => $wo->work_order_no,
            'description' => $wo->description ? mb_substr($wo->description, 0, 120) : null,
            'location' => $wo->location,
            'category' => $wo->category,
            'status' => $wo->status,
            'local_status' => $wo->local_status,
            'priority' => $wo->priority,
            'is_emergency' => (bool) $wo->is_emergency,
            'service_status' => $wo->service_status?->name,
            'building_name' => $wo->building_name,
        ]);

        return response()->json(['results' => $results]);
    }

    public function buildings(): JsonResponse
    {
        $buildings = Building::query()
            ->whereHas('workOrders')
            ->orderBy('name')
            ->get(['propertyware_id', 'name']);

        return response()->json(['buildings' => $buildings]);
    }
}
