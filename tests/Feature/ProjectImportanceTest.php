<?php

namespace Tests\Feature;

use App\Actions\ProtectCredential;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\ProjectLink;
use App\WorkspaceBackup;
use App\WorkspaceRestore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ProjectImportanceTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['links', ProjectLink::class, null])]
    #[TestWith(['documents', ProjectDocument::class, 3])]
    public function test_items_can_be_marked_and_unmarked_without_changing_their_content(string $kind, string $model, ?int $revision): void
    {
        $project = Project::factory()->create();
        $item = $model::factory()->for($project)->create()->refresh();
        $content = Arr::except($item->getAttributes(), ['important', 'revision', 'updated_at']);
        $url = '/projects/'.$project->id;
        $payload = ['kind' => $kind, 'id' => $item->id, 'important' => true, 'revision' => 1];
        $this->assertFalse($item->important);

        $this->from($url)->putJson($url.'/important', $payload)->assertRedirect($url);

        $this->assertTrue($item->fresh()->important);
        $this->assertSame(2, $project->fresh()->revision);
        $this->get($url)->assertInertia(fn (Assert $page): Assert => $page->where('selectedProject.'.$kind.'.0.important', true));

        $this->from($url)->putJson($url.'/important', [...$payload, 'important' => false, 'revision' => 2])->assertRedirect($url);

        $this->assertFalse($item->fresh()->important);
        $this->assertSame(3, $project->fresh()->revision);
        $this->assertSame($revision, $item->fresh()->getAttribute('revision'));
        $this->assertSame($content, Arr::except($item->fresh()->getAttributes(), ['important', 'revision', 'updated_at']));
    }

    #[TestWith(['links', ProjectLink::class])]
    #[TestWith(['documents', ProjectDocument::class])]
    public function test_foreign_records_invalid_values_and_stale_changes_leave_importance_unchanged(string $kind, string $model): void
    {
        $project = Project::factory()->create();
        $item = $model::factory()->for($project)->create();
        $foreign = $model::factory()->create();
        $url = '/projects/'.$project->id.'/important';
        $payload = ['kind' => $kind, 'id' => $item->id, 'important' => true, 'revision' => 1];

        $this->putJson($url, [...$payload, 'id' => $foreign->id])->assertNotFound();
        $this->putJson($url, [...$payload, 'kind' => 'secrets'])->assertUnprocessable()->assertJsonValidationErrors('kind');
        $this->putJson($url, [...$payload, 'important' => 'yes'])->assertUnprocessable()->assertJsonValidationErrors('important');
        $this->putJson($url, [...$payload, 'revision' => 2])->assertConflict();
        $this->from('/projects/'.$project->id)->withHeader('X-Inertia', 'true')
            ->put($url, [...$payload, 'revision' => 2])->assertSessionHasErrors('revision');

        $this->assertFalse($item->fresh()->important);
        $this->assertFalse($foreign->fresh()->important);
        $this->assertSame(1, $project->fresh()->revision);
    }

    public function test_editing_links_and_documents_preserves_their_stars(): void
    {
        $project = Project::factory()->create();
        $link = ProjectLink::factory()->for($project)->create(['important' => true]);
        $document = ProjectDocument::factory()->for($project)->create(['important' => true]);

        $this->putJson('/projects/'.$project->id, [
            'name' => $project->name, 'status' => $project->status, 'revision' => 1,
            'links' => [['id' => $link->id, 'label' => 'Updated link', 'url' => $link->url]],
        ])->assertRedirect();
        $this->putJson('/projects/'.$project->id.'/documents', [
            'action' => 'save', 'revision' => 2, 'id' => $document->id, 'document_revision' => 1,
            'title' => 'Updated document', 'body' => $document->body,
        ])->assertRedirect();

        $this->assertTrue($link->fresh()->important);
        $this->assertSame('Updated link', $link->fresh()->label);
        $this->assertTrue($document->fresh()->important);
        $this->assertSame('Updated document', $document->fresh()->title);
    }

    public function test_project_duplication_preserves_important_items(): void
    {
        $project = Project::factory()->create();
        ProjectLink::factory()->for($project)->create(['important' => true]);
        ProjectDocument::factory()->for($project)->create(['important' => true]);

        $this->post('/projects/'.$project->id.'/duplicate')->assertRedirect();

        $copy = Project::whereKeyNot($project->id)->sole();
        $this->assertTrue($copy->links()->sole()->important);
        $this->assertTrue($copy->documents()->sole()->important);
    }

    public function test_backup_restore_preserves_stars_and_accepts_older_backups_without_them(): void
    {
        $project = Project::factory()->create();
        $link = ProjectLink::factory()->for($project)->create(['important' => true]);
        $document = ProjectDocument::factory()->for($project)->create(['important' => true]);
        $crypto = app(ProtectCredential::class);
        $records = app(WorkspaceBackup::class)->records(false, $crypto);
        $restore = app(WorkspaceRestore::class);

        $restore->apply($restore->stage($records, $crypto));

        $this->assertTrue($link->fresh()->important);
        $this->assertTrue($document->fresh()->important);

        foreach ($records as &$record) {
            if (in_array($record['type'], ['project_links', 'project_documents'], true)) {
                unset($record['data']['important']);
            }
        }
        unset($record);
        $restore->apply($restore->stage($records, $crypto));

        $this->assertFalse($link->fresh()->important);
        $this->assertFalse($document->fresh()->important);
    }

    #[TestWith(['project_links'])]
    #[TestWith(['project_documents'])]
    public function test_backups_reject_invalid_importance_values(string $type): void
    {
        ProjectLink::factory()->create();
        ProjectDocument::factory()->create();
        $crypto = app(ProtectCredential::class);
        $records = app(WorkspaceBackup::class)->records(false, $crypto);
        foreach ($records as &$record) {
            if ($record['type'] === $type) {
                $record['data']['important'] = 'yes';
            }
        }
        unset($record);
        $this->expectException(InvalidArgumentException::class);

        app(WorkspaceRestore::class)->stage($records, $crypto);
    }
}
