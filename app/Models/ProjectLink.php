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

    protected $fillable = ['id', 'label', 'url', 'category', 'description', 'icon', 'position', 'important'];

    protected $appends = ['description_html'];

    protected function casts(): array
    {
        return ['important' => 'boolean'];
    }

    public function getDescriptionHtmlAttribute(): string
    {
        return Task::renderDescription($this->description ?? '');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
