<?php

namespace App;

use App\Models\BoardColumn;
use App\Models\Project;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class WorkspacePreferences
{
    /** @return list<string> */
    public static function validatedProjectStatuses(array $statuses): array
    {
        $statuses = array_map(fn (mixed $name): mixed => is_string($name) ? trim($name) : $name, $statuses);

        return Validator::make(['statuses' => $statuses], [
            'statuses' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'statuses.*' => ['required', 'string', 'max:100', 'distinct:ignore_case', 'regex:/\A[^\x00-\x1F\x7F]+\z/u', 'not_regex:/\AArchived\z/iu'],
        ], ['statuses.*.not_regex' => 'Archived is reserved for archiving projects.'])->validate()['statuses'];
    }

    public static function defaultBoardColumns(): array
    {
        return array_map(fn (string $name): array => ['name' => $name, 'color' => null], BoardColumn::DEFAULT_NAMES);
    }

    public static function validatedBoardColumns(array $columns): array
    {
        $data = Validator::make(['columns' => $columns], [
            'columns' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'columns.*' => ['required', 'array:name,color'],
            'columns.*.name' => ['required', 'string', 'max:100', 'regex:/\S/u'],
            'columns.*.color' => ['nullable', 'string', Rule::in(BoardColumn::COLORS)],
        ])->validate();

        return array_map(fn (array $column): array => ['name' => trim($column['name']), 'color' => $column['color'] ?? null], $data['columns']);
    }

    public static function defaults(): array
    {
        return [
            'general' => ['startup_destination' => 'dashboard'],
            'appearance' => ['theme' => 'system', 'reduce_motion' => 'system'],
            'security' => ['lock_minutes' => 15, 'clipboard_seconds' => 30, 'generation' => 0],
            'project_defaults' => ['columns' => self::defaultBoardColumns()],
            'project_statuses' => [
                'names' => array_values(array_diff(Project::STATUSES, ['Archived'])),
                'colors' => ['Idea' => 'purple', 'In Progress' => 'blue', 'Live' => 'green', 'Paused' => 'amber', 'Maintenance' => 'orange'],
            ],
            'tools' => ['paths' => array_fill_keys(['php', 'node', 'composer', 'npm', 'pnpm', 'yarn'], null)],
            'backups' => ['folder' => null, 'last_export_at' => null, 'last_export_path' => null],
            'startup' => ['last_project_id' => null],
            'ai' => ['provider' => null, 'model' => null, 'credential' => null],
        ];
    }

    /** @return array{revision: int, values: array<string, array>} */
    public function snapshot(): array
    {
        $record = DB::table('workspace_preferences')->where('id', 1)->first();
        $values = self::defaults();
        foreach (json_decode($record?->values ?? '{}', true, flags: JSON_THROW_ON_ERROR) as $section => $fields) {
            if (isset($values[$section]) && is_array($fields)) {
                $values[$section] = array_replace($values[$section], $fields);
            }
        }
        $values['startup']['last_project_id'] = $record?->last_project_id;
        $values['project_statuses']['colors'] = Arr::only($values['project_statuses']['colors'], $values['project_statuses']['names']);

        return ['revision' => (int) ($record?->revision ?? 1), 'values' => $values];
    }

    public function get(?string $key = null): mixed
    {
        return $key === null ? $this->snapshot()['values'] : Arr::get($this->snapshot()['values'], $key);
    }

    public function update(string $section, array $values, int $expectedRevision): array
    {
        return $this->write([$section => $values], $expectedRevision);
    }

    public function lastProjectId(): ?string
    {
        return DB::table('workspace_preferences')->where('id', 1)->value('last_project_id');
    }

    public function rememberProject(?string $id): void
    {
        DB::table('workspace_preferences')->insertOrIgnore(['id' => 1, 'revision' => 1, 'values' => '{}']);
        DB::table('workspace_preferences')->where('id', 1)->update(['last_project_id' => $id]);
    }

    public function merge(array $values): array
    {
        if (isset($values['startup'])) {
            $this->rememberProject($values['startup']['last_project_id'] ?? null);
            unset($values['startup']);
        }
        if ($values === []) {
            return $this->snapshot();
        }

        return $this->write($values, null);
    }

    private function write(array $changes, ?int $expectedRevision): array
    {
        return DB::transaction(function () use ($changes, $expectedRevision): array {
            DB::table('workspace_preferences')->insertOrIgnore(['id' => 1, 'revision' => 1, 'values' => '{}']);
            $snapshot = $this->snapshot();
            if ($expectedRevision !== null && $snapshot['revision'] !== $expectedRevision) {
                throw new ConflictHttpException('Settings changed in another window. Reload before saving.');
            }
            foreach ($changes as $section => $fields) {
                abort_unless(isset($snapshot['values'][$section]) && is_array($fields), 422);
                $snapshot['values'][$section] = array_replace($snapshot['values'][$section], $fields);
            }
            $storedValues = $snapshot['values'];
            unset($storedValues['startup']);
            if (! DB::table('workspace_preferences')->where('id', 1)->where('revision', $snapshot['revision'])->update([
                'values' => json_encode($storedValues, JSON_THROW_ON_ERROR), 'revision' => $snapshot['revision'] + 1,
            ])) {
                throw new ConflictHttpException('Settings changed. Reload before saving.');
            }
            $snapshot['revision']++;

            return $snapshot;
        });
    }
}
