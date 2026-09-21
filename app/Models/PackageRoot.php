<?php

namespace App\Models;

use Database\Factories\PackageRootFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class PackageRoot extends Model
{
    /** @use HasFactory<PackageRootFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['relative_path', 'executable_overrides'];

    protected $hidden = ['scan_token', 'scan_job_id'];

    protected function casts(): array
    {
        return [
            'executable_overrides' => 'array', 'snapshot' => 'array', 'outdated' => 'array', 'revision' => 'integer',
            'scanned_at' => 'datetime', 'scan_attempted_at' => 'datetime', 'scan_started_at' => 'datetime',
        ];
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(ProjectFolder::class, 'project_folder_id');
    }

    public function resolvePath(): string
    {
        $folder = realpath($this->folder->path);
        $path = $folder ? realpath($folder.'/'.$this->relative_path) : false;
        if (! $path || ! is_dir($path)) {
            throw new RuntimeException('Missing folder');
        }
        if (! is_readable($path)) {
            throw new RuntimeException('Permission denied');
        }
        if ($path !== $folder && ! str_starts_with($path, rtrim($folder, '/').'/')) {
            throw new RuntimeException('Root is outside its linked folder');
        }

        return $path;
    }
}
