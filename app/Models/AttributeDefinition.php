<?php

namespace App\Models;

use App\Enums\AttributeDefinitionStatus;
use DomainException;
use Illuminate\Database\Eloquent\Model;

class AttributeDefinition extends Model
{
    protected $table = 'catalog_attribute_definitions';

    protected $fillable = [
        'key',
        'name',
        'description',
        'status',
    ];

    protected static function booted(): void
    {
        static::updating(function (AttributeDefinition $attribute): void {
            if ($attribute->isDirty('key')) {
                throw new DomainException(
                    'La semantic key de una definición de atributo es inmutable.'
                );
            }

            $original = AttributeDefinitionStatus::from(
                (string) $attribute->getRawOriginal('status')
            );

            if ($original === AttributeDefinitionStatus::Retired) {
                throw new DomainException(
                    'Una definición de atributo retirada es inmutable.'
                );
            }

            if (! $attribute->isDirty('status')) {
                return;
            }

            $next = $attribute->status;

            $valid = match ($original) {
                AttributeDefinitionStatus::Active =>
                    $next === AttributeDefinitionStatus::Deprecated,
                AttributeDefinitionStatus::Deprecated =>
                    $next === AttributeDefinitionStatus::Retired,
                AttributeDefinitionStatus::Retired => false,
            };

            if (! $valid) {
                throw new DomainException(
                    'Transición de estado inválida para la definición de atributo.'
                );
            }
        });

        static::deleting(fn () => throw new DomainException(
            'Una definición de atributo semántica no puede eliminarse físicamente.'
        ));
    }

    protected function casts(): array
    {
        return [
            'status' => AttributeDefinitionStatus::class,
        ];
    }
}
