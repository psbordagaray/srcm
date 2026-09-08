<?php

namespace App\Domain\Catalog;

use App\Enums\MeasurementDimensionStatus;
use App\Enums\MeasurementUnitStatus;
use App\Models\MeasurementDimension;
use App\Models\MeasurementUnit;
use DomainException;
use Illuminate\Support\Facades\DB;

class MeasurementUnitManager
{
    public function create(
        MeasurementDimension $dimension,
        string $key,
        string $name,
        ?string $symbol = null,
        ?string $description = null
    ): MeasurementUnit {
        SemanticKey::assertValid($key);
        $this->assertName($name);
        $this->assertSymbol($symbol);

        return DB::transaction(function () use (
            $dimension,
            $key,
            $name,
            $symbol,
            $description
        ): MeasurementUnit {
            $lockedDimension = MeasurementDimension::query()
                ->whereKey($dimension->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $lockedDimension->status
                    !== MeasurementDimensionStatus::Active
            ) {
                throw new DomainException(
                    'Una unidad de medida sólo puede crearse bajo una dimensión activa.'
                );
            }

            if (
                MeasurementUnit::query()
                    ->where('key', $key)
                    ->exists()
            ) {
                throw new DomainException(
                    'La semantic key de la unidad de medida ya existe.'
                );
            }

            return MeasurementUnit::query()->create([
                'measurement_dimension_id' => $lockedDimension->id,
                'key' => $key,
                'name' => $name,
                'symbol' => $symbol,
                'description' => $description,
                'status' => MeasurementUnitStatus::Active,
            ]);
        });
    }

    public function updateMetadata(
        MeasurementUnit $unit,
        string $name,
        ?string $symbol = null,
        ?string $description = null
    ): MeasurementUnit {
        $this->assertName($name);
        $this->assertSymbol($symbol);

        return DB::transaction(function () use (
            $unit,
            $name,
            $symbol,
            $description
        ): MeasurementUnit {
            $locked = $this->lock($unit);

            if ($locked->status === MeasurementUnitStatus::Retired) {
                throw new DomainException(
                    'Una unidad de medida retirada es inmutable.'
                );
            }

            $locked->fill([
                'name' => $name,
                'symbol' => $symbol,
                'description' => $description,
            ])->save();

            return $locked->fresh();
        });
    }

    public function deprecate(
        MeasurementUnit $unit
    ): MeasurementUnit {
        return DB::transaction(function () use (
            $unit
        ): MeasurementUnit {
            $locked = $this->lock($unit);

            if ($locked->status !== MeasurementUnitStatus::Active) {
                throw new DomainException(
                    'Sólo una unidad de medida activa puede deprecarse.'
                );
            }

            $locked->status = MeasurementUnitStatus::Deprecated;
            $locked->save();

            return $locked->fresh();
        });
    }

    public function retire(
        MeasurementUnit $unit
    ): MeasurementUnit {
        return DB::transaction(function () use (
            $unit
        ): MeasurementUnit {
            $locked = $this->lock($unit);

            if (
                $locked->status
                    !== MeasurementUnitStatus::Deprecated
            ) {
                throw new DomainException(
                    'Sólo una unidad de medida deprecada puede retirarse.'
                );
            }

            $locked->status = MeasurementUnitStatus::Retired;
            $locked->save();

            return $locked->fresh();
        });
    }

    private function lock(MeasurementUnit $unit): MeasurementUnit
    {
        return MeasurementUnit::query()
            ->whereKey($unit->getKey())
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
                'El nombre de la unidad de medida es obligatorio y admite hasta 160 caracteres.'
            );
        }
    }

    private function assertSymbol(?string $symbol): void
    {
        if (
            $symbol !== null
            && mb_strlen(trim($symbol)) > 32
        ) {
            throw new DomainException(
                'El símbolo de la unidad de medida admite hasta 32 caracteres.'
            );
        }
    }
}
