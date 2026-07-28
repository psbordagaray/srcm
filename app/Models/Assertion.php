<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Assertion extends Model
{
    protected $fillable = [
        'entity_id',
        'attribute',
        'value_type',
        'value_text',
        'related_entity_id',
        'value_json',
        'confidence',
        'status',
        'source',
        'evidence',
        'created_by',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'value_json' => 'array',
            'confidence' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function relatedEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'related_entity_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}