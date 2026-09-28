<?php

namespace App\Actions;

use App\Models\Project;
use App\WorkspacePreferences;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class SaveProjectStatuses
{
    public function __construct(private WorkspacePreferences $preferences) {}

    public function handle(array $input): void
    {
        $data = Validator::make($input, [
            'revision' => ['required', 'integer', 'min:1'],
            'statuses' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'statuses.*' => ['required', 'array:original,name,color'],
            'statuses.*.original' => ['present', 'nullable', 'string', 'distinct:strict'],
            'statuses.*.name' => ['required', 'string'],
            'statuses.*.color' => ['sometimes', 'required', 'string', Rule::in(Project::STATUS_COLORS)],
            'replacements' => ['sometimes', 'array', 'list', 'max:100'],
            'replacements.*' => ['required', 'array:original,replacement'],
            'replacements.*.original' => ['required', 'string', 'distinct:strict'],
            'replacements.*.replacement' => ['required', 'string'],
        ])->validate();

        DB::transaction(function () use ($data): void {
            $snapshot = $this->preferences->snapshot();
            if ($snapshot['revision'] !== (int) $data['revision']) {
                throw new ConflictHttpException('Settings changed in another window. Reload before saving.');
            }
            $current = $snapshot['values']['project_statuses']['names'];
            $names = WorkspacePreferences::validatedProjectStatuses(array_column($data['statuses'], 'name'));
            $removed = array_diff($current, array_column($data['statuses'], 'original'));
            Validator::make($data, [
                'statuses.*.original' => ['nullable', Rule::in($current)],
                'replacements.*.original' => [Rule::in($removed)],
                'replacements.*.replacement' => [Rule::in($names)],
            ])->validate();

            $mapping = array_column($data['replacements'] ?? [], 'replacement', 'original');
            $colors = [];
            foreach ($data['statuses'] as $index => $status) {
                $colors[$names[$index]] = $status['color'] ?? $snapshot['values']['project_statuses']['colors'][$status['original']] ?? 'gray';
                if ($status['original'] !== null) {
                    $mapping[$status['original']] = $names[$index];
                }
            }
            $changed = array_values(array_filter($current, fn (string $name): bool => ($mapping[$name] ?? null) !== $name));
            $this->preferences->update('project_statuses', ['names' => $names, 'colors' => $colors], (int) $data['revision']);
            $projects = Project::whereIn('status', $changed)->orWhereIn('previous_status', $changed)->get(['id', 'status', 'previous_status', 'revision']);
            foreach ($projects as $project) {
                foreach ([$project->status, $project->previous_status] as $status) {
                    if (in_array($status, $removed, true) && ! isset($mapping[$status])) {
                        throw ValidationException::withMessages(['replacements' => 'Choose a replacement for “'.$status.'” before removing it.']);
                    }
                }
                $project->forceFill([
                    'status' => $mapping[$project->status] ?? $project->status,
                    'previous_status' => $mapping[$project->previous_status] ?? $project->previous_status,
                    'revision' => $project->revision + 1,
                ])->save();
            }
        });
    }
}
