<?php

namespace App\Models;

use App\Domain\Knowledge\IdentifierIntegrity;
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

    protected static function booted(): void
    {
        static::saving(function (Identifier $identifier): void {
            $integrity = app(IdentifierIntegrity::class);

            $identifier->value = trim(
                (string) $identifier->value
            );

            $identifier->normalized_value = $integrity->normalize(
                $identifier->value
            );

            $integrity->assertCanPersist($identifier);
        });
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function identifierType(): BelongsTo
    {
        return $this->belongsTo(IdentifierType::class);
    }
}
