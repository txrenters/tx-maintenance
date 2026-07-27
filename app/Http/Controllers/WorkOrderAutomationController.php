<?php

namespace App\Http\Controllers;

use App\Models\WorkOrder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WorkOrderAutomationController extends Controller
{
    /**
     * The channels currently muted for this work order, so a conversation tab
     * can render its toggle in the right state on open.
     */
    public function show(WorkOrder $workOrder)
    {
        return response()->json([
            'paused_automations' => $workOrder->paused_automations ?? [],
        ]);
    }

    /**
     * Mute or resume a single channel's automated messages for one work order.
     * Only the automated senders consult this flag; a WOC typing into the
     * conversation tab and hitting Send is never affected.
     */
    public function update(Request $request, WorkOrder $workOrder)
    {
        $validated = $request->validate([
            'channel' => ['required', Rule::in(WorkOrder::AUTOMATION_CHANNELS)],
            'paused' => ['required', 'boolean'],
        ]);

        $workOrder->setAutomationPaused($validated['channel'], $validated['paused']);

        return back(303);
    }
}
