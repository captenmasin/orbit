<?php

namespace App\Models;

use Database\Factories\ProjectDocumentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectDocument extends Model
{
    /** @use HasFactory<ProjectDocumentFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['title', 'body', 'position'];

    protected $appends = ['body_html'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'revision' => 'integer'];
    }

    public function getBodyHtmlAttribute(): string
    {
        return Task::renderDescription($this->body ?? '');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
