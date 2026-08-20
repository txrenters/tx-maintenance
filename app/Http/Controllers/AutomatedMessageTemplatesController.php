<?php

namespace App\Http\Controllers;

use App\Services\AutomatedMessageTemplates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The Message Templates editor on the IT Tools Automated Messages page: staff
 * read every editable canned message with its tokens and current override, save
 * a rewording, or reset one back to the shipped default. Saves are blocked when
 * the text uses a token the template does not declare (a typo would go out to
 * the recipient literally) or drops a required one (e.g. a portal link).
 */
class AutomatedMessageTemplatesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);

        return response()->json(AutomatedMessageTemplates::forUi());
    }

    public function update(Request $request, string $key): JsonResponse
    {
        $this->authorizeStaff($request);

        abort_unless(array_key_exists($key, AutomatedMessageTemplates::TEMPLATES), 404);

        $validated = $request->validate([
            'text' => ['required', 'string', 'max:5000'],
        ]);

        $unknown = AutomatedMessageTemplates::unknownTokens($key, $validated['text']);

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'text' => 'Unknown placeholder'.(count($unknown) === 1 ? '' : 's').' {'.implode('}, {', $unknown).'} — it would be sent out literally. This template supports: '
                    .$this->supportedTokenList($key),
            ]);
        }

        $missing = AutomatedMessageTemplates::missingRequiredTokens($key, $validated['text']);

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'text' => 'The placeholder'.(count($missing) === 1 ? '' : 's').' {'.implode('}, {', $missing).'} must stay in this message.',
            ]);
        }

        $nonGsm = AutomatedMessageTemplates::nonGsmCharacters($validated['text']);

        if ($nonGsm !== []) {
            throw ValidationException::withMessages([
                'text' => 'The character'.(count($nonGsm) === 1 ? '' : 's').' "'.implode('", "', $nonGsm).'" would make the text undeliverable as SMS. Please use plain dashes (-) and straight quotes instead.',
            ]);
        }

        AutomatedMessageTemplates::put($key, $validated['text']);

        $this->audit($request, $key, 'updated');

        return response()->json(['template' => AutomatedMessageTemplates::uiEntry($key)]);
    }

    public function reset(Request $request, string $key): JsonResponse
    {
        $this->authorizeStaff($request);

        abort_unless(array_key_exists($key, AutomatedMessageTemplates::TEMPLATES), 404);

        AutomatedMessageTemplates::reset($key);

        $this->audit($request, $key, 'reset');

        return response()->json(['template' => AutomatedMessageTemplates::uiEntry($key)]);
    }

    /**
     * Admin + WOC, matching the page these endpoints live on: coordinators own
     * the message wording day-to-day.
     */
    private function authorizeStaff(Request $request): void
    {
        $user = $request->user();

        abort_unless((bool) ($user?->hasRole('admin') || $user?->hasRole('woc')), 403);
    }

    /**
     * One activity-log line answering "who changed this message, when". Kept
     * out of the automated_message log name so the ledger page stays sends-only.
     */
    private function audit(Request $request, string $key, string $event): void
    {
        activity('message_template')
            ->causedBy($request->user())
            ->withProperties(['template' => $key])
            ->log($event);
    }

    private function supportedTokenList(string $key): string
    {
        $tokens = array_keys(AutomatedMessageTemplates::TEMPLATES[$key]['tokens']);

        return $tokens === [] ? '(no placeholders)' : '{'.implode('}, {', $tokens).'}';
    }
}
