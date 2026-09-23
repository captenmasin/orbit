<?php

namespace Tests\Feature;

use App\Models\BoardColumn;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProjectBoardTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_and_existing_projects_get_their_own_default_board(): void
    {
        $this->post('/projects', ['name' => 'Board project', 'status' => 'Idea'])->assertRedirect();
        $project = Project::sole();
        $this->assertSame(['Backlog', 'To Do', 'In Progress', 'Done'], $project->boardColumns()->pluck('name')->all());
        $this->assertSame([0, 1, 2, 3], $project->boardColumns()->pluck('position')->all());
        $original = $project->getAttributes();
        $migration = require database_path('migrations/2026_09_15_141056_create_project_boards.php');
        $migration->down();
        $migration->up();

        $this->assertSame($original, $project->fresh()->getAttributes());
        $this->get('/projects/'.$project->id)->assertInertia(fn (Assert $page): Assert => $page
            ->has('selectedProject.board_columns', 4)
            ->where('selectedProject.board_columns.0.name', 'Backlog')
            ->has('selectedProject.board_columns.0.tasks', 0));
        $other = Project::factory()->create();
        $this->assertCount(4, $other->boardColumns);
        $this->assertSame([], array_intersect($project->boardColumns()->pluck('id')->all(), $other->boardColumns->modelKeys()));
    }

    public function test_tasks_can_be_created_edited_moved_reordered_and_deleted(): void
    {
        $project = Project::factory()->create();
        [$backlog, $todo, $progress] = $project->boardColumns;
        $url = '/projects/'.$project->id.'/board';
        $this->putJson($url, ['action' => 'task.save', 'revision' => 1, 'column_id' => $backlog->id, 'title' => '  First task  ', 'description' => "One\nTwo", 'position' => 99, 'project_id' => 'forged'])
            ->assertRedirect('/projects/'.$project->id);
        $first = Task::sole();
        $this->assertSame('First task', $first->title);
        $this->assertSame("One\nTwo", $first->description);
        $this->assertSame(0, $first->position);
        $second = Task::factory()->for($backlog, 'column')->create(['title' => 'Second task', 'position' => 1]);
        $third = Task::factory()->for($todo, 'column')->create(['title' => 'Third task']);

        $this->putJson($url, ['action' => 'task.move', 'revision' => 2, 'id' => $first->id, 'column_id' => $backlog->id, 'position' => 1])->assertRedirect();
        $this->assertSame([$second->id, $first->id], $backlog->tasks()->pluck('id')->all());
        $this->putJson($url, ['action' => 'task.move', 'revision' => 3, 'id' => $first->id, 'column_id' => $backlog->id, 'position' => 0])->assertRedirect();
        $this->assertSame([$first->id, $second->id], $backlog->tasks()->pluck('id')->all());
        $this->putJson($url, ['action' => 'task.move', 'revision' => 4, 'id' => $first->id, 'column_id' => $todo->id, 'position' => 0])->assertRedirect();
        $this->assertSame([$first->id, $third->id], $todo->tasks()->pluck('id')->all());
        $this->assertSame([0, 1], $todo->tasks()->pluck('position')->all());
        $this->assertSame(0, $second->fresh()->position);

        $this->putJson($url, ['action' => 'task.save', 'revision' => 5, 'id' => $first->id, 'column_id' => $todo->id, 'title' => 'Edited task'])->assertRedirect();
        $this->assertDatabaseHas('tasks', ['id' => $first->id, 'title' => 'Edited task', 'description' => null, 'position' => 0]);
        $this->putJson($url, ['action' => 'task.save', 'revision' => 6, 'id' => $first->id, 'column_id' => $progress->id, 'title' => 'Edited and moved'])->assertRedirect();
        $this->assertDatabaseHas('tasks', ['id' => $first->id, 'board_column_id' => $progress->id, 'position' => 0]);
        $this->assertSame(0, $third->fresh()->position);
        $this->get('/projects/'.$project->id)->assertInertia(fn (Assert $page): Assert => $page
            ->where('selectedProject.board_columns.2.tasks.0.title', 'Edited and moved')
            ->where('selectedProject.revision', 7));

        $this->putJson($url, ['action' => 'task.delete', 'revision' => 7, 'id' => $first->id])->assertRedirect();
        $this->assertModelMissing($first);
        $this->assertModelExists($second);
        $this->assertModelExists($third);
    }

    public function test_columns_can_be_added_renamed_reordered_and_removed_without_resetting_the_board(): void
    {
        $project = Project::factory()->create();
        $url = '/projects/'.$project->id.'/board';
        $this->putJson($url, ['action' => 'column.save', 'revision' => 1, 'name' => '  Review  ', 'position' => 99])->assertRedirect();
        $review = $project->boardColumns()->where('name', 'Review')->sole();
        $this->assertSame(4, $review->position);
        $this->putJson($url, ['action' => 'column.save', 'revision' => 2, 'id' => $review->id, 'name' => 'Ready'])->assertRedirect();
        $this->putJson($url, ['action' => 'column.move', 'revision' => 3, 'id' => $review->id, 'position' => 0])->assertRedirect();
        $this->assertSame(['Ready', 'Backlog', 'To Do', 'In Progress', 'Done'], $project->boardColumns()->pluck('name')->all());
        $this->putJson($url, ['action' => 'column.move', 'revision' => 4, 'id' => $review->id, 'position' => 3])->assertRedirect();
        $this->assertSame(['Backlog', 'To Do', 'In Progress', 'Ready', 'Done'], $project->boardColumns()->pluck('name')->all());
        $this->putJson($url, ['action' => 'column.delete', 'revision' => 5, 'id' => $review->id])->assertRedirect();
        $this->assertModelMissing($review);
        $this->assertSame([0, 1, 2, 3], $project->boardColumns()->pluck('position')->all());
        foreach ($project->boardColumns as $column) {
            $this->putJson($url, ['action' => 'column.delete', 'revision' => $project->fresh()->revision, 'id' => $column->id])->assertRedirect();
        }
        $this->get('/projects/'.$project->id)->assertInertia(fn (Assert $page): Assert => $page->has('selectedProject.board_columns', 0));
    }

    public function test_deleting_a_populated_column_requires_a_destination_and_preserves_task_order(): void
    {
        $project = Project::factory()->create();
        [$source, $destination] = $project->boardColumns;
        $tasks = Task::factory()->count(2)->for($source, 'column')->sequence(['position' => 0], ['position' => 1])->create();
        $existing = Task::factory()->for($destination, 'column')->create();
        $foreign = BoardColumn::factory()->create();
        $url = '/projects/'.$project->id.'/board';
        foreach ([null, $source->id, $foreign->id] as $invalidDestination) {
            $this->putJson($url, ['action' => 'column.delete', 'revision' => 1, 'id' => $source->id, 'destination_id' => $invalidDestination])
                ->assertUnprocessable()->assertJsonValidationErrors(['destination_id' => 'Choose another column for the remaining tasks.']);
            $this->assertSame(1, $project->fresh()->revision);
            $this->assertSame($tasks->modelKeys(), $source->tasks()->pluck('id')->all());
        }
        $this->putJson($url, ['action' => 'column.delete', 'revision' => 1, 'id' => $source->id, 'destination_id' => $destination->id])->assertRedirect();
        $this->assertModelMissing($source);
        $this->assertSame([$existing->id, ...$tasks->modelKeys()], $destination->tasks()->pluck('id')->all());
        $this->assertSame([0, 1, 2], $destination->tasks()->pluck('position')->all());
        $this->assertSame([0, 1, 2], $project->boardColumns()->pluck('position')->all());
    }

    public function test_stale_board_and_catalog_edits_fail_without_overwriting_newer_changes(): void
    {
        $project = Project::factory()->create();
        $task = Task::factory()->for($project->boardColumns->first(), 'column')->create(['title' => 'Original']);
        $url = '/projects/'.$project->id;
        $payload = ['action' => 'task.save', 'revision' => 1, 'id' => $task->id, 'column_id' => $task->board_column_id, 'title' => 'Newest'];
        $this->putJson($url.'/board', $payload)->assertRedirect();
        $this->putJson($url.'/board', [...$payload, 'title' => 'Stale'])->assertConflict();
        $this->putJson($url.'/board', ['action' => 'task.delete', 'revision' => 1, 'id' => $task->id])->assertConflict();
        $this->putJson($url, ['name' => 'Stale project', 'status' => 'Idea', 'revision' => 1])->assertConflict();
        $this->deleteJson($url, ['revision' => 1])->assertConflict();
        $this->assertSame('Newest', $task->fresh()->title);
        $this->assertSame(2, $project->fresh()->revision);

        $this->from($url)->withHeader('X-Inertia', 'true')->put($url.'/board', [...$payload, 'title' => 'Keep this draft'])
            ->assertRedirect($url)->assertSessionHasErrors(['revision' => 'This project changed. Reload the board before trying again.'])
            ->assertSessionHasInput('title', 'Keep this draft');
        $this->flushHeaders()->withCookie(config('session.cookie'), session()->getId())->withHeader('X-Inertia-Error-Bag', 'board')->get($url)
            ->assertInertia(fn (Assert $page): Assert => $page->where('errors.board.revision', 'This project changed. Reload the board before trying again.'));
        $this->flushHeaders()->putJson($url, ['name' => 'Catalog edit', 'status' => 'Live', 'revision' => 2])->assertRedirect();
        $this->putJson($url.'/board', [...$payload, 'revision' => 2])->assertConflict();
    }

    public function test_board_records_and_move_destinations_are_scoped_to_the_project(): void
    {
        $project = Project::factory()->create();
        $other = Project::factory()->create();
        $column = $project->boardColumns->first();
        $foreign = $other->boardColumns->first();
        $task = Task::factory()->for($column, 'column')->create();
        $foreignTask = Task::factory()->for($foreign, 'column')->create();
        $url = '/projects/'.$project->id.'/board';
        $attempts = [
            ['action' => 'task.move', 'id' => $task->id, 'column_id' => $foreign->id, 'position' => 0],
            ['action' => 'task.save', 'column_id' => $foreign->id, 'title' => 'Wrong project'],
            ['action' => 'task.save', 'id' => $foreignTask->id, 'column_id' => $column->id, 'title' => 'Wrong task'],
            ['action' => 'task.delete', 'id' => $foreignTask->id],
            ['action' => 'column.save', 'id' => $foreign->id, 'name' => 'Wrong column'],
            ['action' => 'column.move', 'id' => $foreign->id, 'position' => 0],
            ['action' => 'column.delete', 'id' => $foreign->id],
        ];
        foreach ($attempts as $attempt) {
            $this->putJson($url, [...$attempt, 'revision' => 1])->assertNotFound();
        }
        $this->assertSame(1, $project->fresh()->revision);
        $this->assertSame(1, $other->fresh()->revision);
        $this->assertSame($column->id, $task->fresh()->board_column_id);
        $this->assertModelExists($foreignTask);
        $this->assertDatabaseCount('tasks', 2);
        $this->get('/projects/'.$project->id)->assertInertia(fn (Assert $page): Assert => $page
            ->has('selectedProject.board_columns', 4)->has('selectedProject.board_columns.0.tasks', 1)
            ->where('selectedProject.board_columns.0.tasks.0.id', $task->id));
    }

    #[DataProvider('invalidChanges')]
    public function test_invalid_changes_do_not_mutate_the_board(array $payload, string $field): void
    {
        $project = Project::factory()->create();
        $column = $project->boardColumns->first();
        $this->putJson('/projects/'.$project->id.'/board', ['revision' => 1, 'id' => $column->id, ...$payload])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame(1, $project->fresh()->revision);
        $this->assertSame(['Backlog', 'To Do', 'In Progress', 'Done'], $project->boardColumns()->pluck('name')->all());
        $this->assertDatabaseCount('tasks', 0);
    }

    public static function invalidChanges(): array
    {
        return [
            'unknown action' => [['action' => 'drop table tasks'], 'action'],
            'empty column' => [['action' => 'column.save', 'name' => '   '], 'name'],
            'long column' => [['action' => 'column.save', 'name' => str_repeat('a', 101)], 'name'],
            'negative position' => [['action' => 'column.move', 'position' => -1], 'position'],
            'outside board' => [['action' => 'column.move', 'position' => 4], 'position'],
            'missing revision' => [['action' => 'column.save', 'name' => 'Changed', 'revision' => null], 'revision'],
            'blank task' => [['action' => 'task.save', 'title' => ' ', 'column_id' => 'bad'], 'title'],
            'long task' => [['action' => 'task.save', 'title' => str_repeat('a', 256), 'column_id' => 'bad'], 'title'],
            'long description' => [['action' => 'task.save', 'title' => 'Task', 'description' => str_repeat('a', 10001), 'column_id' => 'bad'], 'description'],
        ];
    }

    public function test_a_failed_write_rolls_back_all_task_moves_and_the_revision(): void
    {
        $project = Project::factory()->create();
        [$source, $destination] = $project->boardColumns;
        $tasks = Task::factory()->count(2)->for($source, 'column')->sequence(['position' => 0], ['position' => 1])->create();
        Exceptions::fake();
        DB::unprepared("CREATE TEMP TRIGGER fail_board_move BEFORE UPDATE ON tasks WHEN OLD.id = '{$tasks[1]->id}' BEGIN SELECT RAISE(ABORT, 'Simulated failed write'); END");
        try {
            $this->putJson('/projects/'.$project->id.'/board', ['action' => 'column.delete', 'revision' => 1, 'id' => $source->id, 'destination_id' => $destination->id])->assertStatus(500);
        } finally {
            DB::unprepared('DROP TRIGGER fail_board_move');
        }
        Exceptions::assertReported(QueryException::class);
        $this->assertModelExists($source);
        $this->assertSame($tasks->modelKeys(), $source->tasks()->pluck('id')->all());
        $this->assertSame([0, 1], $source->tasks()->pluck('position')->all());
        $this->assertSame(0, $destination->tasks()->count());
        $this->assertSame(1, $project->fresh()->revision);
    }

    public function test_invalid_task_positions_leave_both_columns_unchanged_and_deletion_closes_the_gap(): void
    {
        $project = Project::factory()->create();
        [$source, $destination] = $project->boardColumns;
        $tasks = Task::factory()->count(2)->for($source, 'column')->sequence(['position' => 0], ['position' => 1])->create();
        $url = '/projects/'.$project->id.'/board';
        $this->putJson($url, ['action' => 'task.move', 'revision' => 1, 'id' => $tasks[0]->id, 'column_id' => $destination->id, 'position' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('position');
        $this->assertSame($tasks->modelKeys(), $source->tasks()->pluck('id')->all());
        $this->assertSame(0, $destination->tasks()->count());
        $this->assertSame(1, $project->fresh()->revision);
        $this->putJson($url, ['action' => 'task.delete', 'revision' => 1, 'id' => $tasks[0]->id])->assertRedirect();
        $this->assertModelMissing($tasks[0]);
        $this->assertSame(0, $tasks[1]->fresh()->position);
    }

    public function test_removing_a_project_cascades_its_board_only(): void
    {
        $project = Project::factory()->create();
        $task = Task::factory()->for($project->boardColumns->first(), 'column')->create();
        $otherTask = Task::factory()->create();
        $this->deleteJson('/projects/'.$project->id, ['revision' => 1])->assertRedirect('/');
        $this->assertModelMissing($task);
        $this->assertSame(0, BoardColumn::where('project_id', $project->id)->count());
        $this->assertModelExists($otherTask);
    }
}
