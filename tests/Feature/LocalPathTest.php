<?php

namespace Tests\Feature;

use App\Mcp\LocalUpload;
use App\Models\ProjectFolder;
use App\Rules\AbsoluteLocalPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class LocalPathTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['relative/path'])]
    #[TestWith(['../outside'])]
    #[TestWith(['C:'])]
    #[TestWith(['C:relative'])]
    #[TestWith(['file:///tmp/source'])]
    #[TestWith(['php://filter/resource=/tmp/source'])]
    #[TestWith(["/tmp/bad\0path"])]
    public function test_relative_paths_stream_wrappers_and_null_bytes_are_rejected(string $path): void
    {
        $validator = Validator::make(['path' => $path], ['path' => [new AbsoluteLocalPath]]);

        $this->assertTrue($validator->fails());
        $this->assertSame(['path' => ['Choose an absolute local path.']], $validator->errors()->toArray());
    }

    public function test_native_paths_support_folder_inspection_nested_roots_and_local_uploads(): void
    {
        Storage::fake('local');
        Storage::makeDirectory('project/packages/web');
        Storage::put('project/package.json', '{"description":"Portable project"}');
        $base = realpath(Storage::path('project'));
        $nested = realpath(Storage::path('project/packages/web'));

        $this->postJson('/folders/inspect', ['path' => $base])->assertOk()->assertJsonPath('folder.description', 'Portable project');
        $folder = ProjectFolder::factory()->create(['path' => $base]);
        $this->postJson('/projects/'.$folder->project_id.'/roots', ['action' => 'save', 'folder_id' => $folder->id, 'path' => $nested])->assertOk();

        $root = $folder->packageRoots()->where('relative_path', 'packages/web')->sole();
        $this->assertSame($nested, $root->resolvePath());
        $this->assertSame('{"description":"Portable project"}', LocalUpload::fromPath(Storage::path('project/package.json'), 'file')->getContent());
        $this->postJson('/settings/tools/probe', ['paths' => ['php' => 'file://'.$base]])->assertInvalid('paths.php');
    }

    #[TestWith(['C:\\Projects\\Orbit'])]
    #[TestWith(['C:/Projects/Orbit'])]
    #[TestWith(['\\\\server\\share\\Orbit'])]
    public function test_windows_absolute_paths_are_accepted(string $path): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Windows absolute paths are only local on Windows.');
        }

        $this->assertTrue(Validator::make(['path' => $path], ['path' => [new AbsoluteLocalPath]])->passes());
    }
}
