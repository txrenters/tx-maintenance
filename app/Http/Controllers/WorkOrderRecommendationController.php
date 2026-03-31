<?php

namespace App\Http\Controllers;

use App\Models\WorkOrder;
use App\Services\WorkOrderRecommendationService;
use Illuminate\Http\JsonResponse;
use Laravel\Ai\Promptable;

class WorkOrderRecommendationController extends Controller
{
    public function __construct(private readonly WorkOrderRecommendationService $recommendationService) {}

    public function show(WorkOrder $workOrder): JsonResponse
    {
        $recommendation = $this->recommendationService->latest($workOrder);

        return response()->json([
            'recommendation' => $recommendation,
            'ai_ready' => class_exists(Promptable::class),
        ]);
    }

    public function generate(WorkOrder $workOrder): JsonResponse
    {
        $recommendation = $this->recommendationService->generate($workOrder);

        return response()->json([
            'message' => 'Work order recommendation generated successfully.',
            'recommendation' => $recommendation,
            'ai_ready' => class_exists(Promptable::class),
        ]);
    }
}
