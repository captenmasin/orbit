<?php

namespace Tests\Feature;

use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class WorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_projects_can_be_created_opened_and_edited_without_stale_overwrites(): void
    {
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page): Assert => $page
            ->component('Dashboard')->has('projects.data', 0)->missing('selectedProject'));
        $response = $this->post('/projects', ['name' => '  Orbit  ', 'status' => 'Idea']);
        $project = Project::sole();
        $url = '/projects/'.$project->id;
        $response->assertRedirect($url);
        $this->assertSame('Orbit', $project->name);
        $this->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertInertia(fn (Assert $page): Assert => $page->component('ShowProject')
                ->where('selectedProject.id', $project->id)->where('selectedProject.revision', 1)
                ->has('statuses', 6)->where('statuses.0', 'Idea')->where('statuses.5', 'Archived'));

        $this->withHeader('X-Inertia', 'true')->put($url, [
            'name' => 'Updated project', 'description' => 'A description', 'status' => 'Live', 'revision' => 1,
        ])->assertStatus(303)->assertRedirect($url);
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'name' => 'Updated project', 'status' => 'Live', 'revision' => 2]);
        $this->flushHeaders()->get('/')->assertInertia(fn (Assert $page): Assert => $page
            ->where('projects.data.0.name', 'Updated project')->where('projects.data.0.description', 'A description'));
        $this->put($url, ['name' => 'Stale edit', 'status' => 'Idea', 'revision' => 1])->assertStatus(409);
        $this->assertSame('Updated project', $project->fresh()->name);
    }

    public function test_failed_forms_keep_their_input_and_expose_inertia_errors(): void
    {
        $this->from('/')->post('/projects', [
            'name' => '  ', 'status' => 'unknown', 'description' => 'Keep my draft',
        ])->assertRedirect('/')->assertSessionHasErrors(['name', 'status'])->assertSessionHasInput('description', 'Keep my draft');
        $this->withCookie(config('session.cookie'), session()->getId())
            ->withHeader('X-Inertia-Error-Bag', 'createProject')->get('/')
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('errors.createProject.name', 'The name field is required.')
                ->where('errors.createProject.status', 'The selected status is invalid.'));
        $this->assertDatabaseCount('projects', 0);

        $project = Project::create(['name' => 'Existing project', 'status' => 'Idea']);
        $url = '/projects/'.$project->id;
        $this->from($url)->withHeader('X-Inertia', 'true')->put($url, [
            'name' => 'Draft name', 'description' => 'Draft description', 'status' => 'unknown', 'revision' => 1,
        ])->assertRedirect($url)->assertSessionHasErrors('status')->assertSessionHasInput('description', 'Draft description');
        $this->flushHeaders()->withHeader('X-Inertia-Error-Bag', 'editProject')->get($url)
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('errors.editProject.status', 'The selected status is invalid.')
                ->missing('errors.createProject')->where('selectedProject.name', 'Existing project'));
        $this->assertSame('Existing project', $project->fresh()->name);
    }

    public function test_inertia_stale_edits_preserve_the_draft_and_return_a_form_error(): void
    {
        $project = Project::create(['name' => 'Current name', 'status' => 'Live']);
        $url = '/projects/'.$project->id;

        $this->from($url)->withHeader('X-Inertia', 'true')->put($url, [
            'name' => 'My unsaved draft', 'description' => 'Keep this text', 'status' => 'Paused', 'revision' => 2,
        ])->assertRedirect($url)
            ->assertSessionHasErrors(['revision' => 'This project changed. Reload it before saving again.'])
            ->assertSessionHasInput('description', 'Keep this text');
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'name' => 'Current name', 'status' => 'Live', 'revision' => 1]);
    }

    public function test_project_lists_are_paginated_and_missing_projects_are_not_found(): void
    {
        for ($i = 0; $i < 26; $i++) {
            Project::create(['name' => 'Project '.$i, 'status' => 'Idea']);
        }
        $page = $this->get('/')->assertOk()->assertInertia(fn (Assert $page): Assert => $page
            ->has('projects.data', 25)->where('projects.next_page_url', '/?page=2'));
        $version = $page->inertiaPage()['version'];
        $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version])->get('/?page=2')
            ->assertOk()->assertHeader('X-Inertia', 'true')->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('component', 'Dashboard')->assertJsonCount(1, 'props.projects.data')
            ->assertJsonPath('props.projects.prev_page_url', '/?page=1');
        $this->get('/projects/missing')->assertNotFound();
    }

    public function test_removed_preview_features_have_no_routes_or_storage(): void
    {
        $project = Project::create(['name' => 'Keep this project', 'status' => 'Idea']);
        $migration = require database_path('migrations/2026_09_14_000002_remove_preview_tools.php');
        $migration->up();
        $this->assertSame('Keep this project', $project->fresh()->name);
        $this->assertFalse(Schema::hasTable('mcp_clients'));
        $this->assertFalse(Schema::hasTable('integration_checks'));
        $this->post('/clients')->assertNotFound();
        $this->delete('/clients/example')->assertNotFound();
        $this->post('/checks/encryption')->assertNotFound();
        $this->get('/')->assertInertia(fn (Assert $page): Assert => $page->missing('clients')->missing('encryptionCheck'));
    }

    public function test_native_bootstrap_keeps_caches_outside_the_bundle_and_isolates_launches(): void
    {
        $directory = sys_get_temp_dir().'/orbit-cache-'.bin2hex(random_bytes(8));
        $paths = [];
        foreach (['first-launch', 'second-launch'] as $secret) {
            $process = new Process([
                PHP_BINARY, '-r',
                'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; echo json_encode([$app->getCachedConfigPath(), $app->getCachedRoutesPath()]);',
            ], base_path(), ['NATIVEPHP_RUNNING' => 'true', 'NATIVEPHP_USER_DATA_PATH' => $directory, 'NATIVEPHP_SECRET' => $secret]);
            $process->mustRun();
            $paths[] = json_decode($process->getOutput(), true);
        }
        $this->assertStringStartsWith($directory, $paths[0][0]);
        $this->assertNotSame($paths[0][0], $paths[1][0]);
        $this->assertSame($paths[0][1], $paths[1][1]);
        rmdir($directory.'/bootstrap/cache');
        rmdir($directory.'/bootstrap');
        rmdir($directory);
    }
}
