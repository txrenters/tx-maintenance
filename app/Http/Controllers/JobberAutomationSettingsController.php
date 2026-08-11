<?php

namespace App\Http\Controllers;

use App\Services\JobberAutomationSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The app-wide Jobber automation kill-switch behind the header toggle on the
 * Jobber pages: a master switch plus one switch per Jobber automation. Only
 * the automated senders consult this state — manual staff sends are never
 * affected.
 */
class JobberAutomationSettingsController extends Controller
{
    /**
     * Current switch state plus the key => label list the popover renders.
     */
    public function show(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);

        return response()->json([
            'disabled' => JobberAutomationSettings::disabled(),
            'automations' => JobberAutomationSettings::labels(),
        ]);
    }

    /**
     * Flip one switch: an automation key, or "all" for the master.
     */
    public function update(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);

        $validated = $request->validate([
            'automation' => ['required', Rule::in([
                JobberAutomationSettings::MASTER,
                ...JobberAutomationSettings::AUTOMATIONS,
            ])],
            'disabled' => ['required', 'boolean'],
        ]);

        JobberAutomationSettings::set($validated['automation'], $validated['disabled']);

        return response()->json([
            'disabled' => JobberAutomationSettings::disabled(),
        ]);
    }

    /**
     * Admin + WOC — the roles who run the Jobber board day-to-day. Wider than
     * the admin-only IT Tools gate on purpose, so a coordinator can kill a
     * misbehaving automation without waiting for an admin.
     */
    private function authorizeStaff(Request $request): void
    {
        $user = $request->user();

        abort_unless((bool) ($user?->hasRole('admin') || $user?->hasRole('woc')), 403);
    }
}
