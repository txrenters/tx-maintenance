<?php

namespace App\Http\Controllers;

use App\Models\WorkOrder;
use App\Models\WorkOrderRecommendation;
use App\Services\InspectionApp\HandoffStatus;
use App\Services\TenantEasyFixReplyJudge;
use App\Services\WorkOrderRecommendationService;
use Illuminate\Http\JsonResponse;

class WorkOrderRecommendationController extends Controller
{
    public function __construct(
        private readonly WorkOrderRecommendationService $recommendationService,
        private readonly TenantEasyFixReplyJudge $easyFixReplyJudge,
        private readonly HandoffStatus $handoffStatus,
    ) {}

    public function show(WorkOrder $workOrder): JsonResponse
    {
        $recommendation = $this->recommendationService->latest($workOrder);
        $aiStatus = $this->recommendationService->aiStatus();

        return response()->json([
            'recommendation' => $this->withPropertyHistory($recommendation, $workOrder),
            'ai_ready' => $aiStatus['ready'],
            'ai_provider' => $aiStatus['provider'],
        ]);
    }

    public function generate(WorkOrder $workOrder): JsonResponse
    {
        $recommendation = $this->recommendationService->generate($workOrder);
        $aiStatus = $this->recommendationService->aiStatus();

        return response()->json([
            'message' => 'Work order recommendation generated successfully.',
            'recommendation' => $this->withPropertyHistory($recommendation, $workOrder),
            'ai_ready' => $aiStatus['ready'],
            'ai_provider' => $aiStatus['provider'],
        ]);
    }

    /**
     * The property's previous work orders, and the AI's reading of the
     * tenant's easy-fix reply, ride inside the recommendation payload because
     * every host of the tab (the modal, the boards, the full page) keeps only
     * response.data.recommendation. Merged into the array form rather than
     * set on the model, so nothing can later try to persist a column that
     * does not exist.
     *
     * @return array<string, mixed>|null
     */
    private function withPropertyHistory(?WorkOrderRecommendation $recommendation, WorkOrder $workOrder): ?array
    {
        if ($recommendation === null) {
            return null;
        }

        return $recommendation->toArray() + [
            'property_history' => $this->recommendationService->propertyHistory($workOrder),
            'easy_fix_reply' => $this->easyFixReplyJudge->latestFor($workOrder),
            // Where the hand-off to the inspection app stands: held and why,
            // or sent under which rule (the 2026-11-19 approval filter).
            'inspection_app_handoff' => $this->handoffStatus->for($workOrder),
        ];
    }
}
