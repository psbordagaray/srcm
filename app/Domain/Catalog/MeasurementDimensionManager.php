<?php

namespace App\Domain\Catalog;

use App\Enums\MeasurementDimensionStatus;
use App\Enums\MeasurementUnitStatus;
use App\Models\MeasurementDimension;
use DomainException;
use Illuminate\Support\Facades\DB;

class MeasurementDimensionManager
{
    public function create(
        string $key,
        string $name,
        ?string $description = null
    ): MeasurementDimension {
        SemanticKey::assertValid($key);
        $this->assertName($name);

        return DB::transaction(function () use (
            $key,
            $name,
            $description
        ): MeasurementDimension {
            if (
                MeasurementDimension::query()
                    ->where('key', $key)
                    ->exists()
            ) {
                throw new DomainException(
                    'La semantic key de la dimensión de medida ya existe.'
                );
            }

            return MeasurementDimension::query()->create([
                'key' => $key,
                'name' => $name,
                'description' => $description,
                'status' => MeasurementDimensionStatus::Active,
            ]);
        });
    }

    public function updateMetadata(
        MeasurementDimension $dimension,
        string $name,
        ?string $description = null
    ): MeasurementDimension {
        $this->assertName($name);

        return DB::transaction(function () use (
            $dimension,
            $name,
            $description
        ): MeasurementDimension {
            $locked = $this->lock($dimension);

            if (
                $locked->status
                    === MeasurementDimensionStatus::Retired
            ) {
                throw new DomainException(
                    'Una dimensión de medida retirada es inmutable.'
                );
            }

            $locked->fill([
                'name' => $name,
                'description' => $description,
            ])->save();

            return $locked->fresh();
        });
    }

    public function deprecate(
        MeasurementDimension $dimension
    ): MeasurementDimension {
        return DB::transaction(function () use (
            $dimension
        ): MeasurementDimension {
            $locked = $this->lock($dimension);

            if (
                $locked->status
                    !== MeasurementDimensionStatus::Active
            ) {
                throw new DomainException(
                    'Sólo una dimensión de medida activa puede deprecarse.'
                );
            }

            if (
                $locked->units()
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

            $locked->status = MeasurementDimensionStatus::Deprecated;
            $locked->save();

            return $locked->fresh();
        });
    }

    public function retire(
        MeasurementDimension $dimension
    ): MeasurementDimension {
        return DB::transaction(function () use (
            $dimension
        ): MeasurementDimension {
            $locked = $this->lock($dimension);

            if (
                $locked->status
                    !== MeasurementDimensionStatus::Deprecated
            ) {
                throw new DomainException(
                    'Sólo una dimensión de medida deprecada puede retirarse.'
                );
            }

            if (
                $locked->units()
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

            $locked->status = MeasurementDimensionStatus::Retired;
            $locked->save();

            return $locked->fresh();
        });
    }

    private function lock(
        MeasurementDimension $dimension
    ): MeasurementDimension {
        return MeasurementDimension::query()
            ->whereKey($dimension->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertName(string $name): void
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
