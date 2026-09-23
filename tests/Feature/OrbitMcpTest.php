<?php

namespace Tests\Feature;

use App\Mcp\Tools\ReadProjectTool;
use App\Mcp\Tools\ReadWorkspaceTool;
use App\Models\Project;
use App\Models\ProjectSecret;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Mcp\Request;
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
        $this->assertSame('App Store Connect', $details['links'][0]['label']);
        $this->assertSame('Run the app', $details['documents'][0]['body']);
        $this->assertSame('API_KEY', $details['secrets'][0]['name']);
        $this->assertStringNotContainsString('never-share-this', json_encode($details));
    }

    public function test_connection_settings_point_the_mcp_server_at_the_active_workspace(): void
    {
        config(['nativephp-internal.running' => true]);
        $this->get('/settings/connections')->assertInertia(fn (Assert $page): Assert => $page
            ->where('mcp.command', PHP_BINARY)
            ->where('mcp.args.1', 'mcp:start')
            ->where('mcp.env.DB_DATABASE', DB::connection()->getDatabaseName()));
    }
}
