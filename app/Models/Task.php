<?php

namespace App\Models;

use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\DefaultAttributes\DefaultAttributesExtension;

class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['title', 'description', 'position'];

    protected $hidden = ['attachment_files'];

    protected $appends = ['description_html', 'attachments'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'attachment_files' => 'array'];
    }

    public static function renderDescription(string $description): string
    {
        return Str::markdown($description, [
            'html_input' => 'strip', 'allow_unsafe_links' => false,
            'max_nesting_level' => 100, 'max_delimiters_per_line' => 1000,
            'default_attributes' => ['attributes' => [Link::class => ['target' => '_blank', 'rel' => 'noopener noreferrer']]],
        ], [new DefaultAttributesExtension]);
    }

    public function getDescriptionHtmlAttribute(): string
    {
        return self::renderDescription($this->description ?? '');
    }

    /** @return array<int, array{id: string, name: string, size: int}> */
    public function getAttachmentsAttribute(): array
    {
        return array_map(fn ($file) => [
            'id' => $file['id'], 'name' => $file['name'], 'size' => $file['size'],
        ], $this->attachment_files ?? []);
    }

    public function column(): BelongsTo
    {
        return $this->belongsTo(BoardColumn::class, 'board_column_id');
    }
}
