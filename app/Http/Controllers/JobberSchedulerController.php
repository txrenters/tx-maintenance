<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Response;

class JobberSchedulerController extends Controller
{
    /**
     * Placeholder for the scheduling & dispatch engine. Intentionally renders
     * an empty page until the feature is built out.
     */
    public function index(Request $request): Response
    {
        abort_unless((bool) $request->user()?->hasAnyRole(['admin', 'woc']), 403);

        return inertia('Inspection/Scheduler', [
            'title' => 'Scheduler',
        ]);
    }
}
