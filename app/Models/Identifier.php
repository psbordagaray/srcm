<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Identifier extends Model
{
    protected $fillable = [
        'entity_id',
        'identifier_type_id',
        'value',
        'is_primary',
        'active',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'active' => 'boolean',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function identifierType(): BelongsTo
    {
        return $this->belongsTo(IdentifierType::class);
    }
}
