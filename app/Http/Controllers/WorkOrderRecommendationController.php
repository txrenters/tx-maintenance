<?php

namespace App\Http\Controllers;

use App\Models\WorkOrder;
use App\Services\WorkOrderRecommendationService;
use Illuminate\Http\JsonResponse;

class WorkOrderRecommendationController extends Controller
{
    public function __construct(private readonly WorkOrderRecommendationService $recommendationService) {}

    public function show(WorkOrder $workOrder): JsonResponse
    {
        $recommendation = $this->recommendationService->latest($workOrder);
        $aiStatus = $this->recommendationService->aiStatus();

        return response()->json([
            'recommendation' => $recommendation,
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
            'recommendation' => $recommendation,
            'ai_ready' => $aiStatus['ready'],
            'ai_provider' => $aiStatus['provider'],
        ]);
    }
}
