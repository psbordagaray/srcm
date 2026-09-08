<?php

namespace App\Models;

use App\Enums\ProductDefinitionStatus;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductDefinition extends Model
{
    protected $table = 'catalog_product_definitions';

    protected $fillable = [
        'key',
        'name',
        'description',
        'status',
    ];

    protected static function booted(): void
    {
        static::updating(function (ProductDefinition $definition): void {
            if ($definition->isDirty('key')) {
                throw new DomainException(
                    'La semantic key de una definición de producto es inmutable.'
                );
            }

            $original = ProductDefinitionStatus::from(
                (string) $definition->getRawOriginal('status')
            );

            if ($original === ProductDefinitionStatus::Retired) {
                throw new DomainException(
                    'Una definición de producto retirada es inmutable.'
                );
            }

            if (! $definition->isDirty('status')) {
                return;
            }

            $next = $definition->status;

            $valid = match ($original) {
                ProductDefinitionStatus::Active =>
                    $next === ProductDefinitionStatus::Deprecated,
                ProductDefinitionStatus::Deprecated =>
                    $next === ProductDefinitionStatus::Retired,
                ProductDefinitionStatus::Retired => false,
            };

            if (! $valid) {
                throw new DomainException(
                    'Transición de estado inválida para la definición de producto.'
                );
            }
        });

        static::deleting(fn () => throw new DomainException(
            'Una definición de producto semántica no puede eliminarse físicamente.'
        ));
    }

    protected function casts(): array
    {
        return [
            'status' => ProductDefinitionStatus::class,
        ];
    }

    public function schemaVersions(): HasMany
    {
        return $this->hasMany(
            ProductSchemaVersion::class,
            'product_definition_id'
        );
    }
}
