<?php

namespace App\Http\Controllers;

use App\Ai\Agents\ConnectionTestAgent;
use App\Services\AiSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin IT tools page for choosing which AI provider and model power the
 * agents (work order classifier, vendor picker, HOA reader, summaries,
 * courtesy closer), managing UI-entered provider API keys, and live-testing
 * a candidate provider/model before saving it. Full key values never leave
 * the server — the page only ever sees a masked tail.
 */
class AiSettingsController extends Controller
{
    private const MODEL_RULES = ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:\/-]*$/'];

    public function show(Request $request): Response
    {
        $this->authorizeAdmin($request);

        return Inertia::render('ItTools/AiSettings', [
            'title' => 'IT Tools — AI Settings',
            'providers' => $this->providers(),
            'current' => $this->currentState(),
            'vision' => $this->visionState(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'provider' => ['nullable', 'string', 'required_with:model', Rule::in(AiSettings::configuredProviders())],
            'model' => self::MODEL_RULES,
        ]);

        AiSettings::put($validated['provider'] ?? null, $validated['model'] ?? null);

        return response()->json([
            'current' => $this->currentState(),
        ]);
    }

    public function storeKey(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'provider' => ['required', 'string', Rule::in(AiSettings::TEXT_PROVIDERS)],
            'key' => ['required', 'string', 'min:8', 'max:512'],
        ]);

        AiSettings::putApiKey($validated['provider'], trim($validated['key']));

        return response()->json([
            'providers' => $this->providers(),
            'current' => $this->currentState(),
        ]);
    }

    public function destroyKey(Request $request, string $provider): JsonResponse
    {
        $this->authorizeAdmin($request);

        abort_unless(in_array($provider, AiSettings::TEXT_PROVIDERS, true), 404);
        // Env-backed keys belong to the server config; only keys saved from
        // this page can be removed here.
        abort_unless(AiSettings::keySource($provider) === 'database', 422, 'Only keys saved from this page can be removed.');

        AiSettings::putApiKey($provider, null);

        return response()->json([
            'providers' => $this->providers(),
            'current' => $this->currentState(),
        ]);
    }

    /**
     * Fire one tiny structured prompt at the candidate provider/model WITHOUT
     * saving anything, so the admin sees a pass/fail (and the provider's own
     * error message) before switching production over.
     */
    public function test(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'provider' => ['required', 'string', Rule::in(AiSettings::configuredProviders())],
            'model' => self::MODEL_RULES,
        ]);

        $started = microtime(true);

        try {
            $response = (new ConnectionTestAgent)->prompt(
                'Connectivity check.',
                provider: $validated['provider'],
                model: $validated['model'] ?? null,
                timeout: 30,
            );

            return response()->json([
                'ok' => true,
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
                'provider' => $response->meta->provider,
                'model' => $response->meta->model,
            ]);
        } catch (\Throwable $exception) {
            // 200 with ok:false — a failing provider is a result to render
            // inline, not an application error.
            return response()->json([
                'ok' => false,
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
                'error' => Str::limit($exception->getMessage(), 300),
            ]);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function providers(): array
    {
        $default = (string) config('ai.default');

        return array_map(fn (string $provider): array => [
            'key' => $provider,
            'configured' => AiSettings::isConfigured($provider),
            'key_source' => AiSettings::keySource($provider),
            'masked_key' => $this->maskedKeyFor($provider),
            'is_env_default' => $provider === $default,
            'suggestions' => AiSettings::modelSuggestions($provider),
        ], AiSettings::TEXT_PROVIDERS);
    }

    /**
     * @return array<string, mixed>
     */
    private function currentState(): array
    {
        return [
            'override_provider' => AiSettings::provider(),
            'override_model' => AiSettings::model(),
            'effective_provider' => AiSettings::effectiveProvider(),
            'effective_model' => AiSettings::effectiveModel(),
            'env_default_provider' => (string) config('ai.default'),
            'ready' => AiSettings::ready(),
            'override_ignored' => AiSettings::overrideIgnored(),
        ];
    }

    /**
     * @return array{ready: bool, model: string}
     */
    private function visionState(): array
    {
        return [
            'ready' => filled(config('ai.providers.openai.key')),
            'model' => AiSettings::visionModel(),
        ];
    }

    private function maskedKeyFor(string $provider): ?string
    {
        if (AiSettings::keySource($provider) !== 'database') {
            return null;
        }

        $key = (string) AiSettings::apiKeyFor($provider);

        return '••••'.substr($key, -4);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless((bool) $request->user()?->hasRole('admin'), 403);
    }
}
