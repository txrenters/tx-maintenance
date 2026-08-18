<?php

namespace App\Ai\Concerns;

use App\Services\AiSettings;

/**
 * Feeds the runtime AI Settings override into the SDK's per-call
 * provider()/model() hooks — Promptable::getProvidersAndModels() checks for
 * these methods on every prompt, so queue workers and the scheduler pick up
 * a change from the settings page immediately, without a restart. Null falls
 * through to config('ai.default') and the provider's default model, exactly
 * the pre-override behavior.
 */
trait ConfigurableAiProvider
{
    public function provider(): ?string
    {
        return AiSettings::provider();
    }

    public function model(): ?string
    {
        return AiSettings::model();
    }
}
