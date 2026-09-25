<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Project extends Model
{
    use HasFactory, HasUuids;

    public const STATUSES = ['Idea', 'In Progress', 'Live', 'Paused', 'Maintenance', 'Archived'];

    public const PREVIEWABLE_ASSET_MIME_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/avif', 'image/bmp', 'image/x-icon', 'image/vnd.microsoft.icon', 'image/svg+xml'];

    protected $fillable = ['name', 'description', 'notes', 'scratchpad', 'status', 'icon_type', 'icon_emoji', 'icon_path', 'archived_at', 'previous_status', 'reviewed_at'];

    protected $hidden = ['icon_path', 'asset_files', 'scratchpad'];

    protected $appends = ['icon_url', 'assets'];

    protected static function booted(): void
    {
        static::creating(function (Project $project): void {
            $project->position = (static::max('position') ?? -1) + 1;
        });
        static::created(function (Project $project) {
            $project->boardColumns()->createMany(array_map(
                fn ($name, $position) => ['name' => $name, 'position' => $position],
                BoardColumn::DEFAULT_NAMES, array_keys(BoardColumn::DEFAULT_NAMES),
            ));
        });
    }

    protected function casts(): array
    {
        return ['revision' => 'integer', 'position' => 'integer', 'asset_files' => 'array', 'asset_folders' => 'array', 'archived_at' => 'datetime', 'last_commit_at' => 'datetime', 'reviewed_at' => 'date:Y-m-d'];
    }

    /** @return list<array{id: string, name: string, size: int, folder_id: ?string, mime_type: ?string, url: string, preview_url: ?string}> */
    public function getAssetsAttribute(): array
    {
        return array_map(function (array $file): array {
            $mime = self::assetMimeType($file['path']);

            return [
                'id' => $file['id'], 'name' => $file['name'], 'size' => $file['size'], 'mime_type' => $mime,
                'folder_id' => $file['folder_id'] ?? null,
                'url' => route('projects.assets.download', [$this, $file['id']], absolute: false),
                'preview_url' => in_array($mime, self::PREVIEWABLE_ASSET_MIME_TYPES, true) ? route('projects.assets.preview', [$this, $file['id']], absolute: false) : null,
            ];
        }, $this->asset_files ?? []);
    }

    public static function assetMimeType(string $path): ?string
    {
        $disk = Storage::disk('local');

        return $disk->exists($path) ? ($disk->mimeType($path) ?: null) : null;
    }

    public function getIconUrlAttribute(): ?string
    {
        return $this->icon_path ? route('projects.icon', $this, absolute: false) : null;
    }

    public function scopeWithLatestCommit(Builder $query): void
    {
        $query->addSelect('projects.*')->selectRaw('(SELECT MAX(commit_at) FROM (SELECT last_commit_at AS commit_at FROM project_folders WHERE project_id = projects.id UNION ALL SELECT remote_commit_at AS commit_at FROM repositories WHERE project_id = projects.id)) AS last_commit_at');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->orderBy('name');
    }

    public function repositories(): HasMany
    {
        return $this->hasMany(Repository::class)->orderBy('name')->orderBy('id');
    }

    public function folders(): HasMany
    {
        return $this->hasMany(ProjectFolder::class)->orderBy('path')->orderBy('id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(ProjectLink::class)->orderBy('position')->orderBy('id');
    }

    public function boardColumns(): HasMany
    {
        return $this->hasMany(BoardColumn::class)->orderBy('position')->orderBy('id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ProjectDocument::class)->orderBy('position')->orderBy('id');
    }

    public function secrets(): HasMany
    {
        return $this->hasMany(ProjectSecret::class)->orderBy('environment')->orderBy('name');
    }
}
