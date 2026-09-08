<?php

namespace App\Models;

use App\Enums\ProductSchemaStatus;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSchemaVersion extends Model
{
    protected $table = 'catalog_product_schema_versions';

    protected $fillable = [
        'product_definition_id',
        'version',
        'status',
        'change_summary',
        'published_at',
        'deprecated_at',
        'retired_at',
    ];

    protected static function booted(): void
    {
        static::updating(function (ProductSchemaVersion $schema): void {
            if ($schema->isDirty([
                'product_definition_id',
                'version',
            ])) {
                throw new DomainException(
                    'La identidad de una versión de schema es inmutable.'
                );
            }

            $original = ProductSchemaStatus::from(
                (string) $schema->getRawOriginal('status')
            );

            if ($original === ProductSchemaStatus::Retired) {
                throw new DomainException(
                    'Una versión de schema retirada es inmutable.'
                );
            }

            $dirty = array_keys($schema->getDirty());
            $dirty = array_values(array_diff($dirty, ['updated_at']));

            if ($original === ProductSchemaStatus::Published) {
                $forbidden = array_diff(
                    $dirty,
                    ['status', 'deprecated_at']
                );

                if ($forbidden !== []) {
                    throw new DomainException(
                        'Una versión de schema publicada es semánticamente inmutable.'
                    );
                }
            }

            if ($original === ProductSchemaStatus::Deprecated) {
                $forbidden = array_diff(
                    $dirty,
                    ['status', 'retired_at']
                );

                if ($forbidden !== []) {
                    throw new DomainException(
                        'Una versión de schema deprecada sólo puede retirarse.'
                    );
                }
            }

            if (! $schema->isDirty('status')) {
                return;
            }

            $next = $schema->status;

            $valid = match ($original) {
                ProductSchemaStatus::Draft => in_array(
                    $next,
                    [
                        ProductSchemaStatus::Published,
                        ProductSchemaStatus::Retired,
                    ],
                    true
                ),
                ProductSchemaStatus::Published =>
                    $next === ProductSchemaStatus::Deprecated,
                ProductSchemaStatus::Deprecated =>
                    $next === ProductSchemaStatus::Retired,
                ProductSchemaStatus::Retired => false,
            };

            if (! $valid) {
                throw new DomainException(
                    'Transición de estado inválida para la versión de schema.'
                );
            }

            if (
                $next === ProductSchemaStatus::Published
                && $schema->published_at === null
            ) {
                throw new DomainException(
                    'Publicar una versión de schema requiere published_at.'
                );
            }

            if (
                $next === ProductSchemaStatus::Deprecated
                && $schema->deprecated_at === null
            ) {
                throw new DomainException(
                    'Deprecar una versión de schema requiere deprecated_at.'
                );
            }

            if (
                $next === ProductSchemaStatus::Retired
                && $schema->retired_at === null
            ) {
                throw new DomainException(
                    'Retirar una versión de schema requiere retired_at.'
                );
            }
        });

        static::deleting(fn () => throw new DomainException(
            'Una versión de schema semántica no puede eliminarse físicamente.'
        ));
    }

    protected function casts(): array
    {
        return [
            'status' => ProductSchemaStatus::class,
            'version' => 'integer',
            'published_at' => 'immutable_datetime',
            'deprecated_at' => 'immutable_datetime',
            'retired_at' => 'immutable_datetime',
        ];
    }

    public function productDefinition(): BelongsTo
    {
        return $this->belongsTo(
            ProductDefinition::class,
            'product_definition_id'
        );
    }
}
