<?php

namespace App\Models;

use Database\Factories\ProjectFolderFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class ProjectFolder extends Model
{
    /** @use HasFactory<ProjectFolderFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['id', 'path', 'repository_id', 'git_state', 'branch', 'last_commit_hash', 'last_commit_at', 'scanned_at', 'git_root', 'git_remote', 'commit_subject'];

    protected $hidden = ['scan_token', 'scan_job_id'];

    protected $appends = ['availability'];

    protected function casts(): array
    {
        return ['last_commit_at' => 'datetime', 'scanned_at' => 'datetime', 'scan_attempted_at' => 'datetime', 'scan_started_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::created(fn (ProjectFolder $folder) => $folder->packageRoots()->create(['relative_path' => '.']));
        static::updating(function (ProjectFolder $folder) {
            if ($folder->isDirty('path')) {
                $folder->scan_token = null;
                $folder->scan_state = 'Not scanned';
                $folder->scan_error = null;
                $folder->scan_job_id = null;
                $folder->scan_attempted_at = null;
                $folder->scan_started_at = null;
            }
        });
        static::updated(function (ProjectFolder $folder) {
            if ($folder->wasChanged('path')) {
                $folder->packageRoots()->update([
                    'scan_token' => null, 'scan_state' => 'Not scanned', 'scan_error' => null,
                    'snapshot' => null, 'scanned_at' => null,
                    'scan_job_id' => null, 'scan_attempted_at' => null, 'scan_started_at' => null,
                    'revision' => DB::raw('revision + 1'),
                ]);
            }
        });
    }

    public function packageRoots(): HasMany
    {
        return $this->hasMany(PackageRoot::class)->orderBy('relative_path')->orderBy('id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function getAvailabilityAttribute(): string
    {
        return ! is_dir($this->path) ? 'Missing folder' : (is_readable($this->path) ? 'Available' : 'Permission denied');
    }
}
