<?php

namespace App;

use App\Actions\ProtectCredential;
use App\Models\ProjectSecret;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class WorkspaceBackup
{
    /**
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    public function records(bool $includeSecrets, ProtectCredential $crypto): array
    {
        return DB::transaction(fn (): array => $this->buildRecords($includeSecrets, $crypto));
    }

    /**
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    private function buildRecords(bool $includeSecrets, ProtectCredential $crypto): array
    {
        $records = [[
            'type' => 'workspace',
            'data' => ['schema' => 1, 'created_at' => now()->toIso8601String(), 'includes_secrets' => $includeSecrets],
        ]];
        $assets = [];
        foreach (['projects', 'tags', 'project_tag', 'repositories', 'project_folders', 'package_roots', 'project_links', 'board_columns', 'tasks'] as $table) {
            $query = DB::table($table)->orderBy($table === 'project_tag' ? 'project_id' : 'id');
            foreach ($query->get() as $row) {
                $data = (array) $row;
                if ($table === 'projects' && $data['icon_path']) {
                    $data['icon_asset_id'] = 'icon:'.$data['id'];
                    $assets[] = $this->asset($data['icon_asset_id'], $data['icon_path'], 'project-icons/');
                    $data['icon_path'] = null;
                }
                if ($table === 'repositories') {
                    $data = Arr::only($data, ['id', 'project_id', 'name', 'remote_url', 'created_at', 'updated_at']);
                }
                if ($table === 'project_folders') {
                    $data = Arr::only($data, ['id', 'project_id', 'repository_id', 'path', 'created_at', 'updated_at']);
                }
                if ($table === 'package_roots') {
                    $data = Arr::only($data, ['id', 'project_folder_id', 'relative_path', 'executable_overrides', 'revision', 'created_at', 'updated_at']);
                }
                if (in_array($table, ['tasks', 'projects'], true)) {
                    $column = $table === 'projects' ? 'asset_files' : 'attachment_files';
                    $prefix = $table === 'projects' ? 'project-file:' : 'attachment:';
                    $files = json_decode($data[$column] ?? '[]', true);
                    if (! is_array($files)) {
                        throw new RuntimeException('Workspace attachments could not be read.');
                    }
                    $data[$column] = [];
                    foreach ($files as $file) {
                        if (! is_array($file) || ! isset($file['id'], $file['name'], $file['path'], $file['size']) || ! is_string($file['id']) || ! is_string($file['name']) || ! is_string($file['path']) || ! is_int($file['size'])) {
                            throw new RuntimeException('Workspace attachments could not be read.');
                        }
                        $assetId = $prefix.$data['id'].':'.$file['id'];
                        $assets[] = $this->asset($assetId, $file['path'], $table === 'projects' ? 'project-assets/' : 'task-attachments/');
                        $data[$column][] = Arr::only($file, ['id', 'name', 'size']) + ['asset_id' => $assetId];
                    }
                }
                $records[] = ['type' => $table, 'data' => $data];
            }
        }
        foreach ($assets as $asset) {
            foreach ($asset as $record) {
                $records[] = $record;
            }
        }
        if (! $includeSecrets) {
            return $records;
        }
        foreach (ProjectSecret::query()->orderBy('project_id')->orderBy('environment')->orderBy('name')->get() as $secret) {
            $records[] = ['type' => 'project_secrets', 'data' => [
                'id' => $secret->id,
                'project_id' => $secret->project_id,
                'environment' => $secret->environment,
                'name' => $secret->name,
                'value' => $crypto->decrypt($secret->ciphertext),
                'revision' => $secret->revision,
                'created_at' => $secret->created_at?->toDateTimeString(),
                'updated_at' => $secret->updated_at?->toDateTimeString(),
            ]];
        }

        return $records;
    }

    /**
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    private function asset(string $id, string $path, string $prefix): array
    {
        if (! str_starts_with($path, $prefix) || ! Storage::disk('local')->exists($path)) {
            throw new RuntimeException('Workspace assets could not be read.');
        }
        $contents = Storage::disk('local')->get($path);
        $mime = mime_content_type(Storage::disk('local')->path($path));
        if (! is_string($contents) || ! is_string($mime)) {
            throw new RuntimeException('Workspace assets could not be read.');
        }
        $chunks = max(1, (int) ceil(strlen($contents) / (2 * 1024 * 1024)));
        $records = [];
        for ($chunk = 0; $chunk < $chunks; $chunk++) {
            $records[] = ['type' => 'asset', 'data' => [
                'id' => $id,
                'chunk' => $chunk,
                'chunks' => $chunks,
                'mime' => $mime,
                'content' => base64_encode(substr($contents, $chunk * 2 * 1024 * 1024, 2 * 1024 * 1024)),
            ]];
        }

        return $records;
    }
}
