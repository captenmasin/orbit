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
use PHPUnit\Framework\Attributes\TestWith;
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

        $this->getJson('/search?q=private-secret-needle')->assertExactJson(['results' => [], 'has_more' => false, 'term' => 'private-secret-needle', 'filters' => ['type' => null, 'project' => null]]);
        $this->getJson('/search?q=STRIPE')->assertJsonCount(1, 'results')->assertJsonMissingPath('results.0.ciphertext')
            ->assertJsonMissingPath('results.0.value')->assertDontSee('private-secret-needle');
        $this->assertStringNotContainsString('ciphertext', implode(' ', $queries));
        ProjectDocument::factory()->for($project)->count(12)->create(['title' => 'Deploy notes']);
        $this->getJson('/search?q=deploy')->assertJsonCount(10, 'results')->assertJsonPath('has_more', true);
        $this->getJson('/search?q=%25')->assertExactJson(['results' => [], 'has_more' => false, 'term' => '%', 'filters' => ['type' => null, 'project' => null]]);
        $this->getJson('/search?q=')->assertExactJson(['results' => [], 'has_more' => false, 'term' => '', 'filters' => ['type' => null, 'project' => null]]);
        $this->getJson('/search?q='.str_repeat('a', 256))->assertUnprocessable()->assertJsonValidationErrors('q');
    }

    public function test_search_ranks_title_matches_across_types_and_boosts_preferred_project_within_match_quality(): void
    {
        $project = Project::factory()->create(['name' => 'Aardvark', 'description' => 'Deploy production']);
        $preferred = Project::factory()->create(['name' => 'Zebra', 'description' => 'Current project']);
        $substring = ProjectDocument::factory()->for($project)->create(['title' => 'Post deploy checklist', 'body' => 'Instructions']);
        $prefix = Task::factory()->for(BoardColumn::factory()->for($project)->create(), 'column')->create(['title' => 'Deploy workflow', 'description' => 'Instructions']);
        $exact = ProjectLink::factory()->for($project)->create(['label' => 'DEPLOY', 'url' => 'https://example.com']);
        $preferredPrefix = ProjectDocument::factory()->for($preferred)->create(['title' => 'Deploy workflow', 'body' => 'Instructions']);
        $body = ProjectSecret::factory()->for($project)->create(['name' => 'Production key', 'description' => 'Deploy credentials']);

        $response = $this->getJson('/search?'.http_build_query(['q' => 'deploy', 'preferred_project_id' => $preferred->id]))
            ->assertOk()->assertJsonCount(6, 'results')->assertJsonPath('term', 'deploy');

        $this->assertSame([$exact->id, $preferredPrefix->id, $prefix->id, $substring->id, $project->id, $body->id], array_column($response->json('results'), 'id'));
    }

    public function test_search_ranks_matches_before_the_per_type_limit(): void
    {
        $project = Project::factory()->create(['name' => 'Aardvark', 'description' => 'Workspace']);
        ProjectDocument::factory()->for($project)->count(12)->create(['title' => 'Instructions', 'body' => 'Deploy commands']);
        $lastProject = Project::factory()->create(['name' => 'Zebra', 'description' => 'Workspace']);
        $exact = ProjectDocument::factory()->for($lastProject)->create(['title' => 'Deploy', 'body' => 'Instructions']);

        $this->getJson('/search?q=deploy')->assertJsonCount(10, 'results')->assertJsonPath('results.0.id', $exact->id)->assertJsonPath('has_more', true);
    }

    #[TestWith(['deploy TYPE:TaSKs project:"mY pRoJeCt"'])]
    #[TestWith(["project:'mY pRoJeCt' deploy type:task"])]
    public function test_search_combines_case_insensitive_quoted_filters_and_respects_project_scope(string $query): void
    {
        $project = Project::factory()->create(['name' => 'My Project Alpha', 'description' => 'Workspace']);
        $other = Project::factory()->create(['name' => 'Other', 'description' => 'Workspace']);
        $task = Task::factory()->for(BoardColumn::factory()->for($project)->create(), 'column')->create(['title' => 'Deploy', 'description' => 'Instructions']);
        Task::factory()->for(BoardColumn::factory()->for($other)->create(), 'column')->create(['title' => 'Deploy', 'description' => 'Instructions']);
        ProjectDocument::factory()->for($project)->create(['title' => 'Deploy', 'body' => 'Instructions']);

        $this->getJson('/search?'.http_build_query(['q' => $query]))->assertJsonCount(1, 'results')
            ->assertJsonPath('results.0.id', $task->id)->assertJsonPath('term', 'deploy')
            ->assertJsonPath('filters', ['type' => 'task', 'project' => 'mY pRoJeCt']);
        $this->getJson('/search?'.http_build_query(['q' => $query, 'project_id' => $project->id]))->assertJsonCount(1, 'results');
        $this->getJson('/search?'.http_build_query(['q' => $query, 'project_id' => $other->id]))->assertJsonCount(0, 'results');
    }

    #[TestWith(['document'])]
    #[TestWith(['documents'])]
    #[TestWith(['doc'])]
    #[TestWith(['docs'])]
    public function test_search_supports_filter_only_queries_and_document_aliases(string $type): void
    {
        $project = Project::factory()->create(['name' => 'Orbit']);
        $document = ProjectDocument::factory()->for($project)->create(['title' => 'Instructions']);
        Task::factory()->for(BoardColumn::factory()->for($project)->create(), 'column')->create();
        ProjectDocument::factory()->create();

        $this->getJson('/search?'.http_build_query(['q' => 'type:'.$type.' project:orbit']))->assertJsonCount(1, 'results')
            ->assertJsonPath('results.0.id', $document->id)->assertJsonPath('results.0.excerpt', '')
            ->assertJsonPath('term', '')->assertJsonPath('filters', ['type' => 'document', 'project' => 'orbit']);
        $this->getJson('/search?q=project:orbit')->assertJsonCount(3, 'results');
    }

    #[TestWith(['%'])]
    #[TestWith(['_'])]
    #[TestWith(['0'])]
    #[TestWith(["' OR 1=1 --"])]
    public function test_search_treats_project_filter_characters_literally(string $name): void
    {
        $project = Project::factory()->create(['name' => $name, 'description' => 'Workspace']);
        $document = ProjectDocument::factory()->for($project)->create(['title' => 'Instructions']);
        ProjectDocument::factory()->for(Project::factory()->create(['name' => 'Other']))->create(['title' => 'Instructions']);

        $this->getJson('/search?'.http_build_query(['q' => 'type:document project:"'.$name.'"']))
            ->assertJsonCount(1, 'results')->assertJsonPath('results.0.id', $document->id);
    }

    #[TestWith(['type:unknown', 'Choose type:project, type:document, type:task, type:link, or type:secret.'])]
    #[TestWith(['type:', 'Use one non-empty type filter.'])]
    #[TestWith(['project:', 'Use one non-empty project filter.'])]
    #[TestWith(['project:""', 'Use one non-empty project filter.'])]
    #[TestWith(['project:"Orbit', 'Close the quotes around the project filter.'])]
    #[TestWith(['type:task type:document', 'Use one non-empty type filter.'])]
    #[TestWith(['project:Orbit project:Other', 'Use one non-empty project filter.'])]
    public function test_search_returns_422_for_invalid_filters_instead_of_broadening_the_search(string $query, string $message): void
    {
        $this->getJson('/search?'.http_build_query(['q' => $query]))->assertUnprocessable()
            ->assertJsonValidationErrors('q')->assertJsonPath('errors.q.0', $message);
    }

    #[TestWith(['not-a-project-id'])]
    #[TestWith(['00000000-0000-4000-8000-000000000000'])]
    public function test_search_returns_422_for_invalid_preferred_projects(string $projectId): void
    {
        $this->getJson('/search?'.http_build_query(['q' => 'deploy', 'preferred_project_id' => $projectId]))
            ->assertUnprocessable()->assertJsonValidationErrors('preferred_project_id');
    }
}
