<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectSecret;
use App\Models\ProviderConnection;
use App\WorkspacePreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Native\Desktop\Facades\Settings;
use Native\Desktop\Facades\System;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class SecretRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private array $nativeSettings = [];

    private ?string $failedWrite = null;

    public function test_recovery_requires_the_desktop_app(): void
    {
        Settings::shouldReceive('get')->never();
        System::shouldReceive('promptTouchID')->never();

        $this->getJson('/secrets/recovery/status')->assertForbidden();
        $this->postJson('/secrets/recover')->assertForbidden();
        $this->postJson('/secrets/reset')->assertForbidden();
    }

    public function test_setup_returns_a_recovery_code_once_and_recovery_rotates_it_without_changing_secrets(): void
    {
        $this->mockNativeSettings();
        System::shouldReceive('canPromptTouchID')->andReturn(true);
        $secret = ProjectSecret::factory()->create();
        $code = $this->postJson('/secrets/pin', ['pin' => '1234', 'pin_confirmation' => '1234'])
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('recovery_code');
        $this->assertMatchesRegularExpression('/\A[A-F0-9]{8}(?:-[A-F0-9]{8}){7}\z/', $code);
        $this->assertStringNotContainsString($code, json_encode($this->nativeSettings));
        $this->getJson('/secrets/recovery/status')
            ->assertExactJson(['touch_id_available' => true, 'recovery_code_set' => true])
            ->assertHeader('Cache-Control', 'no-store, private');
        $oldSession = session()->all();
        RateLimiter::hit('secret-vault-pin', 300);

        $newCode = $this->postJson('/secrets/recover', [
            'method' => 'recovery_code', 'recovery_code' => strtolower(str_replace('-', ' ', $code)),
            'pin' => '4567', 'pin_confirmation' => '4567',
        ])->assertOk()->assertJsonPath('unlocked', false)->assertJsonPath('revision', 3)->json('recovery_code');

        $this->assertNotSame($code, $newCode);
        $this->assertTrue(Hash::check('4567', $this->nativeSettings['secrets.pin_hash']));
        $this->assertSame('fixture-ciphertext', $secret->fresh()->ciphertext);
        $this->withSession($oldSession)->getJson('/secrets/unlock/status')->assertJsonPath('unlocked', false);
        $this->postJson('/secrets/recover', ['method' => 'recovery_code', 'recovery_code' => $code, 'pin' => '9876', 'pin_confirmation' => '9876'])
            ->assertUnprocessable()->assertJsonValidationErrors('recovery_code');
        $this->postJson('/secrets/unlock', ['pin' => '4567'])->assertOk()->assertJsonPath('unlocked', true);
    }

    public function test_changing_a_pin_replaces_the_recovery_code(): void
    {
        $this->mockNativeSettings();
        $code = $this->postJson('/secrets/pin', ['pin' => '1234', 'pin_confirmation' => '1234'])->json('recovery_code');

        $newCode = $this->putJson('/secrets/pin', ['current_pin' => '1234', 'pin' => '4567', 'pin_confirmation' => '4567'])
            ->assertOk()->json('recovery_code');

        $this->assertNotSame($code, $newCode);
        $this->postJson('/secrets/recover', ['method' => 'recovery_code', 'recovery_code' => $code, 'pin' => '9876', 'pin_confirmation' => '9876'])
            ->assertUnprocessable()->assertJsonValidationErrors('recovery_code');
        $this->postJson('/secrets/recover', ['method' => 'recovery_code', 'recovery_code' => $newCode, 'pin' => '9876', 'pin_confirmation' => '9876'])
            ->assertOk()->assertJsonPath('unlocked', false);
    }

    public function test_touch_id_recovers_an_existing_pin_without_a_recovery_code(): void
    {
        $this->mockNativeSettings();
        $this->nativeSettings['secrets.pin_hash'] = Hash::make('1234');
        $secret = ProjectSecret::factory()->create();
        System::shouldReceive('canPromptTouchID')->twice()->andReturn(true);
        System::shouldReceive('promptTouchID')->once()->with('Reset your Orbit secrets PIN')->andReturn(true);
        $this->getJson('/secrets/recovery/status')->assertExactJson(['touch_id_available' => true, 'recovery_code_set' => false]);

        $this->postJson('/secrets/recover', ['method' => 'touch_id', 'pin' => '4567', 'pin_confirmation' => '4567'])
            ->assertOk()->assertJsonPath('unlocked', false)->assertJsonStructure(['recovery_code']);

        $this->assertTrue(Hash::check('4567', $this->nativeSettings['secrets.pin_hash']));
        $this->assertSame('fixture-ciphertext', $secret->fresh()->ciphertext);
    }

    #[TestWith(['unavailable'])]
    #[TestWith(['cancelled'])]
    #[TestWith(['transport'])]
    public function test_failed_touch_id_does_not_change_the_pin_or_secrets(string $failure): void
    {
        $this->mockNativeSettings();
        $this->nativeSettings['secrets.pin_hash'] = Hash::make('1234');
        $original = $this->nativeSettings;
        $secret = ProjectSecret::factory()->create();
        System::shouldReceive('canPromptTouchID')->once()->andReturn($failure !== 'unavailable');
        if ($failure === 'unavailable') {
            System::shouldReceive('promptTouchID')->never();
        } elseif ($failure === 'transport') {
            System::shouldReceive('promptTouchID')->once()->andThrow(new RuntimeException);
        } else {
            System::shouldReceive('promptTouchID')->once()->andReturn(false);
        }

        $this->postJson('/secrets/recover', ['method' => 'touch_id', 'pin' => '4567', 'pin_confirmation' => '4567'])
            ->assertUnprocessable()->assertJsonValidationErrors('method');

        $this->assertSame($original, $this->nativeSettings);
        $this->assertSame('fixture-ciphertext', $secret->fresh()->ciphertext);
    }

    public function test_touch_id_capability_errors_leave_recovery_code_available(): void
    {
        $this->mockNativeSettings();
        $this->postJson('/secrets/pin', ['pin' => '1234', 'pin_confirmation' => '1234'])->assertOk();
        System::shouldReceive('canPromptTouchID')->once()->andThrow(new RuntimeException);

        $this->getJson('/secrets/recovery/status')->assertExactJson(['touch_id_available' => false, 'recovery_code_set' => true]);
    }

    public function test_touch_id_cannot_overwrite_a_pin_changed_during_the_prompt(): void
    {
        $this->mockNativeSettings();
        $this->nativeSettings['secrets.pin_hash'] = Hash::make('1234');
        $changedHash = Hash::make('9876');
        System::shouldReceive('canPromptTouchID')->once()->andReturn(true);
        System::shouldReceive('promptTouchID')->once()->andReturnUsing(function () use ($changedHash): bool {
            $this->nativeSettings['secrets.pin_hash'] = $changedHash;

            return true;
        });

        $this->postJson('/secrets/recover', ['method' => 'touch_id', 'pin' => '4567', 'pin_confirmation' => '4567'])
            ->assertUnprocessable()->assertJsonPath('errors.pin.0', 'The PIN changed. Try again.');

        $this->assertSame(['secrets.pin_hash' => $changedHash], $this->nativeSettings);
    }

    public function test_incorrect_codes_are_rate_limited_and_sensitive_inputs_are_never_flashed(): void
    {
        $this->mockNativeSettings();
        $this->postJson('/secrets/pin', ['pin' => '1234', 'pin_confirmation' => '1234'])->assertOk();
        $original = $this->nativeSettings;
        $data = ['method' => 'recovery_code', 'recovery_code' => 'wrong-secret-code', 'pin' => '4567', 'pin_confirmation' => '4567'];
        $this->from('/settings')->post('/secrets/recover', [...$data, 'pin_confirmation' => '0000'])
            ->assertSessionHasErrors('pin_confirmation')->assertSessionMissing('_old_input.recovery_code')
            ->assertSessionMissing('_old_input.pin')->assertSessionMissing('_old_input.pin_confirmation');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/secrets/recover', $data)->assertUnprocessable()->assertJsonValidationErrors('recovery_code');
        }
        $this->postJson('/secrets/recover', $data)->assertTooManyRequests();

        $this->assertSame($original, $this->nativeSettings);
    }

    public function test_reset_requires_exact_confirmation_and_deletes_only_project_secrets_across_projects(): void
    {
        $this->mockNativeSettings();
        $this->postJson('/secrets/pin', ['pin' => '1234', 'pin_confirmation' => '1234'])->assertOk();
        $projects = Project::factory()->count(2)->has(ProjectSecret::factory(), 'secrets')->create();
        $connection = ProviderConnection::factory()->create();
        app(WorkspacePreferences::class)->merge(['ai' => ['credential' => 'encrypted-ai-key']]);
        $original = $this->nativeSettings;
        $oldSession = session()->all();
        $data = ['confirmation' => 'DELETE ALL SECRETS', 'pin' => '4567', 'pin_confirmation' => '4567'];
        $this->postJson('/secrets/reset', [...$data, 'confirmation' => 'delete all secrets'])
            ->assertUnprocessable()->assertJsonValidationErrors('confirmation');
        $this->postJson('/secrets/reset', [...$data, 'confirmation' => ' DELETE ALL SECRETS '])
            ->assertUnprocessable()->assertJsonValidationErrors('confirmation');
        $this->postJson('/secrets/reset', [...$data, 'pin_confirmation' => '0000'])
            ->assertUnprocessable()->assertJsonValidationErrors('pin_confirmation');
        $this->assertDatabaseCount('project_secrets', 2);
        $this->assertSame($original, $this->nativeSettings);

        $this->withSession([
            'secret-import:'.$projects[0]->id => ['path' => 'fixture.env'],
            'secret-export:'.$projects[0]->id => ['path' => 'fixture.env'],
            'backup-restore' => ['path' => 'fixture.orbit'], 'backup-export' => ['path' => 'fixture.orbit'],
        ])->postJson('/secrets/reset', $data)->assertOk()->assertJsonPath('unlocked', false)
            ->assertJsonStructure(['recovery_code'])->assertSessionMissing('secret-import:'.$projects[0]->id)
            ->assertSessionMissing('secret-export:'.$projects[0]->id)->assertSessionMissing('backup-restore')->assertSessionMissing('backup-export');

        $this->assertDatabaseCount('project_secrets', 0);
        $this->assertDatabaseCount('projects', 2);
        $this->assertSame('fixture-ciphertext', $connection->fresh()->encrypted_token);
        $this->assertSame('encrypted-ai-key', app(WorkspacePreferences::class)->get('ai.credential'));
        foreach ($projects as $project) {
            $this->assertSame(2, $project->fresh()->revision);
        }
        $this->withSession($oldSession)->getJson('/secrets/unlock/status')->assertJsonPath('unlocked', false);
        $this->postJson('/secrets/unlock', ['pin' => '4567'])->assertOk()->assertJsonPath('unlocked', true);
    }

    public function test_failed_database_reset_rolls_back_deletion_and_never_publishes_a_new_pin(): void
    {
        $this->mockNativeSettings();
        $this->postJson('/secrets/pin', ['pin' => '1234', 'pin_confirmation' => '1234'])->assertOk();
        $secret = ProjectSecret::factory()->create();
        $original = $this->nativeSettings;
        DB::statement("CREATE TRIGGER reject_vault_reset BEFORE UPDATE ON workspace_preferences BEGIN SELECT RAISE(ABORT, 'Cannot update preferences'); END");

        $this->postJson('/secrets/reset', ['confirmation' => 'DELETE ALL SECRETS', 'pin' => '4567', 'pin_confirmation' => '4567'])
            ->assertUnprocessable()->assertJsonValidationErrors('pin');

        $this->assertSame($original, $this->nativeSettings);
        $this->assertSame('fixture-ciphertext', $secret->fresh()->ciphertext);
        $this->assertSame(1, $secret->project->revision);
    }

    #[TestWith(['secrets.recovery'])]
    #[TestWith(['secrets.pin_hash'])]
    public function test_failed_native_reset_write_never_makes_surviving_secrets_accessible(string $failedWrite): void
    {
        $this->mockNativeSettings();
        $this->postJson('/secrets/pin', ['pin' => '1234', 'pin_confirmation' => '1234'])->assertOk();
        ProjectSecret::factory()->create();
        $this->failedWrite = $failedWrite;

        $this->postJson('/secrets/reset', ['confirmation' => 'DELETE ALL SECRETS', 'pin' => '4567', 'pin_confirmation' => '4567'])
            ->assertUnprocessable()->assertJsonPath('errors.pin.0', 'Secrets were deleted, but your new PIN could not be saved. Try resetting again.');

        $this->assertDatabaseCount('project_secrets', 0);
        $this->getJson('/secrets/unlock/status')->assertJsonPath('unlocked', false);
    }

    #[TestWith(['secrets.pin_hash'])]
    #[TestWith(['ignore:secrets.pin_hash'])]
    #[TestWith(['ignore:secrets.recovery'])]
    public function test_partial_recovery_write_preserves_the_previous_code_for_retry(string $failedWrite): void
    {
        $this->mockNativeSettings();
        $code = $this->postJson('/secrets/pin', ['pin' => '1234', 'pin_confirmation' => '1234'])->json('recovery_code');
        $originalHash = $this->nativeSettings['secrets.pin_hash'];
        $this->failedWrite = $failedWrite;
        $data = ['method' => 'recovery_code', 'recovery_code' => $code, 'pin' => '4567', 'pin_confirmation' => '4567'];

        $this->postJson('/secrets/recover', $data)->assertUnprocessable()->assertJsonValidationErrors('pin');

        $this->assertSame($originalHash, $this->nativeSettings['secrets.pin_hash']);
        $this->failedWrite = null;
        $this->postJson('/secrets/recover', $data)->assertOk()->assertJsonPath('unlocked', false);
        $this->assertTrue(Hash::check('4567', $this->nativeSettings['secrets.pin_hash']));
    }

    private function mockNativeSettings(): void
    {
        config(['nativephp-internal.running' => true]);
        Settings::shouldReceive('get')->andReturnUsing(fn (string $key): mixed => $this->nativeSettings[$key] ?? null);
        Settings::shouldReceive('set')->andReturnUsing(function (string $key, mixed $value): void {
            if ($key === $this->failedWrite) {
                throw new RuntimeException('Native settings unavailable.');
            }
            if ('ignore:'.$key === $this->failedWrite) {
                return;
            }
            $this->nativeSettings[$key] = $value;
        });
    }
}
