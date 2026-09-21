<?php

namespace App\Models;

use App\Actions\RecordAccessEvent;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProviderConnection extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['encrypted_token'];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'verified_at' => 'datetime', 'retry_at' => 'datetime'];
    }

    public function repositories(): HasMany
    {
        return $this->hasMany(Repository::class);
    }

    public function recordAccess(string $operation, string $result): void
    {
        app(RecordAccessEvent::class)->handle($operation, $result, connectionId: $this->id);
    }
}
