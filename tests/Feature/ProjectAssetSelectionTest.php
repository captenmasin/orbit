<?php

namespace Tests\Feature;

use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ProjectAssetSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_moving_files_and_folders_together_preserves_nested_contents_and_private_bytes(): void
    {
        Storage::fake('local');
        $project = Project::factory()->create();
        $sourceId = (string) Str::uuid();
        $targetId = (string) Str::uuid();
        $childId = (string) Str::uuid();
        $fileId = (string) Str::uuid();
        $path = 'project-assets/'.$project->id.'/first.txt';
        $project->forceFill([
            'asset_folders' => [
                ['id' => $sourceId, 'name' => 'Source', 'parent_id' => null],
                ['id' => $targetId, 'name' => 'Target', 'parent_id' => null],
                ['id' => $childId, 'name' => 'Child', 'parent_id' => $sourceId],
            ],
            'asset_files' => [['id' => $fileId, 'name' => 'first.txt', 'size' => 5, 'path' => $path, 'folder_id' => null]],
        ])->save();
        Storage::disk('local')->put($path, 'first');
        $url = '/projects/'.$project->id;

        $this->put($url.'/assets/move', [
            'revision' => 1, 'folder_id' => $targetId,
            'items' => [['type' => 'folder', 'id' => $sourceId], ['type' => 'file', 'id' => $fileId]],
        ])->assertRedirect($url);

        $current = $project->fresh();
        $this->assertSame(2, $current->revision);
        $this->assertSame($targetId, $current->asset_folders[0]['parent_id']);
        $this->assertSame($sourceId, $current->asset_folders[2]['parent_id']);
        $this->assertSame($targetId, $current->asset_files[0]['folder_id']);
        Storage::disk('local')->assertExists($path);
    }

    #[TestWith(['folder'])]
    #[TestWith(['file'])]
    public function test_moving_a_folder_with_a_selected_descendant_returns_422_without_changing_assets(string $descendantType): void
    {
        $project = Project::factory()->create();
        $parentId = (string) Str::uuid();
        $childId = (string) Str::uuid();
        $targetId = (string) Str::uuid();
        $fileId = (string) Str::uuid();
        $folders = [
            ['id' => $parentId, 'name' => 'Parent', 'parent_id' => null],
            ['id' => $childId, 'name' => 'Child', 'parent_id' => $parentId],
            ['id' => $targetId, 'name' => 'Target', 'parent_id' => null],
        ];
        $files = [['id' => $fileId, 'name' => 'nested.txt', 'size' => 6, 'path' => 'project-assets/'.$project->id.'/nested.txt', 'folder_id' => $childId]];
        $project->forceFill(['asset_folders' => $folders, 'asset_files' => $files])->save();

        $this->putJson('/projects/'.$project->id.'/assets/move', [
            'revision' => 1,
            'folder_id' => $targetId,
            'items' => [
                ['type' => 'folder', 'id' => $parentId],
                ['type' => $descendantType, 'id' => $descendantType === 'file' ? $fileId : $childId],
            ],
        ])->assertUnprocessable()->assertJsonPath('errors.items.0', 'Select either a folder or its contents to move.');

        $this->assertSame(1, $project->fresh()->revision);
        $this->assertSame($folders, $project->fresh()->asset_folders);
        $this->assertSame($files, $project->fresh()->asset_files);
    }

    public function test_moving_a_folder_into_its_descendant_returns_422_without_changing_the_tree(): void
    {
        $project = Project::factory()->create();
        $parentId = (string) Str::uuid();
        $childId = (string) Str::uuid();
        $folders = [
            ['id' => $parentId, 'name' => 'Parent', 'parent_id' => null],
            ['id' => $childId, 'name' => 'Child', 'parent_id' => $parentId],
        ];
        $project->forceFill(['asset_folders' => $folders])->save();

        $this->putJson('/projects/'.$project->id.'/assets/move', [
            'revision' => 1, 'folder_id' => $childId, 'items' => [['type' => 'folder', 'id' => $parentId]],
        ])->assertUnprocessable()->assertJsonValidationErrors('folder_id');

        $this->assertSame(1, $project->fresh()->revision);
        $this->assertSame($folders, $project->fresh()->asset_folders);
    }

    public function test_moving_conflicting_folder_names_returns_422_without_changing_the_tree(): void
    {
        $project = Project::factory()->create();
        $targetId = (string) Str::uuid();
        $movingId = (string) Str::uuid();
        $folders = [
            ['id' => $targetId, 'name' => 'Target', 'parent_id' => null],
            ['id' => $movingId, 'name' => 'Icons', 'parent_id' => null],
            ['id' => (string) Str::uuid(), 'name' => 'icons', 'parent_id' => $targetId],
        ];
        $project->forceFill(['asset_folders' => $folders])->save();

        $this->putJson('/projects/'.$project->id.'/assets/move', [
            'revision' => 1, 'folder_id' => $targetId, 'items' => [['type' => 'folder', 'id' => $movingId]],
        ])->assertUnprocessable()->assertJsonValidationErrors('folder_id');

        $this->assertSame(1, $project->fresh()->revision);
        $this->assertSame($folders, $project->fresh()->asset_folders);
    }

    public function test_moving_foreign_assets_returns_404_and_foreign_destinations_return_422(): void
    {
        $project = Project::factory()->create();
        $other = Project::factory()->create();
        $fileId = (string) Str::uuid();
        $foreignFileId = (string) Str::uuid();
        $foreignFolderId = (string) Str::uuid();
        $files = [['id' => $fileId, 'name' => 'local.txt', 'size' => 1, 'path' => 'project-assets/'.$project->id.'/local.txt', 'folder_id' => null]];
        $project->forceFill(['asset_files' => $files])->save();
        $other->forceFill([
            'asset_files' => [['id' => $foreignFileId, 'name' => 'foreign.txt', 'size' => 1, 'path' => 'project-assets/'.$other->id.'/foreign.txt', 'folder_id' => null]],
            'asset_folders' => [['id' => $foreignFolderId, 'name' => 'Foreign', 'parent_id' => null]],
        ])->save();
        $url = '/projects/'.$project->id.'/assets/move';

        $this->putJson($url, ['revision' => 1, 'folder_id' => null, 'items' => [
            ['type' => 'file', 'id' => $fileId], ['type' => 'file', 'id' => $foreignFileId],
        ]])->assertNotFound();
        $this->putJson($url, ['revision' => 1, 'folder_id' => $foreignFolderId, 'items' => [
            ['type' => 'file', 'id' => $fileId],
        ]])->assertUnprocessable()->assertJsonValidationErrors('folder_id');
        $this->putJson($url, ['revision' => 2, 'folder_id' => null, 'items' => [
            ['type' => 'file', 'id' => $fileId],
        ]])->assertUnprocessable()->assertJsonValidationErrors('revision');

        $this->assertSame(1, $project->fresh()->revision);
        $this->assertSame($files, $project->fresh()->asset_files);
        $this->assertSame(1, $other->fresh()->revision);
    }

    public function test_removing_selected_files_and_nested_folders_promotes_surviving_contents(): void
    {
        Storage::fake('local');
        $project = Project::factory()->create();
        $survivingId = (string) Str::uuid();
        $outerId = (string) Str::uuid();
        $innerId = (string) Str::uuid();
        $nestedId = (string) Str::uuid();
        $siblingId = (string) Str::uuid();
        $removedFileId = (string) Str::uuid();
        $keptFileId = (string) Str::uuid();
        $removedPath = 'project-assets/'.$project->id.'/removed.txt';
        $keptPath = 'project-assets/'.$project->id.'/kept.txt';
        $project->forceFill([
            'asset_folders' => [
                ['id' => $survivingId, 'name' => 'Surviving', 'parent_id' => null],
                ['id' => $outerId, 'name' => 'Outer', 'parent_id' => $survivingId],
                ['id' => $innerId, 'name' => 'Inner', 'parent_id' => $outerId],
                ['id' => $nestedId, 'name' => 'Nested', 'parent_id' => $innerId],
                ['id' => $siblingId, 'name' => 'Sibling', 'parent_id' => $outerId],
            ],
            'asset_files' => [
                ['id' => $removedFileId, 'name' => 'removed.txt', 'size' => 7, 'path' => $removedPath, 'folder_id' => $nestedId],
                ['id' => $keptFileId, 'name' => 'kept.txt', 'size' => 4, 'path' => $keptPath, 'folder_id' => $innerId],
            ],
        ])->save();
        Storage::disk('local')->put($removedPath, 'removed');
        Storage::disk('local')->put($keptPath, 'kept');
        $url = '/projects/'.$project->id;

        $this->delete($url.'/assets/selection', ['revision' => 1, 'items' => [
            ['type' => 'folder', 'id' => $outerId],
            ['type' => 'folder', 'id' => $innerId],
            ['type' => 'file', 'id' => $removedFileId],
        ]])->assertRedirect($url);

        $current = $project->fresh();
        $this->assertSame(2, $current->revision);
        $this->assertSame([$survivingId, $nestedId, $siblingId], array_column($current->asset_folders, 'id'));
        $this->assertSame([null, $survivingId, $survivingId], array_column($current->asset_folders, 'parent_id'));
        $this->assertSame([$keptFileId], array_column($current->asset_files, 'id'));
        $this->assertSame($survivingId, $current->asset_files[0]['folder_id']);
        Storage::disk('local')->assertMissing($removedPath);
        Storage::disk('local')->assertExists($keptPath);
    }

    public function test_removing_a_folder_with_conflicting_promoted_names_returns_422(): void
    {
        $project = Project::factory()->create();
        $parentId = (string) Str::uuid();
        $folders = [
            ['id' => $parentId, 'name' => 'Parent', 'parent_id' => null],
            ['id' => (string) Str::uuid(), 'name' => 'Icons', 'parent_id' => $parentId],
            ['id' => (string) Str::uuid(), 'name' => 'icons', 'parent_id' => null],
        ];
        $project->forceFill(['asset_folders' => $folders])->save();

        $this->deleteJson('/projects/'.$project->id.'/assets/selection', [
            'revision' => 1, 'items' => [['type' => 'folder', 'id' => $parentId]],
        ])->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->assertSame(1, $project->fresh()->revision);
        $this->assertSame($folders, $project->fresh()->asset_folders);
    }

    public function test_removing_a_foreign_file_returns_404_without_deleting_local_bytes(): void
    {
        Storage::fake('local');
        $project = Project::factory()->create();
        $other = Project::factory()->create();
        $localId = (string) Str::uuid();
        $foreignId = (string) Str::uuid();
        $path = 'project-assets/'.$project->id.'/local.txt';
        $files = [['id' => $localId, 'name' => 'local.txt', 'size' => 5, 'path' => $path, 'folder_id' => null]];
        $project->forceFill(['asset_files' => $files])->save();
        $other->forceFill(['asset_files' => [['id' => $foreignId, 'name' => 'foreign.txt', 'size' => 7, 'path' => 'project-assets/'.$other->id.'/foreign.txt', 'folder_id' => null]]])->save();
        Storage::disk('local')->put($path, 'local');

        $this->deleteJson('/projects/'.$project->id.'/assets/selection', ['revision' => 1, 'items' => [
            ['type' => 'file', 'id' => $localId], ['type' => 'file', 'id' => $foreignId],
        ]])->assertNotFound();

        $this->assertSame(1, $project->fresh()->revision);
        $this->assertSame($files, $project->fresh()->asset_files);
        Storage::disk('local')->assertExists($path);
    }
}
