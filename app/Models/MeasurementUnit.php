<?php

namespace App\Models;

use App\Domain\Catalog\SemanticKey;
use App\Enums\MeasurementDimensionStatus;
use App\Enums\MeasurementUnitStatus;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MeasurementUnit extends Model
{
    protected $table = 'catalog_measurement_units';

    protected $fillable = [
        'measurement_dimension_id',
        'key',
        'name',
        'symbol',
        'description',
        'status',
    ];

    protected static function booted(): void
    {
        static::creating(function (MeasurementUnit $unit): void {
            SemanticKey::assertValid((string) $unit->key);
            static::assertName((string) $unit->name);
            static::assertSymbol($unit->symbol);

            $dimension = MeasurementDimension::query()
                ->whereKey($unit->measurement_dimension_id)
                ->first();

            if (
                ! $dimension
                || $dimension->status
                    !== MeasurementDimensionStatus::Active
            ) {
                throw new DomainException(
                    'Una unidad de medida sólo puede crearse bajo una dimensión activa.'
                );
            }
        });

        static::updating(function (MeasurementUnit $unit): void {
            if (
                $unit->isDirty([
                    'key',
                    'measurement_dimension_id',
                ])
            ) {
                throw new DomainException(
                    'La identidad y dimensión de una unidad de medida son inmutables.'
                );
            }

            if ($unit->isDirty('name')) {
                static::assertName((string) $unit->name);
            }

            if ($unit->isDirty('symbol')) {
                static::assertSymbol($unit->symbol);
            }

            $original = MeasurementUnitStatus::from(
                (string) $unit->getRawOriginal('status')
            );

            if ($original === MeasurementUnitStatus::Retired) {
                throw new DomainException(
                    'Una unidad de medida retirada es inmutable.'
                );
            }

            if (! $unit->isDirty('status')) {
                return;
            }

            $next = $unit->status;

            $valid = match ($original) {
                MeasurementUnitStatus::Active =>
                    $next === MeasurementUnitStatus::Deprecated,
                MeasurementUnitStatus::Deprecated =>
                    $next === MeasurementUnitStatus::Retired,
                MeasurementUnitStatus::Retired => false,
            };

            if (! $valid) {
                throw new DomainException(
                    'Transición de estado inválida para la unidad de medida.'
                );
            }
        });

        static::deleting(fn () => throw new DomainException(
            'Una unidad de medida semántica no puede eliminarse físicamente.'
        ));
    }

    protected function casts(): array
    {
        return [
            'status' => MeasurementUnitStatus::class,
        ];
    }

    public function dimension(): BelongsTo
    {
        return $this->belongsTo(
            MeasurementDimension::class,
            'measurement_dimension_id'
        );
    }

    public function attributeBindings(): HasMany
    {
        return $this->hasMany(
            AttributeBinding::class,
            'measurement_unit_id'
        );
    }

    private static function assertName(string $name): void
    {
        if (
            trim($name) === ''
            || mb_strlen($name) > 160
        ) {
            throw new DomainException(
                'El nombre de la unidad de medida es obligatorio y admite hasta 160 caracteres.'
            );
        }
    }

    private static function assertSymbol(mixed $symbol): void
    {
        if (
            $symbol !== null
            && mb_strlen(trim((string) $symbol)) > 32
        ) {
            throw new DomainException(
                'El símbolo de la unidad de medida admite hasta 32 caracteres.'
            );
        }
    }
}
