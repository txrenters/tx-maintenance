<?php

namespace App\Http\Controllers;

use App\Models\Building;
use App\Models\Scopes\WorkOrderScope;
use App\Models\TenantUploadToken;
use App\Models\WorkOrder;
use App\Services\HoaViolationIntakeService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Staff entry point for HOA violation notices: upload the notice PDF for a
 * property and the intake service does the rest (create the work order in
 * PropertyWare, attach the PDF, AI-read the notice into the description, set
 * Tenant Easy Fix, and text the tenant their portal link with the
 * 5-business-day deadline).
 */
class HoaViolationController extends Controller
{
    public function index()
    {
        $workOrders = WorkOrder::withoutGlobalScope(WorkOrderScope::class)
            ->where('category', HoaViolationIntakeService::CATEGORY)
            ->with(['building:id,propertyware_id,name', 'service_status:id,name'])
            ->latest('id')
            ->get([
                'id', 'work_order_no', 'propertyware_id', 'description', 'status',
                'service_status_id', 'building_id', 'created_date', 'created_at',
            ]);

        $tokens = TenantUploadToken::query()
            ->where('purpose', TenantUploadToken::PURPOSE_HOA_VIOLATION)
            ->whereIn('work_order_id', $workOrders->pluck('id'))
            ->get()
            ->keyBy('work_order_id');

        return inertia('WorkOrder/HoaViolations', [
            'workOrders' => $workOrders->map(function (WorkOrder $workOrder) use ($tokens) {
                $token = $tokens->get($workOrder->id);

                return [
                    'id' => $workOrder->id,
                    'work_order_no' => $workOrder->work_order_no,
                    'property' => $workOrder->building?->name,
                    'description' => $workOrder->description,
                    'status' => $workOrder->service_status?->name ?? $workOrder->status,
                    'pw_linked' => (bool) $workOrder->propertyware_id,
                    'notice_date' => $token?->hoa_notice_date?->toDateString(),
                    'deadline' => $token?->hoa_deadline_at?->toDateString(),
                    'completed' => (bool) $token?->isCompleted(),
                    'overdue' => $token !== null
                        && ! $token->isCompleted()
                        && $token->hoa_deadline_at !== null
                        && $token->hoa_deadline_at->isPast(),
                    'confirmation_sent' => $token?->confirmation_sent_at !== null,
                    'created_at' => $workOrder->created_at?->toDateString(),
                ];
            })->values(),
            'buildings' => Building::query()
                ->orderBy('name')
                ->get(['propertyware_id', 'name'])
                ->map(fn (Building $building) => [
                    'id' => $building->propertyware_id,
                    'name' => $building->name,
                ]),
        ]);
    }

    public function store(Request $request, HoaViolationIntakeService $intake)
    {
        $validated = $request->validate([
            'building_id' => 'required|exists:buildings,propertyware_id',
            'file' => 'required|file|mimes:pdf|max:51200',
            'hoa_notice_date' => 'nullable|date',
        ]);

        $building = Building::query()
            ->where('propertyware_id', $validated['building_id'])
            ->firstOrFail();

        try {
            $result = $intake->handle(
                $building,
                $request->file('file'),
                filled($validated['hoa_notice_date'] ?? null) ? Carbon::parse($validated['hoa_notice_date']) : null,
            );
        } catch (\Throwable $exception) {
            Log::error('HOA violation intake failed.', [
                'building_propertyware_id' => $building->propertyware_id,
                'error' => $exception->getMessage(),
            ]);

            return back()->withErrors(['error' => 'Could not process the HOA notice. Please try again.']);
        }

        $reference = $result['work_order']->work_order_no
            ? 'WO#'.$result['work_order']->work_order_no
            : 'a local work order';

        $message = $result['created']
            ? "HOA violation work order created ({$reference})."
            : "HOA notice attached to the existing open HOA work order ({$reference}).";

        if ($result['created'] && ! $result['pw_created']) {
            $message .= ' PropertyWare creation was unavailable — the work order exists in this app only.';
        }

        return back()->with('success', $message);
    }
}
