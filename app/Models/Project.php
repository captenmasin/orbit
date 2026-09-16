<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasFactory, HasUuids;

    public const STATUSES = ['Idea', 'Active', 'Paused', 'Maintenance', 'Archived'];

    protected $fillable = ['name', 'description', 'status', 'icon_type', 'icon_emoji', 'icon_path', 'archived_at', 'previous_status'];

    protected $hidden = ['icon_path'];

    protected $appends = ['icon_url'];

    protected static function booted(): void
    {
        static::created(function (Project $project) {
            $project->boardColumns()->createMany(array_map(
                fn ($name, $position) => ['name' => $name, 'position' => $position],
                BoardColumn::DEFAULT_NAMES, array_keys(BoardColumn::DEFAULT_NAMES),
            ));
        });
    }

    protected function casts(): array
    {
        return ['revision' => 'integer', 'archived_at' => 'datetime', 'last_commit_at' => 'datetime'];
    }

    public function getIconUrlAttribute(): ?string
    {
        return $this->icon_path ? route('projects.icon', $this, absolute: false) : null;
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
}
