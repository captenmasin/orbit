<?php

namespace Tests\Feature;

use App\Actions\ProtectCredential;
use App\Models\Project;
use App\Models\ProjectSecret;
use App\WorkspacePreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Native\Desktop\Events\Windows\WindowClosed;
use Native\Desktop\Events\Windows\WindowHidden;
use Native\Desktop\Facades\Settings;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class SecretVaultTest extends TestCase
{
    use RefreshDatabase;

    private ?string $nativePinHash = null;

    private ?array $nativeRecovery = null;

    public function test_pin_setup_requires_the_desktop_app_and_exactly_four_digits(): void
    {
        $this->mockNativeSettings();
        $this->postJson('/secrets/pin', ['pin' => '1234', 'pin_confirmation' => '1234'])->assertForbidden();
        $this->withSession(['secrets_unlocked_until' => now()->addMinutes(5)->timestamp])
            ->getJson('/secrets/unlock/status')->assertJsonPath('unlocked', false)->assertJsonPath('unlocked_until', null);
        config(['nativephp-internal.running' => true]);

        foreach (['123', '12345', '12ab'] as $invalid) {
            $this->postJson('/secrets/pin', ['pin' => $invalid, 'pin_confirmation' => $invalid])->assertUnprocessable()->assertJsonValidationErrors('pin');
        }
        $this->from('/')->post('/secrets/pin', ['pin' => '1234', 'pin_confirmation' => '4321'])
            ->assertSessionHasErrors('pin_confirmation')->assertSessionMissing('_old_input.pin');
        $this->postJson('/secrets/pin', ['pin' => '1234', 'pin_confirmation' => '1234'])->assertOk()->assertJsonPath('unlocked', true)->assertJsonPath('revision', 2);
        $this->assertSame(2, app(WorkspacePreferences::class)->snapshot()['revision']);
        $this->assertTrue(Hash::check('1234', $this->nativePinHash));
        $this->assertDatabaseCount('secret_vaults', 0);
        $this->getJson('/secrets/unlock/status')->assertJsonPath('pin_set', true)->assertJsonPath('unlocked', true);
        $this->postJson('/secrets/pin', ['pin' => '9999', 'pin_confirmation' => '9999'])->assertUnprocessable();
        $this->assertTrue(Hash::check('1234', $this->nativePinHash));
    }

    public function test_wrong_pin_is_rate_limited_and_correct_pin_unlocks_after_lock(): void
    {
        $this->mockNativeSettings();
        config(['nativephp-internal.running' => true]);
        RateLimiter::clear('secret-vault-pin');
        $this->postJson('/secrets/pin', ['pin' => '0123', 'pin_confirmation' => '0123'])->assertOk();
        $this->postJson('/secrets/lock')->assertOk();
        $this->getJson('/secrets/unlock/status')->assertJsonPath('unlocked', false)->assertJsonPath('unlocked_until', null);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/secrets/unlock', ['pin' => '9999'])->assertUnprocessable()->assertJsonValidationErrors('pin');
        }
        $this->postJson('/secrets/unlock', ['pin' => '0123'])->assertStatus(429);
        RateLimiter::clear('secret-vault-pin');
        $this->postJson('/secrets/unlock', ['pin' => '0123'])->assertOk()->assertJsonPath('unlocked', true);
        $this->getJson('/secrets/unlock/status')->assertJsonPath('unlocked', true);
    }

    public function test_pin_unlock_survives_later_requests_and_expires_fifteen_minutes_after_unlock(): void
    {
        $this->mockNativeSettings();
        config(['nativephp-internal.running' => true]);
        $this->travelTo(Carbon::parse('2026-09-26 08:00:00 UTC'));
        $project = Project::factory()->create();
        $url = '/projects/'.$project->id.'/secrets/values';

        $setup = $this->postJson('/secrets/pin', ['pin' => '0123', 'pin_confirmation' => '0123'])
            ->assertOk()->assertJsonPath('unlocked_until', 1790410500)->assertSessionHas('secret_pin_unlocked_until', 1790410500);
        $this->assertGreaterThanOrEqual(1790410500, $setup->getCookie(config('session.cookie'))->getExpiresTime());
        $this->postJson('/secrets/lock')->assertOk()->assertSessionMissing('secret_pin_unlocked_until');
        $this->travelTo(Carbon::parse('2026-09-26 08:15:00 UTC'));
        $this->postJson('/secrets/unlock', ['pin' => '0123'])
            ->assertOk()->assertJsonPath('unlocked_until', 1790411400)->assertSessionHas('secret_pin_unlocked_until', 1790411400);

        $this->travelTo(Carbon::parse('2026-09-26 08:29:59 UTC'));
        $this->getJson('/secrets/unlock/status')
            ->assertOk()->assertExactJson(['pin_set' => true, 'unlocked' => true, 'unlocked_until' => 1790411400]);
        $this->getJson($url)->assertOk();
        $this->getJson('/secrets/unlock/status')->assertJsonPath('unlocked_until', 1790411400);

        $this->travelTo(Carbon::parse('2026-09-26 08:30:00 UTC'));
        $this->getJson('/secrets/unlock/status')
            ->assertOk()->assertExactJson(['pin_set' => true, 'unlocked' => false, 'unlocked_until' => null]);
        $this->getJson($url)->assertStatus(423);
    }

    public function test_values_require_a_recent_pin_unlock_and_lock_revokes_it(): void
    {
        $this->mockNativeSettings();
        config(['nativephp-internal.running' => true]);
        $project = Project::factory()->create();
        $secret = ProjectSecret::factory()->for($project)->create(['ciphertext' => 'encrypted-value']);
        $url = '/projects/'.$project->id.'/secrets/values';
        $this->getJson($url)->assertStatus(423);
        $this->postJson('/secrets/pin', ['pin' => '1234', 'pin_confirmation' => '1234'])->assertOk();

        $this->mock(ProtectCredential::class, function ($mock): void {
            $mock->shouldReceive('decrypt')->once()->with('encrypted-value')->andReturn('plain-value');
        });
        $this->getJson($url)->assertOk()->assertExactJson(['values' => [$secret->id => 'plain-value']])->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson('/secrets/lock')->assertOk();
        $this->getJson($url)->assertStatus(423);
        $this->withSession(['secret_pin_unlocked_until' => now()->subSecond()->timestamp])->getJson($url)->assertStatus(423);
    }

    #[TestWith([5, 1790409900])]
    #[TestWith([15, 1790410500])]
    #[TestWith([60, 1790413200])]
    #[TestWith([480, 1790438400])]
    public function test_unlock_uses_the_saved_duration_and_requests_do_not_extend_it(int $minutes, int $until): void
    {
        $this->mockNativeSettings();
        config(['nativephp-internal.running' => true]);
        $this->travelTo(Carbon::parse('2026-09-26 08:00:00 UTC'));
        app(WorkspacePreferences::class)->merge(['security' => ['lock_minutes' => $minutes]]);
        $this->postJson('/secrets/pin', ['pin' => '0123', 'pin_confirmation' => '0123'])
            ->assertOk()->assertJsonPath('unlocked_until', $until);
        $this->travel($minutes * 60 - 1)->seconds();
        $this->getJson('/secrets/unlock/status')->assertJsonPath('unlocked', true)->assertJsonPath('unlocked_until', $until);

        $this->travel(1)->seconds();

        $this->getJson('/secrets/unlock/status')->assertJsonPath('unlocked', false)
            ->assertSessionMissing('secret_pin_version')->assertSessionMissing('secret_pin_unlocked_until');
    }

    #[TestWith([WindowClosed::class])]
    #[TestWith([WindowHidden::class])]
    public function test_closing_or_hiding_the_native_window_revokes_existing_unlocks(string $event): void
    {
        $this->mockNativeSettings();
        config(['nativephp-internal.running' => true]);
        $this->postJson('/secrets/pin', ['pin' => '0123', 'pin_confirmation' => '0123'])->assertOk();

        $event::dispatch('main');

        $this->getJson('/secrets/unlock/status')->assertJsonPath('unlocked', false)->assertJsonPath('unlocked_until', null);
        $this->postJson('/secrets/unlock', ['pin' => '0123'])->assertOk()->assertJsonPath('unlocked', true);
    }

    public function test_changing_the_lock_duration_revokes_the_current_unlock_without_extending_it(): void
    {
        $this->mockNativeSettings();
        config(['nativephp-internal.running' => true]);
        $this->postJson('/secrets/pin', ['pin' => '0123', 'pin_confirmation' => '0123'])->assertOk();

        app(WorkspacePreferences::class)->merge(['security' => ['lock_minutes' => 480]]);

        $this->getJson('/secrets/unlock/status')->assertJsonPath('unlocked', false)->assertJsonPath('unlocked_until', null);
    }

    public function test_expired_vault_rejects_every_protected_operation_without_writes(): void
    {
        $this->mockNativeSettings();
        config(['nativephp-internal.running' => true]);
        $this->postJson('/secrets/pin', ['pin' => '0123', 'pin_confirmation' => '0123'])->assertOk();
        $this->travel(15)->minutes();
        $project = Project::factory()->create();
        $secret = ProjectSecret::factory()->for($project)->create();
        $base = '/projects/'.$project->id.'/secrets';

        foreach ([
            ['post', $base],
            ['post', $base.'/paste'],
            ['put', $base.'/bulk'],
            ['delete', $base.'/bulk'],
            ['post', $base.'/import/preview'],
            ['post', $base.'/import'],
            ['post', $base.'/export/preview'],
            ['post', $base.'/export'],
            ['put', $base.'/'.$secret->id],
            ['put', $base.'/'.$secret->id.'/metadata'],
            ['put', $base.'/'.$secret->id.'/description'],
            ['delete', $base.'/'.$secret->id],
            ['get', $base.'/values'],
            ['get', $base.'/'.$secret->id.'/value'],
            ['post', $base.'/'.$secret->id.'/copy'],
        ] as [$method, $url]) {
            $this->{$method.'Json'}($url, [])->assertStatus(423);
        }

        $this->assertDatabaseCount('project_secrets', 1);
        $this->assertSame('fixture-ciphertext', $secret->fresh()->ciphertext);
        $this->assertSame(1, $project->fresh()->revision);
    }

    public function test_settings_show_whether_one_shared_native_pin_is_set(): void
    {
        $this->mockNativeSettings();
        config(['nativephp-internal.running' => true]);

        $this->get('/settings')->assertInertia(fn (Assert $page): Assert => $page
            ->component('Settings')->where('pinSet', false)->missing('pin_hash'));
        $this->postJson('/secrets/pin', ['pin' => '0123', 'pin_confirmation' => '0123'])->assertOk();
        $this->get('/settings')->assertInertia(fn (Assert $page): Assert => $page
            ->component('Settings')->where('pinSet', true)->missing('pin_hash'));
    }

    public function test_changing_the_shared_pin_revokes_previous_sessions_for_all_projects(): void
    {
        $this->mockNativeSettings();
        config(['nativephp-internal.running' => true]);
        $projects = Project::factory()->count(2)->create();
        $this->postJson('/secrets/pin', ['pin' => '0123', 'pin_confirmation' => '0123'])->assertOk();
        $oldSession = [
            'secret_pin_unlocked_until' => session('secret_pin_unlocked_until'),
            'secret_pin_version' => session('secret_pin_version'),
            'secret_pin_generation' => session('secret_pin_generation'),
            'secret_pin_lock_minutes' => session('secret_pin_lock_minutes'),
        ];
        foreach ($projects as $project) {
            $this->getJson('/projects/'.$project->id.'/secrets/values')->assertOk();
        }

        $this->putJson('/secrets/pin', ['current_pin' => '0123', 'pin' => '4567', 'pin_confirmation' => '4567'])
            ->assertOk()->assertJsonPath('unlocked', false)->assertJsonPath('pin_set', true)->assertJsonPath('revision', 3);
        $this->assertTrue(Hash::check('4567', $this->nativePinHash));
        $this->assertFalse(Hash::check('0123', $this->nativePinHash));
        $this->assertDatabaseCount('secret_vaults', 0);
        foreach ($projects as $project) {
            $this->getJson('/projects/'.$project->id.'/secrets/values')->assertStatus(423);
        }

        $this->withSession($oldSession)->getJson('/secrets/unlock/status')
            ->assertJsonPath('unlocked', false)->assertJsonPath('unlocked_until', null);
        foreach ($projects as $project) {
            $this->getJson('/projects/'.$project->id.'/secrets/values')->assertStatus(423);
        }
        $this->postJson('/secrets/unlock', ['pin' => '0123'])->assertUnprocessable()->assertJsonValidationErrors('pin');
        $this->postJson('/secrets/unlock', ['pin' => '4567'])->assertOk()->assertJsonPath('unlocked', true);
        foreach ($projects as $project) {
            $this->getJson('/projects/'.$project->id.'/secrets/values')->assertOk();
        }
    }

    public function test_pin_changes_require_the_desktop_app_current_pin_and_matching_confirmation(): void
    {
        $this->mockNativeSettings();
        $data = ['current_pin' => '1234', 'pin' => '4567', 'pin_confirmation' => '4567'];
        $this->putJson('/secrets/pin', $data)->assertForbidden();
        config(['nativephp-internal.running' => true]);
        $this->putJson('/secrets/pin', $data)->assertUnprocessable()->assertJsonValidationErrors('current_pin');
        $this->postJson('/secrets/pin', ['pin' => '1234', 'pin_confirmation' => '1234'])->assertOk();
        $originalHash = $this->nativePinHash;

        $this->putJson('/secrets/pin', [...$data, 'current_pin' => '9999'])
            ->assertUnprocessable()->assertJsonPath('errors.current_pin.0', 'Incorrect PIN.');
        $this->putJson('/secrets/pin', [...$data, 'current_pin' => '123'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_pin');
        $this->putJson('/secrets/pin', [...$data, 'pin' => '12ab'])
            ->assertUnprocessable()->assertJsonValidationErrors('pin');
        $this->from('/settings')->put('/secrets/pin', [...$data, 'pin_confirmation' => '9876'])
            ->assertSessionHasErrors('pin_confirmation')->assertSessionMissing('_old_input.current_pin')
            ->assertSessionMissing('_old_input.pin')->assertSessionMissing('_old_input.pin_confirmation');

        $this->assertSame($originalHash, $this->nativePinHash);
        $this->getJson('/secrets/unlock/status')->assertJsonPath('unlocked', true);
    }

    public function test_pin_changes_share_the_unlock_attempt_limit(): void
    {
        $this->mockNativeSettings();
        config(['nativephp-internal.running' => true]);
        RateLimiter::clear('secret-vault-pin');
        $this->postJson('/secrets/pin', ['pin' => '1234', 'pin_confirmation' => '1234'])->assertOk();
        $originalHash = $this->nativePinHash;
        $data = ['current_pin' => '9999', 'pin' => '4567', 'pin_confirmation' => '4567'];

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->putJson('/secrets/pin', $data)->assertUnprocessable()->assertJsonValidationErrors('current_pin');
        }
        $this->postJson('/secrets/unlock', ['pin' => '1234'])->assertTooManyRequests();
        $this->putJson('/secrets/pin', [...$data, 'current_pin' => '1234'])->assertTooManyRequests();

        $this->assertSame($originalHash, $this->nativePinHash);
    }

    public function test_existing_pin_is_migrated_to_native_settings_without_changing_it(): void
    {
        $this->mockNativeSettings();
        config(['nativephp-internal.running' => true]);
        $originalHash = Hash::make('0123');
        DB::table('secret_vaults')->insert(['id' => 1, 'pin_hash' => $originalHash]);

        $this->get('/settings')->assertInertia(fn (Assert $page): Assert => $page->where('pinSet', true));

        $this->assertSame($originalHash, $this->nativePinHash);
        $this->assertDatabaseCount('secret_vaults', 0);
        $this->postJson('/secrets/unlock', ['pin' => '0123'])->assertOk()->assertJsonPath('unlocked', true);
    }

    public function test_a_concurrent_pin_change_does_not_overwrite_the_newer_pin(): void
    {
        config(['nativephp-internal.running' => true]);
        $originalHash = Hash::make('0123');
        $changedHash = Hash::make('4567');
        Settings::shouldReceive('get')->with('secrets.pin_hash')->andReturn($originalHash, $changedHash);
        Settings::shouldReceive('set')->never();

        $this->withSession([
            'secret_pin_unlocked_until' => now()->addHours(8)->timestamp,
            'secret_pin_version' => hash('sha256', $originalHash),
        ])->putJson('/secrets/pin', ['current_pin' => '0123', 'pin' => '9876', 'pin_confirmation' => '9876'])
            ->assertUnprocessable()->assertJsonPath('errors.pin.0', 'The PIN changed. Try again.');

        $this->getJson('/secrets/unlock/status')->assertJsonPath('unlocked', false)->assertJsonPath('pin_set', true);
    }

    #[TestWith(['read'])]
    #[TestWith(['write'])]
    #[TestWith(['readback'])]
    public function test_native_settings_failure_keeps_the_existing_pin_and_session_locked(string $failure): void
    {
        config(['nativephp-internal.running' => true]);
        $originalHash = Hash::make('0123');
        DB::table('secret_vaults')->insert(['id' => 1, 'pin_hash' => $originalHash]);
        $read = Settings::shouldReceive('get')->with('secrets.pin_hash');
        if ($failure === 'read') {
            $read->once()->andThrow(new RuntimeException('Native settings unavailable.'));
            Settings::shouldReceive('set')->never();
        } else {
            $read->andReturnNull();
            $write = Settings::shouldReceive('set')->once()->with('secrets.pin_hash', $originalHash);
            if ($failure === 'write') {
                $write->andThrow(new RuntimeException('Native settings unavailable.'));
            } else {
                $write->andReturnNull();
            }
        }

        $this->postJson('/secrets/unlock', ['pin' => '0123'])
            ->assertUnprocessable()->assertJsonValidationErrors('pin')->assertSessionMissing('secret_pin_version');

        $this->assertDatabaseHas('secret_vaults', ['id' => 1, 'pin_hash' => $originalHash]);
    }

    private function mockNativeSettings(): void
    {
        Settings::shouldReceive('get')->with('secrets.recovery')->andReturnUsing(fn (): ?array => $this->nativeRecovery);
        Settings::shouldReceive('set')->with('secrets.recovery', \Mockery::type('array'))
            ->andReturnUsing(function (string $key, array $recovery): void {
                $this->nativeRecovery = $recovery;
            });
        Settings::shouldReceive('get')->with('secrets.pin_hash')->andReturnUsing(fn (): ?string => $this->nativePinHash);
        Settings::shouldReceive('set')->with('secrets.pin_hash', \Mockery::type('string'))
            ->andReturnUsing(function (string $key, string $hash): void {
                $this->nativePinHash = $hash;
            });
    }
}
