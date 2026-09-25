<?php

namespace App\Actions;

use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class DuplicateProject
{
    public function handle(Project $source): Project
    {
        $copiedFiles = [];

        try {
            return DB::transaction(function () use ($source, &$copiedFiles): Project {
                $source->load(['tags', 'repositories', 'folders.packageRoots', 'links', 'documents', 'boardColumns.tasks', 'secrets']);
                $duplicate = $source->replicate(['revision', 'icon_path', 'asset_files', 'asset_folders']);
                $nameSuffix = ' (copy)';
                $duplicate->name = mb_substr($source->name, 0, 255 - mb_strlen($nameSuffix)).$nameSuffix;
                $duplicate->save();
                $duplicate->tags()->sync($source->tags->modelKeys());

                $repositoryIds = [];
                foreach ($source->repositories as $repository) {
                    $copy = $repository->replicate(['provider_revision', 'remote_commit_at']);
                    $duplicate->repositories()->save($copy);
                    $repositoryIds[$repository->id] = $copy->id;
                }
                foreach ($source->folders as $folder) {
                    $copy = $duplicate->folders()->create([
                        'path' => $folder->path,
                        'repository_id' => $folder->repository_id ? $repositoryIds[$folder->repository_id] : null,
                    ]);
                    $copy->packageRoots()->delete();
                    foreach ($folder->packageRoots as $root) {
                        $copy->packageRoots()->create([
                            'relative_path' => $root->relative_path,
                            'executable_overrides' => $root->executable_overrides,
                        ]);
                    }
                }
                foreach ($source->links as $link) {
                    $duplicate->links()->save($link->replicate());
                }
                foreach ($source->documents as $document) {
                    $duplicate->documents()->save($document->replicate(['revision']));
                }
                foreach ($source->secrets as $secret) {
                    $duplicate->secrets()->save($secret->replicate(['revision']));
                }

                $duplicate->boardColumns()->delete();
                foreach ($source->boardColumns as $column) {
                    $copy = $column->replicate();
                    $duplicate->boardColumns()->save($copy);
                    foreach ($column->tasks as $task) {
                        $taskCopy = $task->replicate(['attachment_files']);
                        $copy->tasks()->save($taskCopy);
                        $taskCopy->attachment_files = array_map(function (array $file) use ($duplicate, &$copiedFiles): array {
                            return [
                                ...$file,
                                'id' => (string) Str::uuid7(),
                                'path' => $this->copyFile($file['path'], 'task-attachments/'.$duplicate->id, $copiedFiles),
                            ];
                        }, $task->attachment_files ?? []);
                        $taskCopy->save();
                    }
                }

                $assetFolderIds = [];
                foreach ($source->asset_folders ?? [] as $folder) {
                    $assetFolderIds[$folder['id']] = (string) Str::uuid7();
                }
                $duplicate->asset_folders = array_map(fn (array $folder): array => [
                    ...$folder,
                    'id' => $assetFolderIds[$folder['id']],
                    'parent_id' => isset($folder['parent_id']) ? $assetFolderIds[$folder['parent_id']] : null,
                ], $source->asset_folders ?? []);
                $duplicate->asset_files = array_map(function (array $file) use ($duplicate, $assetFolderIds, &$copiedFiles): array {
                    return [
                        ...$file,
                        'id' => (string) Str::uuid7(),
                        'path' => $this->copyFile($file['path'], 'project-assets/'.$duplicate->id, $copiedFiles),
                        'folder_id' => isset($file['folder_id']) ? $assetFolderIds[$file['folder_id']] : null,
                    ];
                }, $source->asset_files ?? []);
                $duplicate->icon_path = $source->icon_path
                    ? $this->copyFile($source->icon_path, 'project-icons', $copiedFiles)
                    : null;
                $duplicate->save();

                return $duplicate->refresh();
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($copiedFiles);

            throw $exception;
        }
    }

    /** @param list<string> $copiedFiles */
    private function copyFile(string $source, string $directory, array &$copiedFiles): string
    {
        $extension = pathinfo($source, PATHINFO_EXTENSION);
        $destination = $directory.'/'.Str::uuid7().($extension === '' ? '' : '.'.$extension);
        $copiedFiles[] = $destination;
        if (! Storage::disk('local')->exists($source) || ! Storage::disk('local')->copy($source, $destination)) {
            throw new RuntimeException('Project files could not be duplicated.');
        }

        return $destination;
    }
}
