<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Compatibility extends Model
{
    protected $fillable = [
        'left_entity_id',
        'right_entity_id',
        'relationship_type',
        'confidence',
        'source',
        'evidence',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function leftEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'left_entity_id');
    }

    public function rightEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'right_entity_id');
    }
}