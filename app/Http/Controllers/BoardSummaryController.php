<?php

namespace App\Http\Controllers;

use App\Models\WorkOrder;
use App\Services\BoardSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The AI Summary popup behind the "Summary" button on every work order board.
 *
 * Runs synchronously like HoaViolationController::detect() — there is no
 * streaming anywhere in this app to fall back on, and one call over a small
 * stats blob is quick.
 */
class BoardSummaryController extends Controller
{
    public function __invoke(Request $request, BoardSummaryService $summaries): JsonResponse
    {
        abort_unless($request->user()->hasAnyRole(['admin', 'woc', 'accounting']), 403);

        $validated = $request->validate([
            'board' => ['required', 'string', Rule::in(WorkOrder::BOARDS)],
            'search' => ['nullable', 'string', 'max:255'],
            'vendor' => ['nullable', 'integer'],
            'category' => ['nullable', 'string', 'max:255'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'emergency' => ['nullable', 'string', Rule::in(['emergency', 'non_emergency', 'needs_review'])],
            'refresh' => ['nullable', 'boolean'],
        ]);

        $board = $validated['board'];

        $filters = collect($validated)
            ->only(['search', 'vendor', 'category', 'start_date', 'end_date', 'emergency'])
            ->filter(fn ($value) => filled($value))
            ->all();

        $result = $summaries->summarize($board, $filters, $request->boolean('refresh'));

        $aiStatus = $summaries->aiStatus();

        return response()->json([
            'stats' => $result['stats'],
            'summary' => $result['summary'],
            'source' => $result['source'],
            'ai_ready' => $aiStatus['ready'],
            'ai_provider' => $aiStatus['provider'],
        ]);
    }
}
