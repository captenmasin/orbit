<?php

namespace Tests\Feature;

use App\Actions\ProtectCredential;
use App\Models\Project;
use App\Models\ProjectSecret;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Native\Desktop\Facades\Shell;
use Tests\TestCase;

class SecretMetadataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withSession(['secret_pin_unlocked_until' => now()->addMinutes(5)->timestamp]);
    }

    public function test_context_is_editable_without_crypto_and_preserves_names_environment_and_ciphertext(): void
    {
        $project = Project::factory()->create();
        $secret = ProjectSecret::factory()->for($project)->create(['name' => 'API_KEY', 'environment' => 'Production']);
        $this->mock(ProtectCredential::class, function ($mock): void {
            $mock->shouldNotReceive('encrypt');
            $mock->shouldNotReceive('decrypt');
        });

        $this->putJson('/projects/'.$project->id.'/secrets/'.$secret->id.'/metadata', [
            'project_revision' => 1, 'revision' => 1, 'service' => '  Stripe  ', 'description' => 'Billing dashboard',
            'management_url' => 'https://dashboard.stripe.com/', 'name' => 'CHANGED', 'environment' => 'Changed', 'ciphertext' => 'changed',
        ])->assertExactJson(['saved' => true]);

        $this->assertDatabaseHas('project_secrets', [
            'id' => $secret->id, 'name' => 'API_KEY', 'environment' => 'Production', 'ciphertext' => 'fixture-ciphertext',
            'service' => 'Stripe', 'description' => 'Billing dashboard', 'management_url' => 'https://dashboard.stripe.com/', 'revision' => 2,
        ]);
        $this->assertSame(2, $project->fresh()->revision);
        $this->assertDatabaseCount('credential_access_events', 0);
        $this->get('/projects/'.$project->id)->assertInertia(fn (Assert $page): Assert => $page
            ->where('selectedProject.secrets.0.service', 'Stripe')->where('selectedProject.secrets.0.description', 'Billing dashboard')
            ->where('selectedProject.secrets.0.management_url', 'https://dashboard.stripe.com/')->missing('selectedProject.secrets.0.ciphertext'));
    }

    public function test_context_updates_reject_stale_revisions_unsafe_urls_and_other_projects_without_writes(): void
    {
        $project = Project::factory()->create(['revision' => 2]);
        $secret = ProjectSecret::factory()->for($project)->create(['revision' => 2, 'service' => 'Redis']);
        $other = Project::factory()->create();
        $url = '/projects/'.$project->id.'/secrets/'.$secret->id.'/metadata';
        $data = ['project_revision' => 2, 'revision' => 2, 'service' => 'Changed'];

        $this->putJson($url, [...$data, 'project_revision' => 1])->assertConflict();
        $this->putJson($url, [...$data, 'revision' => 1])->assertConflict();
        $this->putJson($url, [...$data, 'management_url' => 'javascript:alert(1)'])->assertUnprocessable()->assertJsonValidationErrors('management_url');
        $this->putJson('/projects/'.$other->id.'/secrets/'.$secret->id.'/metadata', [...$data, 'project_revision' => 1])->assertNotFound();

        $this->assertDatabaseHas('project_secrets', ['id' => $secret->id, 'service' => 'Redis', 'ciphertext' => 'fixture-ciphertext', 'revision' => 2]);
        $this->assertSame(2, $project->fresh()->revision);
        $this->assertSame(1, $other->fresh()->revision);
    }

    public function test_creating_context_keeps_secret_uniqueness_independent_of_service_and_opens_validated_management_urls(): void
    {
        config(['nativephp-internal.running' => true]);
        $project = Project::factory()->create();
        $this->mock(ProtectCredential::class, function ($mock): void {
            $mock->shouldReceive('encrypt')->once()->with('dummy-value')->andReturn('encrypted-dummy');
        });
        $data = ['environment' => 'Production', 'name' => 'API_KEY', 'value' => 'dummy-value', 'service' => 'Stripe',
            'description' => 'Billing', 'management_url' => 'https://example.com/dashboard', 'project_revision' => 1];

        $this->postJson('/projects/'.$project->id.'/secrets', $data)->assertExactJson(['saved' => true]);
        $this->postJson('/projects/'.$project->id.'/secrets', [...$data, 'service' => 'Slack', 'project_revision' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $secret = $project->secrets()->sole();
        $this->assertSame('Stripe', $secret->service);
        Shell::shouldReceive('openExternal')->once()->with('https://example.com/dashboard');
        $this->postJson('/projects/'.$project->id.'/open/secrets/'.$secret->id)->assertExactJson(['opened' => true]);
        $secret->update(['management_url' => 'file:///etc/passwd']);
        $this->postJson('/projects/'.$project->id.'/open/secrets/'.$secret->id)->assertUnprocessable()->assertJsonValidationErrors('target');
    }

    public function test_bulk_metadata_updates_are_atomic_and_never_touch_ciphertext(): void
    {
        $project = Project::factory()->create();
        $first = ProjectSecret::factory()->for($project)->create(['name' => 'FIRST', 'environment' => 'Default']);
        $second = ProjectSecret::factory()->for($project)->create(['name' => 'SECOND', 'environment' => 'Default']);
        $selected = [['id' => $first->id, 'revision' => 1], ['id' => $second->id, 'revision' => 1]];
        $url = '/projects/'.$project->id.'/secrets/bulk';

        $this->putJson($url, ['project_revision' => 1, 'secrets' => $selected, 'service' => ' Stripe '])
            ->assertOk()->assertJsonPath('updated', 2);
        $this->assertSame(['Stripe', 'Stripe'], $project->secrets()->pluck('service')->all());
        $this->assertSame(['fixture-ciphertext', 'fixture-ciphertext'], $project->secrets()->pluck('ciphertext')->all());
        $this->putJson($url, ['project_revision' => 2, 'secrets' => $selected, 'environment' => 'Production'])->assertConflict();
        $this->assertSame(['Default', 'Default'], $project->secrets()->pluck('environment')->all());

        $selected = [['id' => $first->id, 'revision' => 2], ['id' => $second->id, 'revision' => 2]];
        $this->putJson($url, ['project_revision' => 2, 'secrets' => $selected, 'environment' => 'Production'])->assertOk();
        $this->assertSame(['Production', 'Production'], $project->secrets()->pluck('environment')->all());
        $this->assertSame(3, $project->fresh()->revision);
    }

    public function test_bulk_environment_change_rejects_name_collisions(): void
    {
        $project = Project::factory()->create();
        $source = ProjectSecret::factory()->for($project)->create(['name' => 'TOKEN', 'environment' => 'Default']);
        ProjectSecret::factory()->for($project)->create(['name' => 'TOKEN', 'environment' => 'Production']);

        $this->putJson('/projects/'.$project->id.'/secrets/bulk', [
            'project_revision' => 1, 'secrets' => [['id' => $source->id, 'revision' => 1]], 'environment' => 'Production',
        ])->assertUnprocessable()->assertJsonValidationErrors('environment');
        $this->assertSame('Default', $source->fresh()->environment);
        $this->assertSame(1, $project->fresh()->revision);
    }
}
