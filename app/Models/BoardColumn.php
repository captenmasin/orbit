<?php

namespace App\Models;

use Database\Factories\BoardColumnFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BoardColumn extends Model
{
    /** @use HasFactory<BoardColumnFactory> */
    use HasFactory, HasUuids;

    public const DEFAULT_NAMES = ['Backlog', 'To Do', 'In Progress', 'Done'];

    public const COLORS = ['gray', 'blue', 'amber', 'green', 'purple', 'pink', 'red'];

    protected $fillable = ['name', 'position', 'color'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class)->orderBy('position')->orderBy('id');
    }
}
