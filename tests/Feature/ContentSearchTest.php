<?php

namespace Tests\Feature;

use App\Actions\ProtectCredential;
use App\Models\BoardColumn;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\ProjectLink;
use App\Models\ProjectSecret;
use App\Models\Tag;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContentSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_finds_each_content_type_and_builds_specific_project_destinations(): void
    {
        $project = Project::factory()->create(['name' => 'Operations']);
        $project->tags()->attach(Tag::factory()->create(['name' => 'renderer']));
        $document = ProjectDocument::factory()->for($project)->create(['title' => 'Deployment', 'body' => str_repeat('Setup ', 50).'Renderer deploy command']);
        $column = BoardColumn::factory()->for($project)->create();
        $task = Task::factory()->for($column, 'column')->create(['title' => 'Improve capture', 'description' => 'Renderer queues']);
        $link = ProjectLink::factory()->for($project)->create(['label' => 'Dashboard', 'category' => 'Renderer', 'url' => 'https://example.com/render']);
        $secret = ProjectSecret::factory()->for($project)->create(['name' => 'BROWSER_KEY', 'service' => 'Renderer']);

        $response = $this->getJson('/search?q=renderer')->assertOk()->assertJsonCount(5, 'results')->assertJsonPath('has_more', false);

        $results = collect($response->json('results'))->keyBy('type');
        $this->assertSame('Operations', $results['project']['title']);
        foreach (['document' => [$document, 'documents'], 'task' => [$task, 'board'], 'link' => [$link, 'overview'], 'secret' => [$secret, 'secrets']] as $type => [$record, $tab]) {
            $this->assertSame($record->id, $results[$type]['id']);
            $this->assertSame('Operations', $results[$type]['project']);
            $this->assertSame('/projects/'.$project->id.'?tab='.$tab.'&'.$type.'='.$record->id, $results[$type]['url']);
        }
        $this->assertStringContainsString('Renderer deploy', $results['document']['excerpt']);
        $this->assertLessThanOrEqual(182, mb_strlen($results['document']['excerpt']));
    }

    public function test_search_scopes_project_content_and_reflects_updates_and_removal(): void
    {
        $project = Project::factory()->create();
        $other = Project::factory()->create();
        $document = ProjectDocument::factory()->for($project)->create(['title' => 'Redis setup', 'body' => 'Original instructions']);
        ProjectDocument::factory()->for($other)->create(['title' => 'Redis setup']);
        $url = '/search?project_id='.$project->id.'&q=redis';

        $this->getJson($url)->assertJsonCount(1, 'results')->assertJsonPath('results.0.id', $document->id);
        $document->update(['title' => 'Database setup']);
        $this->getJson($url)->assertJsonCount(0, 'results');
        $this->getJson('/search?project_id='.$project->id.'&q=database')->assertJsonCount(1, 'results');
        $document->delete();
        $this->getJson('/search?project_id='.$project->id.'&q=database')->assertJsonCount(0, 'results');
    }

    public function test_search_never_selects_or_decrypts_secret_values_and_uses_literal_bounded_queries(): void
    {
        $project = Project::factory()->create(['name' => 'Workspace']);
        ProjectSecret::factory()->for($project)->create(['name' => 'STRIPE_KEY', 'description' => 'Payments', 'ciphertext' => 'private-secret-needle']);
        $this->mock(ProtectCredential::class, function ($mock): void {
            $mock->shouldNotReceive('decrypt');
        });
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->getJson('/search?q=private-secret-needle')->assertExactJson(['results' => [], 'has_more' => false]);
        $this->getJson('/search?q=STRIPE')->assertJsonCount(1, 'results')->assertJsonMissingPath('results.0.ciphertext')
            ->assertJsonMissingPath('results.0.value')->assertDontSee('private-secret-needle');
        $this->assertStringNotContainsString('ciphertext', implode(' ', $queries));
        ProjectDocument::factory()->for($project)->count(12)->create(['title' => 'Deploy notes']);
        $this->getJson('/search?q=deploy')->assertJsonCount(10, 'results')->assertJsonPath('has_more', true);
        $this->getJson('/search?q=%25')->assertExactJson(['results' => [], 'has_more' => false]);
        $this->getJson('/search?q=')->assertExactJson(['results' => [], 'has_more' => false]);
        $this->getJson('/search?q='.str_repeat('a', 256))->assertUnprocessable()->assertJsonValidationErrors('q');
    }
}
