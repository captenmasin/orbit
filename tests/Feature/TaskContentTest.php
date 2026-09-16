<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TaskContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_markdown_is_preserved_and_preview_matches_safe_rendered_task_content(): void
    {
        $project = Project::factory()->create();
        $description = "    indented code\n\n## Heading\n\n**Bold** and _italic_ with `code`\n\n- [x] Done\n- Pending\n\n| A | B |\n| - | - |\n| 1 | 2 |\n\n[Docs](https://example.com)\n\n<script>alert('xss')</script>\n\n<img src=x onerror=alert(1)>\n\n[Bad](javascript:alert(1)) [Local](file:///etc/passwd) ![Bad image](javascript:alert(1))";

        $preview = $this->postJson('/projects/'.$project->id.'/board/preview', ['description' => $description])->assertOk()->json('html');
        $this->post('/projects/'.$project->id.'/board', [
            '_method' => 'put', 'action' => 'task.save', 'revision' => 1,
            'column_id' => $project->boardColumns->first()->id, 'title' => 'Markdown task', 'description' => $description,
        ])->assertRedirect();

        $this->assertSame($description, Task::sole()->description);
        foreach (['<pre><code>indented code', '<h2>Heading</h2>', '<strong>Bold</strong>', '<em>italic</em>', '<code>code</code>', 'type="checkbox"', '<table>', 'href="https://example.com"', 'rel="noopener noreferrer"'] as $html) {
            $this->assertStringContainsString($html, $preview);
        }
        foreach (['<script', '<img src=x', 'onerror', 'javascript:', 'file:///'] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, $preview);
        }
        $this->get('/projects/'.$project->id)->assertInertia(fn (Assert $page): Assert => $page
            ->where('selectedProject.board_columns.0.tasks.0.description_html', $preview)
            ->where('selectedProject.board_columns.0.tasks.0.attachments', [])
            ->missing('selectedProject.board_columns.0.tasks.0.attachment_files'));
        $this->postJson('/projects/'.$project->id.'/board/preview', ['description' => str_repeat('a', 10001)])
            ->assertUnprocessable()->assertJsonValidationErrors('description');
        $this->assertSame(2, $project->fresh()->revision);
    }

    public function test_attachments_can_be_uploaded_downloaded_and_removed_with_the_task(): void
    {
        Storage::fake('local');
        $project = Project::factory()->create();
        $url = '/projects/'.$project->id.'/board';
        $payload = ['_method' => 'put', 'action' => 'task.save', 'revision' => 1, 'column_id' => $project->boardColumns->first()->id, 'title' => 'Files'];

        $this->post($url, [...$payload, 'attachments' => [
            UploadedFile::fake()->createWithContent('notes.md', '# Notes'),
            UploadedFile::fake()->createWithContent('notes.md', 'Different file'),
            UploadedFile::fake()->createWithContent('page.html', '<script>alert(1)</script>'),
        ], 'attachment_files' => [['path' => '/etc/passwd']]])->assertRedirect();

        $task = Task::sole();
        [$first, $second, $html] = $task->attachment_files;
        $this->assertNotSame($first['path'], $second['path']);
        $this->assertSame('# Notes', Storage::disk('local')->get($first['path']));
        $this->get(route('projects.tasks.attachments.download', [$project, $task, $first['id']]))->assertDownload('notes.md')
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cache-Control', 'no-store, private');
        $this->get(route('projects.tasks.attachments.download', [$project, $task, $html['id']]))->assertDownload('page.html')
            ->assertHeader('Content-Type', 'application/octet-stream')->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
        $this->get('/projects/'.$project->id)->assertInertia(fn (Assert $page): Assert => $page
            ->has('selectedProject.board_columns.0.tasks.0.attachments', 3)
            ->where('selectedProject.board_columns.0.tasks.0.attachments.0.name', 'notes.md')
            ->where('selectedProject.board_columns.0.tasks.0.attachments.0.size', 7)
            ->missing('selectedProject.board_columns.0.tasks.0.attachment_files')
            ->missing('selectedProject.board_columns.0.tasks.0.attachments.0.path'));

        $this->post($url, [...$payload, 'id' => $task->id, 'revision' => 2, 'removed_attachment_ids' => [$first['id']], 'attachments' => [UploadedFile::fake()->image('image.png')]])->assertRedirect();
        Storage::disk('local')->assertMissing($first['path']);
        Storage::disk('local')->assertExists([$second['path'], $html['path']]);
        $this->assertCount(3, $task->fresh()->attachment_files);
        $this->get(route('projects.tasks.attachments.download', [$project, $task, $first['id']]))->assertNotFound();
        Storage::disk('local')->delete($second['path']);
        $this->get(route('projects.tasks.attachments.download', [$project, $task, $second['id']]))->assertNotFound();
    }

    public function test_attachment_access_and_removal_are_scoped_to_the_task_and_project(): void
    {
        Storage::fake('local');
        $project = Project::factory()->create();
        $column = $project->boardColumns->first();
        $payload = ['action' => 'task.save', 'revision' => 1, 'column_id' => $column->id, 'title' => 'Owner', 'attachments' => [UploadedFile::fake()->create('file.txt')]];
        $this->put('/projects/'.$project->id.'/board', $payload)->assertRedirect();
        $task = Task::sole();
        $file = $task->attachment_files[0];
        $sibling = Task::factory()->for($column, 'column')->create();
        $other = Project::factory()->create();

        $this->get(route('projects.tasks.attachments.download', [$other, $task, $file['id']]))->assertNotFound();
        $this->get(route('projects.tasks.attachments.download', [$project, $sibling, $file['id']]))->assertNotFound();
        $this->putJson('/projects/'.$project->id.'/board', [
            'action' => 'task.save', 'revision' => 2, 'id' => $sibling->id, 'column_id' => $column->id,
            'title' => 'Wrong owner', 'removed_attachment_ids' => [$file['id']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['removed_attachment_ids' => 'Choose attachments belonging to this task.']);

        Storage::disk('local')->assertExists($file['path']);
        $this->assertSame(2, $project->fresh()->revision);
        $this->assertSame($sibling->title, $sibling->fresh()->title);
    }

    public function test_attachment_limits_reject_invalid_uploads_without_changing_the_task(): void
    {
        Storage::fake('local');
        $project = Project::factory()->create();
        $task = Task::factory()->for($project->boardColumns->first(), 'column')->create();
        $url = '/projects/'.$project->id.'/board';
        $payload = ['_method' => 'put', 'action' => 'task.save', 'revision' => 1, 'id' => $task->id, 'column_id' => $task->board_column_id, 'title' => 'Files'];

        $this->postJson($url, [...$payload, 'attachments' => ['not a file']])->assertUnprocessable()->assertJsonValidationErrors('attachments.0');
        $this->post($url, [...$payload, 'attachments' => [UploadedFile::fake()->create('large.zip', 10241)]], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('attachments.0');
        $this->assertSame(1, $project->fresh()->revision);
        $this->assertSame([], Storage::disk('local')->allFiles());

        $uploads = array_map(fn ($index) => UploadedFile::fake()->create('file-'.$index.'.txt'), range(1, 10));
        $this->post($url, [...$payload, 'attachments' => $uploads])->assertRedirect();
        $this->post($url, [...$payload, 'revision' => 2, 'attachments' => [UploadedFile::fake()->create('extra.txt')]], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['attachments' => 'A task can have up to 10 attachments.']);
        $this->assertCount(10, $task->fresh()->attachments);
        $this->assertCount(10, Storage::disk('local')->allFiles());
        $this->assertSame(2, $project->fresh()->revision);
    }

    public function test_conflicts_and_failed_writes_preserve_existing_files_and_remove_uncommitted_uploads(): void
    {
        Storage::fake('local');
        $project = Project::factory()->create();
        $url = '/projects/'.$project->id.'/board';
        $payload = ['_method' => 'put', 'action' => 'task.save', 'revision' => 1, 'column_id' => $project->boardColumns->first()->id, 'title' => 'Original'];
        $this->post($url, [...$payload, 'attachments' => [UploadedFile::fake()->create('original.txt')]])->assertRedirect();
        $task = Task::sole();
        $file = $task->attachment_files[0];
        $change = [...$payload, 'id' => $task->id, 'title' => 'Changed', 'removed_attachment_ids' => [$file['id']], 'attachments' => [UploadedFile::fake()->create('replacement.txt')]];

        $this->post($url, $change, ['Accept' => 'application/json'])->assertConflict();
        Exceptions::fake();
        DB::unprepared("CREATE TEMP TRIGGER fail_task_content BEFORE UPDATE ON tasks BEGIN SELECT RAISE(ABORT, 'Simulated failed write'); END");
        try {
            $this->post($url, [...$change, 'revision' => 2])->assertStatus(500);
        } finally {
            DB::unprepared('DROP TRIGGER fail_task_content');
        }

        Exceptions::assertReported(QueryException::class);
        $this->assertSame([$file['path']], Storage::disk('local')->allFiles());
        $this->assertSame($task->getAttributes(), $task->fresh()->getAttributes());
        $this->assertSame(2, $project->fresh()->revision);
    }

    public function test_task_moves_keep_attachments_and_deletion_cleans_only_the_owned_files(): void
    {
        Storage::fake('local');
        $project = Project::factory()->create();
        [$source, $destination] = $project->boardColumns;
        $url = '/projects/'.$project->id.'/board';
        $this->post($url, ['_method' => 'put', 'action' => 'task.save', 'revision' => 1, 'column_id' => $source->id, 'title' => 'Files', 'attachments' => [UploadedFile::fake()->create('file.txt')]])->assertRedirect();
        $task = Task::sole();
        $file = $task->attachment_files[0];

        $this->putJson($url, ['action' => 'column.delete', 'revision' => 2, 'id' => $source->id, 'destination_id' => $destination->id])->assertRedirect();
        $this->assertSame($file, $task->fresh()->attachment_files[0]);
        $this->get(route('projects.tasks.attachments.download', [$project, $task, $file['id']]))->assertDownload('file.txt');
        $this->putJson($url, ['action' => 'task.delete', 'revision' => 3, 'id' => $task->id])->assertRedirect();
        Storage::disk('local')->assertMissing($file['path']);
        $this->assertModelMissing($task);

        $this->post($url, ['_method' => 'put', 'action' => 'task.save', 'revision' => 4, 'column_id' => $destination->id, 'title' => 'Project file', 'attachments' => [UploadedFile::fake()->create('project.txt')]])->assertRedirect();
        $other = Project::factory()->create();
        $this->post('/projects/'.$other->id.'/board', ['_method' => 'put', 'action' => 'task.save', 'revision' => 1, 'column_id' => $other->boardColumns->first()->id, 'title' => 'Other file', 'attachments' => [UploadedFile::fake()->create('other.txt')]])->assertRedirect();
        $otherTask = $other->boardColumns->first()->tasks()->sole();
        $this->deleteJson('/projects/'.$project->id, ['revision' => 4])->assertConflict();
        $this->assertCount(2, Storage::disk('local')->allFiles());
        $this->deleteJson('/projects/'.$project->id, ['revision' => 5])->assertRedirect();
        $this->assertSame([$otherTask->attachment_files[0]['path']], Storage::disk('local')->allFiles());
        $this->assertModelExists($otherTask);
    }
}
