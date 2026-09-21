<?php

namespace App;

use App\Actions\ProtectCredential;
use App\Rules\ProjectUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;

class WorkspaceRestore
{
    /**
     * Validates a complete backup and replaces portable secret values with local ciphertext.
     *
     * @param  list<array{type: string, data: array<string, mixed>}>  $records
     * @return array{summary: array{created_at: string, projects: int, tasks: int, secrets: int, includes_secrets: bool}, records: list<array{type: string, data: array<string, mixed>}>}
     */
    public function stage(array $records, ProtectCredential $crypto): array
    {
        $tables = array_fill_keys(['projects', 'tags', 'project_tag', 'repositories', 'project_folders', 'package_roots', 'project_links', 'board_columns', 'tasks', 'project_secrets'], []);
        $assets = [];
        if (! isset($records[0]) || $records[0]['type'] !== 'workspace' || ($records[0]['data']['schema'] ?? null) !== 1 || ! is_string($records[0]['data']['created_at'] ?? null) || ! is_bool($records[0]['data']['includes_secrets'] ?? null)) {
            $this->invalid();
        }
        $includesSecrets = $records[0]['data']['includes_secrets'];
        foreach ($records as $index => &$record) {
            if (! is_array($record) || ! isset($record['type'], $record['data']) || ! is_string($record['type']) || ! is_array($record['data']) || ($index === 0 && $record['type'] !== 'workspace')) {
                $this->invalid();
            }
            if ($index === 0) {
                continue;
            }
            if (array_key_exists($record['type'], $tables)) {
                $tables[$record['type']][] = &$record['data'];

                continue;
            }
            if ($record['type'] !== 'asset') {
                $this->invalid();
            }
            $this->asset($record['data'], $assets);
        }
        unset($record);
        if (! $includesSecrets && $tables['project_secrets']) {
            $this->invalid();
        }
        $projects = $this->ids($tables['projects']);
        $tags = $this->ids($tables['tags'], false);
        $repositories = $this->ids($tables['repositories']);
        $folders = $this->ids($tables['project_folders']);
        $roots = $this->ids($tables['package_roots']);
        $columns = $this->ids($tables['board_columns']);
        $tasks = $this->ids($tables['tasks']);
        $links = $this->ids($tables['project_links']);
        $secrets = $this->ids($tables['project_secrets']);
        foreach ($assets as $asset) {
            if (array_keys($asset['parts']) !== range(0, $asset['chunks'] - 1)) {
                $this->invalid();
            }
        }
        foreach ($tables['projects'] as &$project) {
            if (isset($project['notes']) && (! is_string($project['notes']) || mb_strlen($project['notes']) > 50000)) {
                $this->invalid();
            }
            if (isset($project['position']) && (! is_int($project['position']) || $project['position'] < 0)) {
                $this->invalid();
            }
            $project['asset_files'] ??= [];
            $this->validateFiles($project['asset_files'], 'project-file:'.$project['id'].':', $assets);
            if (($project['icon_type'] ?? null) === 'image') {
                $assetId = $project['icon_asset_id'] ?? null;
                if (! is_string($assetId) || $assetId !== 'icon:'.$project['id'] || ! isset($assets[$assetId]) || ! in_array($assets[$assetId]['mime'], ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) {
                    $this->invalid();
                }
            } elseif (isset($project['icon_asset_id'])) {
                $this->invalid();
            }
        }
        unset($project);
        foreach ($tables['repositories'] as $repository) {
            if (! isset($projects[$repository['project_id'] ?? '']) || isset($repository['provider_connection_id']) || ! $this->validUrl($repository['remote_url'] ?? null, true)) {
                $this->invalid();
            }
        }
        foreach ($tables['project_folders'] as $folder) {
            if (! isset($projects[$folder['project_id'] ?? '']) || ! is_string($folder['path'] ?? null) || ($folder['repository_id'] !== null && ! isset($repositories[$folder['repository_id']]))) {
                $this->invalid();
            }
        }
        foreach ($tables['package_roots'] as $root) {
            if (! isset($folders[$root['project_folder_id'] ?? '']) || ! is_string($root['relative_path'] ?? null) || str_starts_with($root['relative_path'], '/') || str_contains($root['relative_path'], '..')) {
                $this->invalid();
            }
        }
        foreach ($tables['project_links'] as $link) {
            if (! isset($projects[$link['project_id'] ?? '']) || ! $this->validUrl($link['url'] ?? null) || ! is_int($link['position'] ?? null)) {
                $this->invalid();
            }
        }
        foreach ($tables['board_columns'] as $column) {
            if (! isset($projects[$column['project_id'] ?? '']) || ! is_int($column['position'] ?? null)) {
                $this->invalid();
            }
        }
        foreach ($tables['tasks'] as $task) {
            if (! isset($columns[$task['board_column_id'] ?? '']) || ! is_int($task['position'] ?? null) || ! is_array($task['attachment_files'] ?? null)) {
                $this->invalid();
            }
            foreach ($task['attachment_files'] as $attachment) {
                $assetId = is_array($attachment) ? $attachment['asset_id'] ?? null : null;
                if (! is_array($attachment) || ! is_string($attachment['id'] ?? null) || ! Str::isUuid($attachment['id']) || ! is_string($attachment['name'] ?? null) || ! is_int($attachment['size'] ?? null) || $attachment['size'] < 0 || ! is_string($assetId) || $assetId !== 'attachment:'.$task['id'].':'.$attachment['id'] || ! isset($assets[$assetId]) || isset($attachment['path'])) {
                    $this->invalid();
                }
            }
        }
        foreach ($tables['project_tag'] as $tag) {
            if (! isset($projects[$tag['project_id'] ?? '']) || ! isset($tags[$tag['tag_id'] ?? ''])) {
                $this->invalid();
            }
        }
        $this->positions($tables['project_links'], 'project_id');
        $this->positions($tables['board_columns'], 'project_id');
        $this->positions($tables['tasks'], 'board_column_id');
        $names = [];
        foreach ($tables['project_secrets'] as &$secret) {
            $key = ($secret['project_id'] ?? '')."\0".($secret['environment'] ?? '')."\0".($secret['name'] ?? '');
            $value = $secret['value'] ?? null;
            if (! isset($projects[$secret['project_id'] ?? '']) || ! is_string($secret['environment'] ?? null) || trim($secret['environment']) === '' || strlen($secret['environment']) > 100 || ! is_string($secret['name'] ?? null) || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $secret['name']) !== 1 || isset($names[$key]) || ! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || strlen($value) > 1024 * 1024) {
                $this->invalid();
            }
            $names[$key] = true;
            $secret['ciphertext'] = $crypto->encrypt($value);
            unset($secret['value']);
        }
        unset($secret);

        return ['summary' => ['created_at' => $records[0]['data']['created_at'], 'projects' => count($projects), 'tasks' => count($tasks), 'secrets' => count($secrets), 'includes_secrets' => $includesSecrets], 'records' => $records];
    }

    /**
     * @param  array{records: list<array{type: string, data: array<string, mixed>}>}  $staged
     */
    public function apply(array $staged): void
    {
        $records = $staged['records'] ?? null;
        if (! is_array($records)) {
            $this->invalid();
        }
        $tables = array_fill_keys(['projects', 'tags', 'project_tag', 'repositories', 'project_folders', 'package_roots', 'project_links', 'board_columns', 'tasks', 'project_secrets'], []);
        $assets = [];
        foreach ($records as $record) {
            if (! is_array($record) || ! is_string($record['type'] ?? null) || ! is_array($record['data'] ?? null)) {
                $this->invalid();
            }
            if (array_key_exists($record['type'], $tables)) {
                $tables[$record['type']][] = $record['data'];
            } elseif ($record['type'] === 'asset') {
                $assets[$record['data']['id']][] = $record['data'];
            }
        }
        $restoreId = (string) Str::uuid7();
        $paths = $this->writeAssets($assets, $restoreId);
        $oldFiles = [];
        try {
            DB::transaction(function () use ($tables, $paths, &$oldFiles): void {
                $oldFiles = [
                    ...DB::table('projects')->whereNotNull('icon_path')->pluck('icon_path')->all(),
                    ...DB::table('projects')->whereNotNull('asset_files')->pluck('asset_files')->flatMap(fn (string $files): array => array_column(json_decode($files, true) ?: [], 'path'))->all(),
                    ...DB::table('tasks')->whereNotNull('attachment_files')->pluck('attachment_files')->flatMap(fn (string $files): array => array_column(json_decode($files, true) ?: [], 'path'))->all(),
                ];
                DB::table('credential_access_events')->delete();
                DB::table('provider_snapshots')->delete();
                DB::table('provider_connections')->delete();
                DB::table('projects')->delete();
                DB::table('tags')->delete();
                foreach ($tables['tags'] as $tag) {
                    DB::table('tags')->insert($tag);
                }
                foreach ($tables['projects'] as $project) {
                    $project['icon_path'] = isset($project['icon_asset_id']) ? $paths[$project['icon_asset_id']] : null;
                    $project['asset_files'] = json_encode(array_map(function (array $file) use ($paths): array {
                        $file['path'] = $paths[$file['asset_id']];
                        unset($file['asset_id']);

                        return $file;
                    }, $project['asset_files'] ?? []), JSON_THROW_ON_ERROR);
                    unset($project['icon_asset_id']);
                    DB::table('projects')->insert($project);
                }
                foreach (['project_tag', 'repositories', 'project_folders', 'package_roots', 'project_links', 'board_columns'] as $table) {
                    foreach ($tables[$table] as $row) {
                        DB::table($table)->insert($row);
                    }
                }
                foreach ($tables['tasks'] as $task) {
                    $task['attachment_files'] = array_map(fn (array $file): array => [...$file, 'path' => $paths[$file['asset_id']]], $task['attachment_files']);
                    foreach ($task['attachment_files'] as &$file) {
                        unset($file['asset_id']);
                    }
                    unset($file);
                    $task['attachment_files'] = json_encode($task['attachment_files'], JSON_THROW_ON_ERROR);
                    DB::table('tasks')->insert($task);
                }
                foreach ($tables['project_secrets'] as $secret) {
                    unset($secret['value']);
                    DB::table('project_secrets')->insert($secret);
                }
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($paths);

            throw $exception;
        }
        Storage::disk('local')->delete(array_diff($oldFiles, $paths));
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $assets
     * @return array<string, string>
     */
    private function writeAssets(array $assets, string $restoreId): array
    {
        $paths = [];
        $iconExtensions = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'];
        foreach ($assets as $id => $chunks) {
            usort($chunks, fn (array $left, array $right): int => $left['chunk'] <=> $right['chunk']);
            $mime = $chunks[0]['mime'];
            $contents = implode('', array_map(fn (array $chunk): string => base64_decode($chunk['content'], true), $chunks));
            $path = str_starts_with($id, 'icon:')
                ? 'project-icons/restored-'.$restoreId.'-'.$id.'.'.$iconExtensions[$mime]
                : (str_starts_with($id, 'project-file:') ? 'project-assets/' : 'task-attachments/').'restored-'.$restoreId.'/'.Str::uuid7();
            if (! Storage::disk('local')->put($path, $contents) || ! chmod(Storage::disk('local')->path($path), 0600)) {
                Storage::disk('local')->delete($paths);
                throw new InvalidArgumentException('Backup assets could not be prepared.');
            }
            $paths[$id] = $path;
        }

        return $paths;
    }

    /**
     * @param  array<string, mixed>  $asset
     * @param  array<string, array{chunks: int, mime: string, parts: array<int, true>}>  $assets
     */
    private function asset(array $asset, array &$assets): void
    {
        $invalid = ! is_string($asset['id'] ?? null) || ! is_int($asset['chunk'] ?? null) || ! is_int($asset['chunks'] ?? null) || ! is_string($asset['mime'] ?? null) || strlen($asset['mime']) > 255 || ! is_string($asset['content'] ?? null)
            || $asset['chunk'] < 0 || $asset['chunks'] < 1 || $asset['chunk'] >= $asset['chunks'] || strlen($asset['content']) > 3 * 1024 * 1024 || base64_decode($asset['content'], true) === false;
        $duplicate = ! $invalid && isset($assets[$asset['id']]) && ($assets[$asset['id']]['chunks'] !== $asset['chunks'] || $assets[$asset['id']]['mime'] !== $asset['mime'] || isset($assets[$asset['id']]['parts'][$asset['chunk']]));
        if ($invalid || $duplicate) {
            $this->invalid();
        }
        $assets[$asset['id']] ??= ['chunks' => $asset['chunks'], 'mime' => $asset['mime'], 'parts' => []];
        $assets[$asset['id']]['parts'][$asset['chunk']] = true;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string|int, true>
     */
    private function ids(array $rows, bool $uuid = true): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            if (($uuid && (! is_string($id) || ! Str::isUuid($id))) || (! $uuid && ! is_int($id)) || isset($ids[$id])) {
                $this->invalid();
            }
            $ids[$id] = true;
        }

        return $ids;
    }

    /** @param list<array<string, mixed>> $rows */
    private function positions(array $rows, string $parent): void
    {
        $groups = [];
        foreach ($rows as $row) {
            $groups[$row[$parent]][] = $row['position'];
        }
        foreach ($groups as $positions) {
            sort($positions);
            if ($positions !== range(0, count($positions) - 1)) {
                $this->invalid();
            }
        }
    }

    private function validUrl(mixed $url, bool $repository = false): bool
    {
        return is_string($url) && ! Validator::make(['url' => $url], ['url' => [new ProjectUrl(repository: $repository)]])->fails();
    }

    private function validateFiles(mixed $files, string $prefix, array $assets): void
    {
        if (! is_array($files) || count($files) > 100) {
            $this->invalid();
        }
        $ids = [];
        foreach ($files as $file) {
            if (! is_array($file) || ! is_string($file['id'] ?? null) || ! Str::isUuid($file['id']) || isset($ids[$file['id']]) || ! is_string($file['name'] ?? null) || ! is_int($file['size'] ?? null) || $file['size'] < 0 || ! is_string($file['asset_id'] ?? null) || $file['asset_id'] !== $prefix.$file['id'] || ! isset($assets[$file['asset_id']]) || isset($file['path'])) {
                $this->invalid();
            }
            $ids[$file['id']] = true;
        }
    }

    private function invalid(): never
    {
        throw new InvalidArgumentException('Backup data is invalid.');
    }
}
