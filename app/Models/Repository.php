<?php

namespace App\Models;

use Database\Factories\RepositoryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Repository extends Model
{
    /** @use HasFactory<RepositoryFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['id', 'name', 'remote_url'];

    protected $appends = ['web_url'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function getWebUrlAttribute(): string
    {
        $url = preg_replace('~^[\w.-]+@([^:]+):~', 'https://$1/', $this->remote_url);
        $url = preg_replace('~^ssh://(?:[^@/]+@)?~', 'https://', $url);

        return preg_replace('~\.git/?$~', '', $url);
    }
}
