<?php

namespace App\Models;

use Database\Factories\ProjectSecretFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectSecret extends Model
{
    /** @use HasFactory<ProjectSecretFactory> */
    use HasFactory, HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['ciphertext'];

    protected function casts(): array
    {
        return ['revision' => 'integer'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
