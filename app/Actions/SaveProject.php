<?php

namespace App\Actions;

use App\Models\Project;
use App\Models\ProjectFolder;
use App\Models\ProjectLink;
use App\Models\Repository;
use App\Models\Tag;
use App\Rules\ProjectUrl;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

class SaveProject
{
    public function handle(array $input, ?string $id = null): Project
    {
        $current = $id ? Project::with(['folders', 'repositories'])->findOrFail($id) : null;
        $emoji = ['nullable', 'string', 'max:32', 'regex:/\A(?=[\s\S]*[\p{So}\x{20E3}])\X\z/u'];
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:255', 'regex:/\S/u'],
            'description' => ['nullable', 'string', 'max:10000'],
            'status' => ['required', Rule::in(Project::STATUSES)],
            'revision' => [$id ? 'required' : 'prohibited', 'integer', 'min:1'],
            'icon_type' => ['sometimes', Rule::in(['initials', 'emoji', 'image'])],
            'icon_emoji' => $emoji,
            'icon_file' => ['nullable', 'image', 'mimes:png,jpg,jpeg,gif,webp', 'max:2048', 'dimensions:max_width=2048,max_height=2048'],
            'tags' => ['sometimes', 'array', 'max:100'],
            'tags.*' => ['required', 'string', 'max:50', 'regex:/\S/u'],
            'repositories' => ['sometimes', 'array', 'max:100'],
            'repositories.*.id' => ['required', 'uuid', 'distinct'],
            'repositories.*.name' => ['required', 'string', 'max:255', 'regex:/\S/u'],
            'repositories.*.remote_url' => ['required', 'string', 'max:2048', 'distinct', new ProjectUrl(repository: true)],
            'folders' => ['sometimes', 'array', 'max:100'],
            'folders.*.id' => ['required', 'uuid', 'distinct'],
            'folders.*.path' => ['required', 'string', 'max:4096', 'distinct'],
            'folders.*.repository_id' => ['nullable', 'uuid'],
            'links' => ['sometimes', 'array', 'max:200'],
            'links.*.id' => ['required', 'uuid', 'distinct'],
            'links.*.label' => ['required', 'string', 'max:255', 'regex:/\S/u'],
            'links.*.url' => ['required', 'string', 'max:2048', new ProjectUrl],
            'links.*.category' => ['nullable', 'string', 'max:50'],
            'links.*.icon' => $emoji,
        ])->validate();

        $revision = $data['revision'] ?? null;
        if ($current && $current->revision !== (int) $revision) {
            throw new ConflictHttpException('This project changed. Reload it before saving again.');
        }
        $type = $data['icon_type'] ?? $current?->icon_type ?? 'initials';
        $data['icon_emoji'] ??= $current?->icon_emoji;
        if ($type === 'emoji' && empty($data['icon_emoji'])) {
            throw ValidationException::withMessages(['icon_emoji' => 'Choose an emoji.']);
        }
        if ($type === 'image' && empty($data['icon_file']) && ! $current?->icon_path) {
            throw ValidationException::withMessages(['icon_file' => 'Choose a PNG, JPEG, GIF or WebP image.']);
        }
        foreach (['repositories' => Repository::class, 'folders' => ProjectFolder::class, 'links' => ProjectLink::class] as $key => $model) {
            if (isset($data[$key]) && $model::whereIn('id', array_column($data[$key], 'id'))
                ->when($id, fn ($query) => $query->where('project_id', '!=', $id))->exists()) {
                throw ValidationException::withMessages([$key => 'These records belong to another project. Reload before saving.']);
            }
        }
        $repositoryIds = isset($data['repositories']) ? array_column($data['repositories'], 'id') : ($current?->repositories->modelKeys() ?? []);
        $paths = [];
        $folders = $data['folders'] ?? [];
        foreach ($folders as $index => &$folder) {
            if (! empty($folder['repository_id']) && ! in_array($folder['repository_id'], $repositoryIds, true)) {
                throw ValidationException::withMessages(["folders.$index.repository_id" => 'Choose a repository belonging to this project.']);
            }
            $existing = $current?->folders->firstWhere('id', $folder['id']);
            if (! $existing || $existing->path !== $folder['path']) {
                try {
                    $scan = app(InspectFolder::class)->handle($folder['path']);
                } catch (ValidationException) {
                    throw ValidationException::withMessages(["folders.$index.path" => 'Choose an existing folder you can read.']);
                }
                $folder = [...$folder, ...Arr::only($scan, ['path', 'git_state', 'branch', 'last_commit_hash', 'last_commit_at', 'scanned_at', 'git_root', 'git_remote', 'commit_subject'])];
            }
            if (in_array($folder['path'], $paths, true)) {
                throw ValidationException::withMessages(["folders.$index.path" => 'This folder is already linked.']);
            }
            $paths[] = $folder['path'];
        }
        unset($folder);
        if (isset($data['folders'])) {
            $data['folders'] = $folders;
        }

        $newIcon = null;
        $oldIcon = $current?->icon_path;
        try {
            if ($type === 'image' && ! empty($data['icon_file'])) {
                $newIcon = $data['icon_file']->store('project-icons', 'local');
                if (! $newIcon) {
                    throw ValidationException::withMessages(['icon_file' => 'The image could not be stored. Try again.']);
                }
            }
            $project = DB::transaction(function () use ($data, $id, $revision, $current, $type, $newIcon, $oldIcon) {
                $attributes = [
                    'name' => trim($data['name']), 'description' => $data['description'] ?? null, 'status' => $data['status'],
                    'icon_type' => $type, 'icon_emoji' => $type === 'emoji' ? $data['icon_emoji'] : null,
                    'icon_path' => $type === 'image' ? ($newIcon ?: $oldIcon) : null,
                    'archived_at' => $data['status'] === 'Archived' ? ($current?->archived_at ?? now()) : null,
                    'previous_status' => $data['status'] === 'Archived' ? ($current?->status === 'Archived' ? $current->previous_status : $current?->status) : null,
                ];
                if ($id) {
                    if (! Project::whereKey($id)->where('revision', $revision)->update([...$attributes, 'revision' => DB::raw('revision + 1')])) {
                        throw new ConflictHttpException('This project changed. Reload it before saving again.');
                    }
                    $project = Project::findOrFail($id);
                } else {
                    $project = Project::create($attributes)->refresh();
                }
                if (isset($data['tags'])) {
                    $tags = collect($data['tags'])->map(fn ($tag) => mb_strtolower(trim($tag)))->unique();
                    $project->tags()->sync($tags->map(fn ($name) => Tag::firstOrCreate(['name' => $name])->id)->all());
                }
                // Save repositories before checkouts, and detach checkouts before removing a repository.
                foreach ($data['repositories'] ?? [] as $repository) {
                    $project->repositories()->updateOrCreate(['id' => $repository['id']], Arr::only($repository, ['name', 'remote_url']));
                }
                if (isset($data['folders'])) {
                    $project->folders()->whereNotIn('id', array_column($data['folders'], 'id'))->delete();
                    foreach ($data['folders'] as $folder) {
                        $project->folders()->updateOrCreate(['id' => $folder['id']], Arr::except($folder, ['id']));
                    }
                }
                if (isset($data['repositories'])) {
                    $removed = $project->repositories()->whereNotIn('id', array_column($data['repositories'], 'id'))->pluck('id');
                    $project->folders()->whereIn('repository_id', $removed)->update(['repository_id' => null]);
                    $project->repositories()->whereIn('id', $removed)->delete();
                }
                if (isset($data['links'])) {
                    $project->links()->whereNotIn('id', array_column($data['links'], 'id'))->delete();
                    foreach ($data['links'] as $position => $link) {
                        $project->links()->updateOrCreate(['id' => $link['id']], [...Arr::except($link, ['id']), 'position' => $position]);
                    }
                }

                return $project;
            });
        } catch (Throwable $exception) {
            if ($newIcon) {
                Storage::disk('local')->delete($newIcon);
            }
            throw $exception;
        }
        if ($oldIcon && $oldIcon !== $project->icon_path) {
            Storage::disk('local')->delete($oldIcon);
        }

        return $project;
    }
}
