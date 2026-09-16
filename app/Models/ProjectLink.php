<?php

namespace App\Models;

use Database\Factories\ProjectLinkFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectLink extends Model
{
    /** @use HasFactory<ProjectLinkFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['id', 'label', 'url', 'category', 'icon', 'position'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
