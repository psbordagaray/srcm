<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Entity extends Model
{
    protected $fillable = [
        'uuid',
        'name',
        'entity_type_id',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function entityType(): BelongsTo
    {
        return $this->belongsTo(EntityType::class);
    }

    public function identifiers(): HasMany
    {
        return $this->hasMany(Identifier::class);
    }

    public function assertions(): HasMany
    {
        return $this->hasMany(Assertion::class);
    }
}