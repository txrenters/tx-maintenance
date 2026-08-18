<?php

namespace App\Http\Controllers;

use App\Services\UnansweredMessageReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The AI summary report behind the Inbox's "Summary" button: who has written in
 * and not been answered.
 *
 * Runs synchronously like BoardSummaryController — one call over a small stats
 * blob, and there is no streaming anywhere in this app to fall back on.
 */
class InboxSummaryController extends Controller
{
    public function __invoke(Request $request, UnansweredMessageReport $report): JsonResponse
    {
        abort_unless($request->user()?->hasAnyRole(['admin', 'woc']), 403);

        $request->validate([
            'refresh' => ['nullable', 'boolean'],
            // The Inbox scopes the report to the threads currently on screen
            // by sending their newest-message ids; absent means the whole
            // queue. The report only ever narrows with these — visibility is
            // enforced inside it regardless of what is posted here.
            'ids' => ['nullable', 'array', 'max:600'],
            'ids.*' => ['integer'],
        ]);

        $ids = $request->has('ids')
            ? array_map('intval', $request->input('ids', []))
            : null;

        $result = $report->build($request->boolean('refresh'), $ids);

        $aiStatus = $report->aiStatus();

        return response()->json([
            'stats' => $result['stats'],
            'summary' => $result['summary'],
            'source' => $result['source'],
            'ai_ready' => $aiStatus['ready'],
            'ai_provider' => $aiStatus['provider'],
        ]);
    }
}
