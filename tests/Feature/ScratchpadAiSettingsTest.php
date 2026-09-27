<?php

namespace Tests\Feature;

use App\Actions\ProtectCredential;
use App\Models\Project;
use App\ScratchpadAi;
use App\WorkspacePreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Laravel\Ai\AiManager;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\StructuredAnonymousAgent;
use Tests\TestCase;

class ScratchpadAiSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_connections_offer_provider_default_models_without_saving_a_connection(): void
    {
        $defaultModels = ['openai' => 'default-openai', 'anthropic' => 'default-anthropic', 'gemini' => 'default-gemini'];
        foreach ($defaultModels as $provider => $model) {
            config(['ai.providers.'.$provider.'.models.text.default' => $model]);
        }
        Http::preventStrayRequests();

        $this->get('/settings/connections')->assertInertia(fn (AssertableInertia $page) => $page->component('Settings')
            ->where('ai.default_models', $defaultModels)->where('ai.configured', false)->where('ai.provider', null)->where('ai.model', null));

        $this->assertDatabaseCount('workspace_preferences', 0);
        Http::assertNothingSent();
    }

    public function test_verification_saves_encrypted_key_and_selected_model_without_exposing_it(): void
    {
        config(['nativephp-internal.running' => true]);
        StructuredAnonymousAgent::fake([['ready' => true]])->preventStrayPrompts();
        $this->mock(ProtectCredential::class, fn ($mock) => $mock->shouldReceive('encrypt')->with('synthetic-key')->once()->andReturn('encrypted-key'));

        $this->putJson('/settings/connections/ai', ['revision' => 1, 'provider' => 'anthropic', 'model' => 'explicit-model', 'key' => 'synthetic-key'])
            ->assertOk()->assertJsonPath('revision', 2)->assertJsonPath('ai.configured', true)->assertJsonMissing(['key' => 'synthetic-key', 'credential' => 'encrypted-key']);
        $this->assertSame(['provider' => 'anthropic', 'model' => 'explicit-model', 'credential' => 'encrypted-key'], app(WorkspacePreferences::class)->get('ai'));
        $this->assertNull(config('ai.providers.orbit-scratchpad'));
        StructuredAnonymousAgent::assertPrompted(fn ($prompt): bool => $prompt->model === 'explicit-model');
    }

    public function test_failed_verification_and_stale_replacement_preserve_the_working_configuration(): void
    {
        config(['nativephp-internal.running' => true]);
        $preferences = app(WorkspacePreferences::class);
        $working = ['provider' => 'openai', 'model' => 'old-model', 'credential' => 'old-encrypted-key'];
        $preferences->merge(['ai' => $working]);
        StructuredAnonymousAgent::fake([fn () => throw new AiException('synthetic-key should never be returned')])->preventStrayPrompts();
        $this->putJson('/settings/connections/ai', ['revision' => 2, 'provider' => 'openai', 'model' => 'new-model', 'key' => 'synthetic-key'])
            ->assertUnprocessable()->assertJsonValidationErrors('key')->assertDontSee('should never be returned');
        $this->assertSame($working, $preferences->get('ai'));

        StructuredAnonymousAgent::fake([['ready' => true]])->preventStrayPrompts();
        $this->mock(ProtectCredential::class, fn ($mock) => $mock->shouldReceive('encrypt')->andReturn('new-encrypted-key'));
        $this->putJson('/settings/connections/ai', ['revision' => 1, 'provider' => 'gemini', 'model' => 'new-model', 'key' => 'synthetic-key'])->assertConflict();
        $this->assertSame($working, $preferences->get('ai'));
    }

    public function test_removing_ai_keeps_notes_and_env_keys_cannot_enable_generation(): void
    {
        config(['nativephp-internal.running' => true, 'ai.providers.openai.key' => 'developer-key']);
        $preferences = app(WorkspacePreferences::class);
        $preferences->merge(['ai' => ['provider' => 'openai', 'model' => 'model', 'credential' => 'encrypted']]);
        $project = Project::factory()->create(['scratchpad' => 'Make a launch checklist']);
        StructuredAnonymousAgent::fake()->preventStrayPrompts();
        $this->deleteJson('/settings/connections/ai', ['revision' => 2])->assertOk()->assertJsonPath('ai.configured', false);
        $this->postJson('/projects/'.$project->id.'/scratchpad/actions/preview', ['revision' => 1])->assertUnprocessable()->assertJsonValidationErrors('scratchpad');
        $this->assertSame('Make a launch checklist', $project->fresh()->scratchpad);
        StructuredAnonymousAgent::assertNeverPrompted();
    }

    public function test_each_generation_uses_saved_configuration_and_clears_process_cached_credentials(): void
    {
        $preferences = app(WorkspacePreferences::class);
        $this->mock(ProtectCredential::class, fn ($mock) => $mock->shouldReceive('decrypt')->with('encrypted')->andReturn('synthetic-key'));
        $ai = app(ScratchpadAi::class);
        foreach (['openai', 'anthropic'] as $provider) {
            $preferences->merge(['ai' => ['provider' => $provider, 'model' => 'chosen-'.$provider, 'credential' => 'encrypted']]);
            $result = $ai->configured(fn (string $name, string $model): array => [app(AiManager::class)->textProvider($name)->driver(), $model]);
            $this->assertSame([$provider, 'chosen-'.$provider], $result);
            $this->assertNull(config('ai.providers.orbit-scratchpad'));
        }
    }

    public function test_browser_cannot_save_credentials_and_settings_props_contain_only_status(): void
    {
        $preferences = app(WorkspacePreferences::class);
        $preferences->merge(['ai' => ['provider' => 'openai', 'model' => 'selected-model', 'credential' => 'encrypted-private-value']]);
        $this->putJson('/settings/connections/ai', ['revision' => 2])->assertForbidden();
        $this->get('/settings/connections')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Settings')->where('ai.configured', true)->where('ai.provider', 'openai')->where('ai.model', 'selected-model')->missing('preferences.values.ai')->missing('ai.key')->missing('ai.credential'));
    }
}
