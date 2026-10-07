<?php

namespace App\Http\Controllers;

use App\Http\Requests\PortalMessageHistoryRequest;
use App\Services\PortalMessageHistory;
use Illuminate\Http\JsonResponse;

/**
 * A tenant's or owner's earlier work order texts, for the client portal.
 */
class PortalMessageHistoryController extends Controller
{
    public function __invoke(PortalMessageHistoryRequest $request, PortalMessageHistory $history): JsonResponse
    {
        return response()->json([
            'data' => $history->for($request->validated('contact'), $request->validated('party'), $request->validated('work_order')),
        ]);
    }
}
