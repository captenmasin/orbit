<?php

namespace Tests\Feature;

use App\Actions\ProtectCredential;
use App\Models\Project;
use App\Models\ProjectSecret;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class SecretVaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_pin_setup_requires_the_desktop_app_and_exactly_four_digits(): void
    {
        $this->postJson('/secrets/pin', ['pin' => '1234', 'pin_confirmation' => '1234'])->assertForbidden();
        $this->withSession(['secrets_unlocked_until' => now()->addMinutes(5)->timestamp])
            ->getJson('/secrets/unlock/status')->assertJsonPath('unlocked', false);
        config(['nativephp-internal.running' => true]);

        foreach (['123', '12345', '12ab'] as $invalid) {
            $this->postJson('/secrets/pin', ['pin' => $invalid, 'pin_confirmation' => $invalid])->assertUnprocessable()->assertJsonValidationErrors('pin');
        }
        $this->from('/')->post('/secrets/pin', ['pin' => '1234', 'pin_confirmation' => '4321'])
            ->assertSessionHasErrors('pin_confirmation')->assertSessionMissing('_old_input.pin');
        $this->postJson('/secrets/pin', ['pin' => '1234', 'pin_confirmation' => '1234'])->assertOk()->assertJsonPath('unlocked', true);
        $this->assertTrue(Hash::check('1234', DB::table('secret_vaults')->where('id', 1)->value('pin_hash')));
        $this->getJson('/secrets/unlock/status')->assertJsonPath('pin_set', true)->assertJsonPath('unlocked', true);
        $this->postJson('/secrets/pin', ['pin' => '9999', 'pin_confirmation' => '9999'])->assertUnprocessable();
        $this->assertTrue(Hash::check('1234', DB::table('secret_vaults')->where('id', 1)->value('pin_hash')));
    }

    public function test_wrong_pin_is_rate_limited_and_correct_pin_unlocks_after_lock(): void
    {
        config(['nativephp-internal.running' => true]);
        RateLimiter::clear('secret-vault-pin');
        $this->postJson('/secrets/pin', ['pin' => '0123', 'pin_confirmation' => '0123'])->assertOk();
        $this->postJson('/secrets/lock')->assertOk();
        $this->getJson('/secrets/unlock/status')->assertJsonPath('unlocked', false);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/secrets/unlock', ['pin' => '9999'])->assertUnprocessable()->assertJsonValidationErrors('pin');
        }
        $this->postJson('/secrets/unlock', ['pin' => '0123'])->assertStatus(429);
        RateLimiter::clear('secret-vault-pin');
        $this->postJson('/secrets/unlock', ['pin' => '0123'])->assertOk()->assertJsonPath('unlocked', true);
        $this->getJson('/secrets/unlock/status')->assertJsonPath('unlocked', true);
    }

    public function test_values_require_a_recent_pin_unlock_and_lock_revokes_it(): void
    {
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

    public function test_locked_vault_rejects_secret_management_requests_without_writes(): void
    {
        $project = Project::factory()->create();
        $secret = ProjectSecret::factory()->for($project)->create();
        $base = '/projects/'.$project->id.'/secrets';

        foreach ([
            ['post', $base],
            ['post', $base.'/paste'],
            ['put', $base.'/bulk'],
            ['post', $base.'/import/preview'],
            ['post', $base.'/import'],
            ['post', $base.'/export/preview'],
            ['put', $base.'/'.$secret->id],
            ['put', $base.'/'.$secret->id.'/metadata'],
            ['delete', $base.'/'.$secret->id],
        ] as [$method, $url]) {
            $this->{$method.'Json'}($url, [])->assertStatus(423);
        }

        $this->assertDatabaseCount('project_secrets', 1);
        $this->assertSame('fixture-ciphertext', $secret->fresh()->ciphertext);
        $this->assertSame(1, $project->fresh()->revision);
    }
}
