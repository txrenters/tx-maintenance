<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Facades\Ai;
use Laravel\Ai\Promptable;

/**
 * Runtime choice of which AI provider and model power every agent call
 * (work order classification, vendor selection, HOA notice reading, board
 * summaries, the unanswered report, the courtesy closer), managed from the
 * IT Tools → AI Settings page instead of env edits and deploys.
 *
 * Two app_settings rows:
 *  - KEY holds {provider, model}. Absent/null means "behave exactly as the
 *    env-backed config/ai.php dictates", so the feature ships inert.
 *  - KEYS_KEY holds provider API keys entered in the UI, each value encrypted
 *    with Crypt. A database key beats the env key for the same provider (that
 *    is what lets staff rotate a key from the UI); applyDbKeysToConfig()
 *    copies them into config so the AI SDK and the raw-HTTP vision extractor
 *    pick them up.
 *
 * The stored provider/model reach the agents through the
 * ConfigurableAiProvider trait, which the SDK consults on EVERY prompt call —
 * queue workers and the scheduler see changes immediately, no restart. A
 * mid-process API-key *rotation* is the one exception: the SDK caches provider
 * instances per process, so long-running workers keep the old key until
 * queue:restart (deploys already do this).
 *
 * All reads fail OPEN: an unreadable row (migration not run, decryption
 * failure after an APP_KEY change) must mean "env behavior", never a crashed
 * recommendation job. Key values are never logged.
 *
 * Known limitation: ollama's config key defaults to '' so it reads as
 * unconfigured until OLLAMA_API_KEY is set to any value.
 */
class AiSettings
{
    public const KEY = 'ai_provider_settings';

    public const KEYS_KEY = 'ai_provider_keys';

    /**
     * The providers that implement the SDK's TextProvider contract in
     * laravel/ai v0.4 — the only ones an agent can run on. The other
     * configured providers (cohere, eleven, jina, voyageai) are
     * embeddings/audio-only and would throw on a text prompt.
     *
     * @var list<string>
     */
    public const TEXT_PROVIDERS = [
        'anthropic',
        'azure',
        'deepseek',
        'gemini',
        'groq',
        'mistral',
        'ollama',
        'openai',
        'openrouter',
        'xai',
    ];

    /**
     * The stored provider override, or null when unset — or when that
     * provider has since lost its API key, so callers fall back to the env
     * default rather than firing prompts at a keyless provider.
     */
    public static function provider(): ?string
    {
        $provider = data_get(self::stored(), 'provider');

        if (! is_string($provider) || $provider === '') {
            return null;
        }

        if (! in_array($provider, self::TEXT_PROVIDERS, true) || ! self::isConfigured($provider)) {
            return null;
        }

        return $provider;
    }

    /**
     * The stored model override. Only meaningful alongside provider(); null
     * means "the provider's default model".
     */
    public static function model(): ?string
    {
        if (self::provider() === null) {
            return null;
        }

        $model = data_get(self::stored(), 'model');

        return is_string($model) && $model !== '' ? $model : null;
    }

    /**
     * Store the override. put(null, null) clears it back to env behavior.
     */
    public static function put(?string $provider, ?string $model): void
    {
        AppSetting::putValue(self::KEY, [
            'provider' => $provider,
            'model' => $provider !== null ? $model : null,
        ]);
    }

    /**
     * True when a provider override is stored but ignored because its API key
     * has since been removed — surfaced as a warning on the settings page.
     */
    public static function overrideIgnored(): bool
    {
        $storedProvider = data_get(self::stored(), 'provider');

        return is_string($storedProvider) && $storedProvider !== '' && self::provider() === null;
    }

    /**
     * The provider actually in effect: the stored override, else the
     * env-backed default from config/ai.php.
     */
    public static function effectiveProvider(): string
    {
        return self::provider() ?? (string) config('ai.default');
    }

    /**
     * The model actually in effect for the effective provider.
     */
    public static function effectiveModel(): string
    {
        $model = self::model();

        if ($model !== null) {
            return $model;
        }

        try {
            return Ai::textProvider(self::effectiveProvider())->defaultTextModel();
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    /**
     * Whether the effective provider is usable — same rules the old
     * WorkOrderRecommendationService::aiStatus() enforced, but database-key
     * aware and applied to the effective (not just env-default) provider.
     */
    public static function ready(): bool
    {
        if (! trait_exists(Promptable::class)) {
            return false;
        }

        $provider = self::effectiveProvider();
        $providerConfig = config("ai.providers.{$provider}", []);

        $isReady = filled(data_get($providerConfig, 'driver')) && filled(self::apiKeyFor($provider));

        if ($provider === 'azure') {
            $isReady = $isReady && filled(data_get($providerConfig, 'url')) && filled(data_get($providerConfig, 'deployment'));
        }

        return $isReady;
    }

    /**
     * The API key in effect for a provider: the UI-entered database key when
     * present, else the env-backed config key.
     */
    public static function apiKeyFor(string $provider): ?string
    {
        $key = self::storedKeys()[$provider] ?? self::envKeyFor($provider);

        return filled($key) ? $key : null;
    }

    /**
     * Store (or with null, remove) a UI-entered API key, then re-sync config
     * so the change is live for the rest of this request.
     */
    public static function putApiKey(string $provider, ?string $key): void
    {
        $stored = [];

        try {
            $stored = AppSetting::getValue(self::KEYS_KEY, []);
            $stored = is_array($stored) ? $stored : [];
        } catch (\Throwable) {
            $stored = [];
        }

        if ($key === null) {
            unset($stored[$provider]);
        } else {
            $stored[$provider] = Crypt::encryptString($key);
        }

        AppSetting::putValue(self::KEYS_KEY, $stored);

        self::applyDbKeysToConfig();
    }

    /**
     * Where a provider's key comes from: 'database' (UI-entered, wins),
     * 'env', or null when it has no key at all.
     */
    public static function keySource(string $provider): ?string
    {
        if (array_key_exists($provider, self::storedKeys())) {
            return 'database';
        }

        return filled(self::envKeyFor($provider)) ? 'env' : null;
    }

    public static function isConfigured(string $provider): bool
    {
        return self::apiKeyFor($provider) !== null;
    }

    /**
     * The text-capable providers that currently have an API key (from either
     * source) — the only values update() accepts.
     *
     * @return list<string>
     */
    public static function configuredProviders(): array
    {
        return array_values(array_filter(self::TEXT_PROVIDERS, fn (string $provider): bool => self::isConfigured($provider)));
    }

    /**
     * Model names worth suggesting for a provider, straight from the SDK's
     * default/cheapest/smartest tiers so there is no hand-maintained list.
     *
     * @return list<string>
     */
    public static function modelSuggestions(string $provider): array
    {
        try {
            $textProvider = Ai::textProvider($provider);

            return array_values(array_unique([
                $textProvider->defaultTextModel(),
                $textProvider->cheapestTextModel(),
                $textProvider->smartestTextModel(),
            ]));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * The model for the raw-HTTP OpenAI vision call (scanned HOA notices).
     * Vision always runs on OpenAI — its Responses-API payload is
     * OpenAI-specific — so the override model only applies when the chosen
     * provider IS OpenAI; any other override would 404 there.
     */
    public static function visionModel(): string
    {
        if (self::provider() === 'openai' && self::model() !== null) {
            return (string) self::model();
        }

        return (string) config('ai.providers.openai.models.text.default', 'gpt-5.4');
    }

    /**
     * Copy the UI-entered keys into config so the AI SDK (which reads
     * config at provider instantiation) and the vision extractor see them.
     * Before a provider's key is first overridden, its pristine env value is
     * snapshotted into ai.env_keys so removing the database key later
     * restores the env key instead of leaving the removed one behind.
     * Providers without a database key are never touched.
     *
     * Called from AppServiceProvider::boot() and before each queued job.
     */
    public static function applyDbKeysToConfig(): void
    {
        $stored = self::storedKeys();
        $snapshot = (array) config('ai.env_keys', []);

        foreach (self::TEXT_PROVIDERS as $provider) {
            $keyPath = "ai.providers.{$provider}.key";
            $isSnapshotted = array_key_exists($provider, $snapshot);

            if (array_key_exists($provider, $stored)) {
                if (! $isSnapshotted) {
                    config(["ai.env_keys.{$provider}" => config($keyPath)]);
                }

                config([$keyPath => $stored[$provider]]);
            } elseif ($isSnapshotted) {
                // The database key was removed this request — put the env
                // value back.
                config([$keyPath => $snapshot[$provider]]);
            }
        }
    }

    /**
     * The stored {provider, model} row, failing open to an empty array.
     *
     * @return array<string, mixed>
     */
    private static function stored(): array
    {
        try {
            $stored = AppSetting::getValue(self::KEY, []);
        } catch (\Throwable $exception) {
            Log::warning('AI settings unreadable; using the env-configured provider.', [
                'error' => $exception->getMessage(),
            ]);

            return [];
        }

        return is_array($stored) ? $stored : [];
    }

    /**
     * The decrypted UI-entered API keys. Rows that fail to decrypt (APP_KEY
     * rotated since they were saved) are skipped, not fatal.
     *
     * @return array<string, string>
     */
    private static function storedKeys(): array
    {
        try {
            $stored = AppSetting::getValue(self::KEYS_KEY, []);
        } catch (\Throwable $exception) {
            Log::warning('AI provider keys unreadable; using env keys only.', [
                'error' => $exception->getMessage(),
            ]);

            return [];
        }

        if (! is_array($stored)) {
            return [];
        }

        $keys = [];

        foreach ($stored as $provider => $encrypted) {
            if (! is_string($provider) || ! is_string($encrypted)) {
                continue;
            }

            try {
                $keys[$provider] = Crypt::decryptString($encrypted);
            } catch (\Throwable) {
                Log::warning('Stored AI provider key failed to decrypt; ignoring it.', [
                    'provider' => $provider,
                ]);
            }
        }

        return $keys;
    }

    /**
     * The env-backed key for a provider, unaffected by any database override
     * applyDbKeysToConfig() may have written over the live config value.
     */
    private static function envKeyFor(string $provider): ?string
    {
        $snapshot = (array) config('ai.env_keys', []);

        if (array_key_exists($provider, $snapshot)) {
            $key = $snapshot[$provider];
        } else {
            $key = config("ai.providers.{$provider}.key");
        }

        return is_string($key) && $key !== '' ? $key : null;
    }
}
