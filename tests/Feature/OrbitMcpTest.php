<?php

namespace Tests\Feature;

use App\Actions\ProtectCredential;
use App\Mcp\Servers\OrbitServer;
use App\Mcp\Tools\AddSecretTool;
use App\Mcp\Tools\ManageAssetTool;
use App\Mcp\Tools\ManageBoardTool;
use App\Mcp\Tools\ManageDocumentTool;
use App\Mcp\Tools\ManageProjectTool;
use App\Mcp\Tools\ReadAssetTool;
use App\Mcp\Tools\ReadProjectTool;
use App\Mcp\Tools\ReadWorkspaceTool;
use App\Models\Project;
use App\Models\ProjectSecret;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Mcp\Request;
use Native\Desktop\Facades\System;
use Tests\TestCase;

class OrbitMcpTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_mcp_tools_expose_project_content_without_secret_values(): void
    {
        $project = Project::factory()->create(['name' => 'Orbit', 'status' => 'Live']);
        $project->links()->create(['label' => 'App Store Connect', 'url' => 'https://appstoreconnect.apple.com', 'description' => 'Sign in with the shared team account']);
        $project->documents()->create(['title' => 'Setup', 'body' => 'Run the app', 'position' => 0]);
        ProjectSecret::factory()->for($project)->create(['name' => 'API_KEY', 'environment' => 'Production', 'service' => 'Apple', 'ciphertext' => 'never-share-this']);

        $workspace = json_decode((string) app(ReadWorkspaceTool::class)->handle(new Request)->content(), true);
        $details = json_decode((string) app(ReadProjectTool::class)->handle(new Request(['project_id' => $project->id]))->content(), true);

        $this->assertSame('Orbit', $workspace[0]['name']);
        $this->assertSame('Live', $workspace[0]['status']);
        $this->assertSame(1, $workspace[0]['revision']);
        $this->assertSame($project->id, $details['id']);
        $this->assertSame('App Store Connect', $details['links'][0]['label']);
        $this->assertSame($project->links()->sole()->id, $details['links'][0]['id']);
        $this->assertSame('Run the app', $details['documents'][0]['body']);
        $this->assertSame('API_KEY', $details['secrets'][0]['name']);
        $this->assertSame(1, $details['secrets'][0]['revision']);
        $this->assertStringNotContainsString('never-share-this', json_encode($details));
    }

    public function test_connection_settings_point_the_mcp_server_at_the_active_workspace(): void
    {
        config(['nativephp-internal.running' => true]);
        $this->get('/settings/connections')->assertInertia(fn (Assert $page): Assert => $page
            ->where('mcp.command', PHP_BINARY)
            ->where('mcp.args.1', 'mcp:start')
            ->where('mcp.env.DB_DATABASE', DB::connection()->getDatabaseName())
            ->where('mcp.env.LARAVEL_STORAGE_PATH', storage_path()));
    }

    public function test_mcp_can_create_update_and_delete_projects_without_losing_omitted_content(): void
    {
        Storage::fake('local');
        OrbitServer::tools()->assertRegistered([ManageProjectTool::class, ManageDocumentTool::class, ManageBoardTool::class, ManageAssetTool::class, ReadAssetTool::class, AddSecretTool::class]);

        OrbitServer::tool(ManageProjectTool::class, [
            'action' => 'create', 'name' => 'Orbit', 'status' => 'Idea', 'tags' => ['personal'],
            'links' => [['label' => 'Website', 'url' => 'https://example.com']],
        ])->assertOk();
        $project = Project::sole();
        $this->assertSame(['personal'], $project->tags()->pluck('name')->all());
        $this->assertSame('Website', $project->links()->sole()->label);

        OrbitServer::tool(ManageProjectTool::class, ['action' => 'update', 'project_id' => $project->id, 'revision' => 1, 'description' => 'Current work'])->assertOk();
        $this->assertSame('Current work', $project->fresh()->description);
        $this->assertSame('Website', $project->links()->sole()->label);
        OrbitServer::tool(ManageProjectTool::class, ['action' => 'update', 'project_id' => $project->id, 'revision' => 1, 'name' => 'Stale'])->assertHasErrors();
        $this->assertSame('Orbit', $project->fresh()->name);

        $second = Project::factory()->create();
        OrbitServer::tool(ManageProjectTool::class, ['action' => 'reorder', 'ids' => [$second->id, $project->id]])->assertOk();
        $this->assertSame([$second->id, $project->id], Project::orderBy('position')->pluck('id')->all());

        OrbitServer::tool(ManageProjectTool::class, ['action' => 'delete', 'project_id' => $project->id, 'revision' => 2])->assertOk();
        $this->assertModelMissing($project);
    }

    public function test_mcp_can_manage_documents_and_board_tasks_with_revision_checks(): void
    {
        $project = Project::factory()->create();
        $column = $project->boardColumns->first();

        OrbitServer::tool(ManageDocumentTool::class, ['project_id' => $project->id, 'action' => 'save', 'revision' => 1, 'title' => 'Setup', 'body' => 'Run tests'])->assertOk();
        $document = $project->documents()->sole();
        $this->assertSame('Run tests', $document->body);
        OrbitServer::tool(ManageBoardTool::class, ['project_id' => $project->id, 'action' => 'task.save', 'revision' => 2, 'column_id' => $column->id, 'title' => 'Ship MCP'])->assertOk();
        $task = Task::sole();
        $this->assertSame('Ship MCP', $task->title);

        OrbitServer::tool(ManageBoardTool::class, ['project_id' => $project->id, 'action' => 'task.delete', 'revision' => 2, 'id' => $task->id])->assertHasErrors();
        $this->assertModelExists($task);
        OrbitServer::tool(ManageDocumentTool::class, ['project_id' => $project->id, 'action' => 'delete', 'revision' => 3, 'id' => $document->id, 'document_revision' => 1])->assertOk();
        $this->assertModelMissing($document);
        $this->assertSame(4, $project->fresh()->revision);
    }

    public function test_mcp_can_upload_read_move_and_delete_project_assets(): void
    {
        Storage::fake('local');
        $project = Project::factory()->create();
        $source = tempnam(sys_get_temp_dir(), 'orbit-mcp-');
        file_put_contents($source, 'Orbit asset content');
        try {
            OrbitServer::tool(ManageAssetTool::class, ['project_id' => $project->id, 'action' => 'file.upload', 'revision' => 1, 'file_path' => $source])->assertOk();
            $asset = $project->fresh()->asset_files[0];
            Storage::disk('local')->assertExists($asset['path']);
            $chunk = json_decode((string) app(ReadAssetTool::class)->handle(new Request(['project_id' => $project->id, 'asset_id' => $asset['id']]))->content(), true);
            $this->assertSame('Orbit asset content', $chunk['content']);
            $this->assertNull($chunk['next_offset']);
            $partial = json_decode((string) app(ReadAssetTool::class)->handle(new Request(['project_id' => $project->id, 'asset_id' => $asset['id'], 'offset' => 6, 'length' => 5]))->content(), true);
            $this->assertSame('asset', $partial['content']);
            $this->assertSame(11, $partial['next_offset']);
            $other = Project::factory()->create();
            OrbitServer::tool(ReadAssetTool::class, ['project_id' => $other->id, 'asset_id' => $asset['id']])->assertHasErrors(['Asset not found.']);
            Storage::disk('local')->put($asset['path'], "\0\xff");
            $binary = json_decode((string) app(ReadAssetTool::class)->handle(new Request(['project_id' => $project->id, 'asset_id' => $asset['id']]))->content(), true);
            $this->assertSame('base64', $binary['encoding']);
            $this->assertSame(base64_encode("\0\xff"), $binary['content']);

            OrbitServer::tool(ManageAssetTool::class, ['project_id' => $project->id, 'action' => 'folder.save', 'revision' => 2, 'name' => 'Design'])->assertOk();
            $folderId = $project->fresh()->asset_folders[0]['id'];
            OrbitServer::tool(ManageAssetTool::class, ['project_id' => $project->id, 'action' => 'file.move', 'revision' => 3, 'id' => $asset['id'], 'folder_id' => $folderId])->assertOk();
            $this->assertSame($folderId, $project->fresh()->asset_files[0]['folder_id']);
            OrbitServer::tool(ManageAssetTool::class, ['project_id' => $project->id, 'action' => 'file.delete', 'revision' => 4, 'id' => $asset['id']])->assertOk();
            Storage::disk('local')->assertMissing($asset['path']);
        } finally {
            unlink($source);
        }
    }

    public function test_mcp_can_attach_local_files_to_tasks_and_reject_missing_sources(): void
    {
        Storage::fake('local');
        $project = Project::factory()->create();
        $column = $project->boardColumns->first();
        $source = tempnam(sys_get_temp_dir(), 'orbit-task-');
        file_put_contents($source, 'Task attachment');
        try {
            OrbitServer::tool(ManageBoardTool::class, [
                'project_id' => $project->id, 'action' => 'task.save', 'revision' => 1,
                'column_id' => $column->id, 'title' => 'Review', 'attachment_paths' => [$source],
            ])->assertOk();
            $task = Task::sole();
            $this->assertCount(1, $task->attachment_files);
            Storage::disk('local')->assertExists($task->attachment_files[0]['path']);
            OrbitServer::tool(ManageBoardTool::class, [
                'project_id' => $project->id, 'action' => 'task.save', 'revision' => 2,
                'column_id' => $column->id, 'title' => 'Missing', 'attachment_paths' => ['/missing/orbit-task.txt'],
            ])->assertHasErrors();
            $this->assertDatabaseCount('tasks', 1);
            $this->assertSame(2, $project->fresh()->revision);
        } finally {
            unlink($source);
        }
    }

    public function test_mcp_adds_encrypted_secrets_without_exposing_values(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $project = Project::factory()->create();
        $input = ['project_id' => $project->id, 'project_revision' => 1, 'environment' => 'Production', 'name' => 'API_KEY', 'value' => 'secret-from-mcp'];

        OrbitServer::tool(AddSecretTool::class, $input)->assertOk()->assertDontSee('secret-from-mcp');
        $secret = ProjectSecret::sole();
        $this->assertNotSame('secret-from-mcp', $secret->ciphertext);
        $this->assertStringStartsWith('orbit-laravel-v1:', $secret->ciphertext);
        OrbitServer::tool(ReadProjectTool::class, ['project_id' => $project->id])->assertOk()->assertDontSee(['secret-from-mcp', $secret->ciphertext]);
        OrbitServer::tool(AddSecretTool::class, [...$input, 'project_revision' => 2])->assertHasErrors();
        OrbitServer::tool(AddSecretTool::class, [...$input, 'name' => 'OTHER_KEY'])->assertHasErrors();
        $this->assertDatabaseCount('project_secrets', 1);
        $this->assertDatabaseHas('credential_access_events', ['secret_id' => $secret->id, 'operation' => 'create', 'result' => 'Succeeded']);
        $this->assertSame(2, $project->fresh()->revision);

        try {
            app(ProtectCredential::class)->decrypt($secret->ciphertext);
            $this->fail('The MCP process must not decrypt vault secrets.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Native credential storage is unavailable. Open the desktop app and try again.', $exception->getMessage());
        }

        config(['nativephp-internal.running' => true]);
        System::shouldReceive('canEncrypt')->once()->andReturn(true);
        $this->assertSame('secret-from-mcp', app(ProtectCredential::class)->decrypt($secret->ciphertext));
    }
}
