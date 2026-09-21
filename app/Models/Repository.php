<?php

namespace App\Models;

use Database\Factories\RepositoryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Repository extends Model
{
    /** @use HasFactory<RepositoryFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['id', 'name', 'remote_url'];

    protected $appends = ['web_url'];

    protected function casts(): array
    {
        return ['provider_revision' => 'integer', 'remote_commit_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (Repository $repository): void {
            if ($repository->isDirty('remote_url')) {
                $repository->disconnectProvider();
            }
        });
    }

    public function providerConnection(): BelongsTo
    {
        return $this->belongsTo(ProviderConnection::class);
    }

    public function providerSnapshots(): HasMany
    {
        return $this->hasMany(ProviderSnapshot::class)->orderBy('resource');
    }

    public function disconnectProvider(): void
    {
        $this->providerSnapshots()->delete();
        $this->forceFill(['provider_connection_id' => null, 'provider_repository_id' => null, 'provider_name' => null,
            'default_branch' => null, 'provider_url' => null, 'remote_commit_at' => null,
            'provider_revision' => $this->provider_revision + 1]);
    }

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

    public static function nameFromUrl(string $url): string
    {
        $web = (new self(['remote_url' => $url]))->web_url;

        return mb_substr(rawurldecode(basename(rtrim(parse_url($web, PHP_URL_PATH) ?? '', '/'))) ?: (parse_url($web, PHP_URL_HOST) ?? 'Repository'), 0, 255);
    }
}
