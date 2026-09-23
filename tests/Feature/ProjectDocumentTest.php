<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProjectDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_documents_can_be_created_edited_reordered_and_deleted_without_changing_review_dates(): void
    {
        $project = Project::factory()->create(['reviewed_at' => '2026-08-01']);
        $body = "  ## Deploy\n\n```sh\nprintf 'first\\nsecond'\n  ./deploy --safe\n```\n\n";
        $url = '/projects/'.$project->id.'/documents';
        $this->putJson($url, ['action' => 'save', 'revision' => 1, 'title' => 'Deployment', 'body' => $body])->assertRedirect();
        $first = $project->documents()->sole();
        $this->assertSame($body, $first->body);
        $this->putJson($url, ['action' => 'save', 'revision' => 2, 'title' => 'Database', 'body' => 'Redis'])->assertRedirect();
        $second = $project->documents()->whereKeyNot($first->id)->sole();
        $this->putJson($url, ['action' => 'save', 'revision' => 3, 'id' => $first->id, 'document_revision' => 1, 'title' => 'Hosting', 'body' => $body])->assertRedirect();
        $this->assertSame('Hosting', $first->fresh()->title);
        $this->assertSame(2, $first->fresh()->revision);
        $this->putJson($url, ['action' => 'move', 'revision' => 4, 'id' => $second->id, 'document_revision' => 1, 'position' => 0])->assertRedirect();
        $this->assertSame([$second->id, $first->id], $project->documents()->pluck('id')->all());
        $this->putJson($url, ['action' => 'delete', 'revision' => 5, 'id' => $second->id, 'document_revision' => 1])->assertRedirect();
        $this->assertModelMissing($second);
        $this->assertSame(0, $first->fresh()->position);
        $this->assertSame('2026-08-01', $project->fresh()->reviewed_at->toDateString());
        $this->deleteJson('/projects/'.$project->id, ['revision' => 6])->assertRedirect('/');
        $this->assertModelMissing($first);
    }

    public function test_document_conflicts_preserve_drafts_and_cross_project_records_and_bad_positions_are_rejected_atomically(): void
    {
        $project = Project::factory()->create();
        $document = ProjectDocument::factory()->for($project)->create(['revision' => 2]);
        $foreign = ProjectDocument::factory()->create();
        $url = '/projects/'.$project->id.'/documents';
        $payload = ['action' => 'save', 'revision' => 1, 'id' => $document->id, 'document_revision' => 1, 'title' => 'Unsaved draft', 'body' => 'Keep this'];
        $this->putJson($url, $payload)->assertConflict();
        $this->from('/projects/'.$project->id)->withHeader('X-Inertia', 'true')->put($url, $payload)
            ->assertSessionHasErrors('revision')->assertSessionHasInput('body', 'Keep this');
        $this->flushHeaders();
        $this->putJson($url, [...$payload, 'revision' => 99, 'document_revision' => 2])->assertConflict();
        $this->putJson($url, [...$payload, 'id' => $foreign->id])->assertNotFound();
        $this->putJson($url, [...$payload, 'action' => 'move', 'document_revision' => 2, 'position' => 2])->assertUnprocessable()->assertJsonValidationErrors('position');
        $this->putJson($url, [...$payload, 'title' => '  '])->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->putJson($url, [...$payload, 'body' => str_repeat('x', 50001)])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->assertSame(1, $project->fresh()->revision);
        $this->assertSame($document->body, $document->fresh()->body);
        $this->assertSame($foreign->body, $foreign->fresh()->body);
    }

    public function test_document_html_is_safe_and_preview_does_not_write(): void
    {
        $document = ProjectDocument::factory()->create(['body' => "## Deploy\n<script>alert(1)</script>\n[unsafe](javascript:alert(1))\n\n```sh\n<safe>\n```"]);
        $this->get('/projects/'.$document->project_id)->assertInertia(fn (Assert $page): Assert => $page
            ->where('selectedProject.documents.0.body_html', fn (string $html): bool => str_contains($html, '<h2>Deploy</h2>') && str_contains($html, '&lt;safe&gt;') && ! str_contains($html, '<script>') && ! str_contains($html, 'href="javascript:')));
        $this->postJson('/projects/'.$document->project_id.'/documents/preview', ['body' => '**Preview**'])->assertJson(['html' => "<p><strong>Preview</strong></p>\n"]);
        $this->assertSame(1, $document->project->revision);
        $this->assertDatabaseCount('project_documents', 1);
    }

    public function test_removed_review_action_returns_404_without_changing_the_project(): void
    {
        $project = Project::factory()->create(['reviewed_at' => '2026-08-01']);

        $this->putJson('/projects/'.$project->id.'/review', ['revision' => 1])->assertNotFound();

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'reviewed_at' => '2026-08-01', 'revision' => 1]);
    }

    public function test_upgrade_preserves_every_byte_of_legacy_notes_and_leaves_other_project_data_unchanged(): void
    {
        $migration = require database_path('migrations/2026_09_22_081720_create_project_documents_table.php');
        $project = Project::factory()->create();
        $migration->down();
        $notes = "  ## Existing notes\r\n\r\n```sh\r\nprintf 'hello'\r\n```\r\n  ";
        DB::table('projects')->where('id', $project->id)->update(['notes' => $notes]);
        $empty = Project::factory()->create(['notes' => '']);
        try {
            $migration->up();
            $this->assertSame($notes, $project->documents()->sole()->body);
            $this->assertSame('Notes', $project->documents()->sole()->title);
            $this->assertNull($project->fresh()->notes);
            $this->assertSame(0, $empty->documents()->count());
            $this->assertSame($project->name, $project->fresh()->name);
            $this->assertSame(1, $project->fresh()->revision);
            $this->assertCount(4, $project->boardColumns);
        } finally {
            if (! Schema::hasTable('project_documents')) {
                $migration->up();
            }
        }
    }
}
