<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Entity extends Model
{
    protected $fillable = [
        'uuid',
        'entity_type_id',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function entityType(): BelongsTo
    {
        return $this->belongsTo(EntityType::class);
    }

    public function identifiers(): HasMany
    {
        return $this->hasMany(Identifier::class);
    }
}
