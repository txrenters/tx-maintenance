<?php

namespace App\Http\Controllers;

use App\Models\AiInsight;
use App\Models\WorkOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Staff endpoints for read-only AI insights: list a work order's open
 * schedule suggestions, and resolve one (accept after the schedule is
 * actually created through the normal form, or dismiss). Resolving an
 * insight never creates or changes a schedule — the existing Service
 * Schedule form remains the only writer.
 */
class AiInsightController extends Controller
{
    public function scheduleSuggestions(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $this->authorizeStaff($request);

        try {
            $suggestions = AiInsight::query()
                ->ofType(AiInsight::TYPE_SCHEDULE_SUGGESTION)
                ->open()
                ->where('work_order_id', $workOrder->id)
                ->latest('id')
                ->get(['id', 'data', 'generated_at'])
                ->map(fn (AiInsight $insight) => [
                    'id' => $insight->id,
                    'start' => data_get($insight->data, 'start'),
                    'end' => data_get($insight->data, 'end'),
                    'summary' => data_get($insight->data, 'summary'),
                    'party' => data_get($insight->data, 'party'),
                    'quote' => data_get($insight->data, 'quote'),
                ])
                ->values();
        } catch (\Throwable $exception) {
            // Fail open — a missing ai_insights table must not break the tab.
            Log::warning('Schedule suggestions unreadable; returning none.', [
                'error' => $exception->getMessage(),
            ]);

            $suggestions = collect();
        }

        return response()->json(['suggestions' => $suggestions]);
    }

    public function updateStatus(Request $request, AiInsight $aiInsight): JsonResponse
    {
        $this->authorizeStaff($request);

        $validated = $request->validate([
            'status' => ['required', Rule::in([AiInsight::STATUS_ACCEPTED, AiInsight::STATUS_DISMISSED])],
        ]);

        $aiInsight->update([
            'status' => $validated['status'],
            'resolved_by_user_id' => $request->user()->id,
            'resolved_at' => now(),
        ]);

        return response()->json(['status' => $aiInsight->status]);
    }

    private function authorizeStaff(Request $request): void
    {
        abort_unless((bool) $request->user()?->hasAnyRole(['admin', 'woc']), 403);
    }
}
