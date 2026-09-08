<?php

namespace App\Models;

use App\Domain\Catalog\SemanticKey;
use App\Enums\MeasurementDimensionStatus;
use App\Enums\MeasurementUnitStatus;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MeasurementDimension extends Model
{
    protected $table = 'catalog_measurement_dimensions';

    protected $fillable = [
        'key',
        'name',
        'description',
        'status',
    ];

    protected static function booted(): void
    {
        static::creating(function (MeasurementDimension $dimension): void {
            SemanticKey::assertValid((string) $dimension->key);
            static::assertName((string) $dimension->name);
        });

        static::updating(function (MeasurementDimension $dimension): void {
            if ($dimension->isDirty('key')) {
                throw new DomainException(
                    'La semantic key de una dimensión de medida es inmutable.'
                );
            }

            if ($dimension->isDirty('name')) {
                static::assertName((string) $dimension->name);
            }

            $original = MeasurementDimensionStatus::from(
                (string) $dimension->getRawOriginal('status')
            );

            if ($original === MeasurementDimensionStatus::Retired) {
                throw new DomainException(
                    'Una dimensión de medida retirada es inmutable.'
                );
            }

            if (! $dimension->isDirty('status')) {
                return;
            }

            $next = $dimension->status;

            $valid = match ($original) {
                MeasurementDimensionStatus::Active =>
                    $next === MeasurementDimensionStatus::Deprecated,
                MeasurementDimensionStatus::Deprecated =>
                    $next === MeasurementDimensionStatus::Retired,
                MeasurementDimensionStatus::Retired => false,
            };

            if (! $valid) {
                throw new DomainException(
                    'Transición de estado inválida para la dimensión de medida.'
                );
            }

            if (
                $next === MeasurementDimensionStatus::Deprecated
                && MeasurementUnit::query()
                    ->where(
                        'measurement_dimension_id',
                        $dimension->getKey()
                    )
                    ->where(
                        'status',
                        MeasurementUnitStatus::Active->value
                    )
                    ->exists()
            ) {
                throw new DomainException(
                    'La dimensión no puede deprecarse mientras posea unidades activas.'
                );
            }

            if (
                $next === MeasurementDimensionStatus::Retired
                && MeasurementUnit::query()
                    ->where(
                        'measurement_dimension_id',
                        $dimension->getKey()
                    )
                    ->where(
                        'status',
                        '!=',
                        MeasurementUnitStatus::Retired->value
                    )
                    ->exists()
            ) {
                throw new DomainException(
                    'La dimensión no puede retirarse hasta retirar todas sus unidades.'
                );
            }
        });

        static::deleting(fn () => throw new DomainException(
            'Una dimensión de medida semántica no puede eliminarse físicamente.'
        ));
    }

    protected function casts(): array
    {
        return [
            'status' => MeasurementDimensionStatus::class,
        ];
    }

    public function units(): HasMany
    {
        return $this->hasMany(
            MeasurementUnit::class,
            'measurement_dimension_id'
        );
    }

    private static function assertName(string $name): void
    {
        if (
            trim($name) === ''
            || mb_strlen($name) > 160
        ) {
            throw new DomainException(
                'El nombre de la dimensión de medida es obligatorio y admite hasta 160 caracteres.'
            );
        }
    }
}
