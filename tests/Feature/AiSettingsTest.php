<?php

namespace Tests\Feature;

use App\Ai\Agents\ConnectionTestAgent;
use App\Ai\Agents\CourtesyCloserAgent;
use App\Models\AppSetting;
use App\Models\User;
use App\Services\AiSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Providers\OpenAiProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AiSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'woc', 'accounting', 'vendor', 'tenant', 'owner'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    private function staff(string $role = 'admin'): User
    {
        return User::factory()->create()->assignRole($role);
    }

    private function withOpenAiKey(string $key = 'env-openai-key'): void
    {
        config(['ai.providers.openai.key' => $key]);
    }

    /*
    |--------------------------------------------------------------------------
    | Page + gating
    |--------------------------------------------------------------------------
    */

    public function test_an_admin_can_view_the_settings_page(): void
    {
        $this->actingAs($this->staff())
            ->get(route('it-tools.ai-settings'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ItTools/AiSettings')
                ->has('providers', count(AiSettings::TEXT_PROVIDERS))
                ->has('current', fn (Assert $current) => $current
                    ->where('override_provider', null)
                    ->where('effective_provider', (string) config('ai.default'))
                    ->etc())
                ->has('vision'));
    }

    public function test_other_roles_cannot_use_any_endpoint(): void
    {
        foreach (['woc', 'accounting', 'vendor'] as $role) {
            $user = $this->staff($role);

            $this->actingAs($user)->get(route('it-tools.ai-settings'))->assertForbidden();
            $this->actingAs($user)->putJson(route('it-tools.ai-settings.update'), ['provider' => null])->assertForbidden();
            $this->actingAs($user)->putJson(route('it-tools.ai-settings.keys'), ['provider' => 'openai', 'key' => 'x-key-1234'])->assertForbidden();
            $this->actingAs($user)->deleteJson(route('it-tools.ai-settings.keys.destroy', 'openai'))->assertForbidden();
            $this->actingAs($user)->postJson(route('it-tools.ai-settings.test'), ['provider' => 'openai'])->assertForbidden();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Saving the engine choice
    |--------------------------------------------------------------------------
    */

    public function test_update_rejects_unknown_unconfigured_and_non_text_providers(): void
    {
        $admin = $this->staff();

        // Not a provider at all.
        $this->actingAs($admin)->putJson(route('it-tools.ai-settings.update'), [
            'provider' => 'skynet',
        ])->assertUnprocessable();

        // A real text provider, but no API key configured anywhere.
        $this->actingAs($admin)->putJson(route('it-tools.ai-settings.update'), [
            'provider' => 'gemini',
        ])->assertUnprocessable();

        // Key present, but cohere cannot generate text — never selectable.
        config(['ai.providers.cohere.key' => 'cohere-key']);
        $this->actingAs($admin)->putJson(route('it-tools.ai-settings.update'), [
            'provider' => 'cohere',
        ])->assertUnprocessable();
    }

    public function test_update_rejects_a_model_without_a_provider_and_malformed_models(): void
    {
        $admin = $this->staff();
        $this->withOpenAiKey();

        $this->actingAs($admin)->putJson(route('it-tools.ai-settings.update'), [
            'model' => 'gpt-5.4-mini',
        ])->assertUnprocessable();

        $this->actingAs($admin)->putJson(route('it-tools.ai-settings.update'), [
            'provider' => 'openai',
            'model' => 'bad model name!',
        ])->assertUnprocessable();
    }

    public function test_an_admin_can_save_and_clear_the_engine_choice(): void
    {
        $admin = $this->staff();
        $this->withOpenAiKey();

        $this->actingAs($admin)->putJson(route('it-tools.ai-settings.update'), [
            'provider' => 'openai',
            'model' => 'gpt-test-model',
        ])->assertOk()->assertJsonPath('current.override_provider', 'openai')
            ->assertJsonPath('current.effective_model', 'gpt-test-model');

        $this->assertSame('openai', AiSettings::provider());
        $this->assertSame('gpt-test-model', AiSettings::model());

        $this->actingAs($admin)->putJson(route('it-tools.ai-settings.update'), [
            'provider' => null,
        ])->assertOk()->assertJsonPath('current.override_provider', null);

        $this->assertNull(AiSettings::provider());
        $this->assertNull(AiSettings::model());
    }

    /*
    |--------------------------------------------------------------------------
    | Resolution order
    |--------------------------------------------------------------------------
    */

    public function test_without_an_override_the_env_default_applies(): void
    {
        $this->assertNull(AiSettings::provider());
        $this->assertSame((string) config('ai.default'), AiSettings::effectiveProvider());
        // phpunit.xml blanks every AI key, so nothing is ready by default.
        $this->assertFalse(AiSettings::ready());
    }

    public function test_a_keyless_override_is_ignored_and_flagged(): void
    {
        $this->withOpenAiKey();
        AiSettings::put('anthropic', 'claude-test');

        // ANTHROPIC_API_KEY is blank in tests, so the stored choice must not
        // point prompts at a keyless provider.
        $this->assertNull(AiSettings::provider());
        $this->assertNull(AiSettings::model());
        $this->assertTrue(AiSettings::overrideIgnored());
        $this->assertSame((string) config('ai.default'), AiSettings::effectiveProvider());
    }

    public function test_a_missing_settings_table_fails_open(): void
    {
        Schema::drop('app_settings');

        $this->assertNull(AiSettings::provider());
        $this->assertSame((string) config('ai.default'), AiSettings::effectiveProvider());
        AiSettings::applyDbKeysToConfig();
        $this->assertFalse(AiSettings::ready());
    }

    /*
    |--------------------------------------------------------------------------
    | The override reaches the agents
    |--------------------------------------------------------------------------
    */

    public function test_agents_prompt_with_the_saved_provider_and_model(): void
    {
        $this->withOpenAiKey();
        AiSettings::put('openai', 'gpt-override-model');

        CourtesyCloserAgent::fake();

        (new CourtesyCloserAgent)->prompt('Judge these refs.');

        CourtesyCloserAgent::assertPrompted(fn ($prompt) => $prompt->model === 'gpt-override-model'
            && $prompt->provider instanceof OpenAiProvider);
    }

    public function test_agents_fall_back_to_the_env_configured_model_without_an_override(): void
    {
        $this->withOpenAiKey();
        config([
            'ai.default' => 'openai',
            'ai.providers.openai.models.text.default' => 'gpt-env-model',
        ]);

        CourtesyCloserAgent::fake();

        (new CourtesyCloserAgent)->prompt('Judge these refs.');

        CourtesyCloserAgent::assertPrompted(fn ($prompt) => $prompt->model === 'gpt-env-model'
            && $prompt->provider instanceof OpenAiProvider);
    }

    /*
    |--------------------------------------------------------------------------
    | API keys
    |--------------------------------------------------------------------------
    */

    public function test_saving_a_key_stores_it_encrypted_and_unlocks_the_provider(): void
    {
        $this->actingAs($this->staff())->putJson(route('it-tools.ai-settings.keys'), [
            'provider' => 'anthropic',
            'key' => 'sk-ant-test-1234',
        ])->assertOk()->assertJsonFragment([
            'key' => 'anthropic',
            'configured' => true,
            'key_source' => 'database',
            'masked_key' => '••••1234',
        ]);

        $raw = AppSetting::getValue(AiSettings::KEYS_KEY);
        $this->assertNotSame('sk-ant-test-1234', $raw['anthropic']);
        $this->assertSame('sk-ant-test-1234', Crypt::decryptString($raw['anthropic']));

        // Applied to live config for the SDK and the vision extractor.
        $this->assertSame('sk-ant-test-1234', config('ai.providers.anthropic.key'));
        $this->assertContains('anthropic', AiSettings::configuredProviders());
    }

    public function test_the_full_key_value_never_reaches_the_page(): void
    {
        AiSettings::putApiKey('anthropic', 'sk-ant-secret-9876');

        $this->actingAs($this->staff())
            ->get(route('it-tools.ai-settings'))
            ->assertOk()
            ->assertDontSee('sk-ant-secret-9876');
    }

    public function test_removing_a_database_key_restores_the_env_key(): void
    {
        $admin = $this->staff();
        $this->withOpenAiKey('env-openai-key');

        AiSettings::putApiKey('openai', 'db-openai-key-5678');
        $this->assertSame('db-openai-key-5678', config('ai.providers.openai.key'));
        $this->assertSame('database', AiSettings::keySource('openai'));

        $this->actingAs($admin)
            ->deleteJson(route('it-tools.ai-settings.keys.destroy', 'openai'))
            ->assertOk();

        $this->assertSame('env-openai-key', config('ai.providers.openai.key'));
        $this->assertSame('env', AiSettings::keySource('openai'));

        // Env keys belong to the server config; the page cannot remove them.
        $this->actingAs($admin)
            ->deleteJson(route('it-tools.ai-settings.keys.destroy', 'openai'))
            ->assertUnprocessable();

        // Unknown/non-text providers 404.
        $this->actingAs($admin)
            ->deleteJson(route('it-tools.ai-settings.keys.destroy', 'cohere'))
            ->assertNotFound();
    }

    public function test_removing_the_selected_providers_key_falls_back_to_env_default(): void
    {
        AiSettings::putApiKey('anthropic', 'sk-ant-test-1234');
        AiSettings::put('anthropic', 'claude-test');
        $this->assertSame('anthropic', AiSettings::provider());

        $this->actingAs($this->staff())
            ->deleteJson(route('it-tools.ai-settings.keys.destroy', 'anthropic'))
            ->assertOk()
            ->assertJsonPath('current.override_provider', null)
            ->assertJsonPath('current.override_ignored', true);

        $this->assertSame((string) config('ai.default'), AiSettings::effectiveProvider());
    }

    /*
    |--------------------------------------------------------------------------
    | Test button
    |--------------------------------------------------------------------------
    */

    public function test_the_test_endpoint_probes_without_saving_anything(): void
    {
        $this->withOpenAiKey();

        ConnectionTestAgent::fake();

        $this->actingAs($this->staff())->postJson(route('it-tools.ai-settings.test'), [
            'provider' => 'openai',
            'model' => 'gpt-probe-model',
        ])->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonStructure(['ok', 'latency_ms', 'provider', 'model']);

        ConnectionTestAgent::assertPrompted(fn ($prompt) => $prompt->model === 'gpt-probe-model'
            && $prompt->provider instanceof OpenAiProvider);

        // A test run must never change the saved settings.
        $this->assertNull(AppSetting::getValue(AiSettings::KEY));
        $this->assertNull(AiSettings::provider());
    }

    /*
    |--------------------------------------------------------------------------
    | Vision + recorded model label
    |--------------------------------------------------------------------------
    */

    public function test_vision_model_follows_the_override_only_when_openai_is_selected(): void
    {
        $envVisionModel = (string) config('ai.providers.openai.models.text.default');
        $this->assertSame($envVisionModel, AiSettings::visionModel());

        $this->withOpenAiKey();
        AiSettings::put('openai', 'gpt-vision-override');
        $this->assertSame('gpt-vision-override', AiSettings::visionModel());

        // A non-OpenAI engine choice must not leak into the OpenAI-only
        // vision call.
        AiSettings::putApiKey('anthropic', 'sk-ant-test-1234');
        AiSettings::put('anthropic', 'claude-test');
        $this->assertSame($envVisionModel, AiSettings::visionModel());
    }
}
