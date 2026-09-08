<?php

namespace App\Domain\Catalog;

use App\Enums\AttributeDefinitionStatus;
use App\Enums\AttributeValueScope;
use App\Enums\AttributeValueType;
use App\Enums\MeasurementDimensionStatus;
use App\Enums\MeasurementUnitStatus;
use App\Enums\ProductSchemaStatus;
use App\Models\AttributeBinding;
use App\Models\AttributeDefinition;
use App\Models\MeasurementUnit;
use App\Models\ProductSchemaVersion;
use DomainException;
use Illuminate\Support\Facades\DB;

class AttributeBindingManager
{
    public function bind(
        ProductSchemaVersion $schema,
        AttributeDefinition $attribute,
        AttributeValueType $valueType,
        AttributeValueScope $valueScope,
        ?MeasurementUnit $measurementUnit = null
    ): AttributeBinding {
        return DB::transaction(function () use (
            $schema,
            $attribute,
            $valueType,
            $valueScope,
            $measurementUnit
        ): AttributeBinding {
            $lockedSchema = $this->lockDraft($schema);
            $lockedAttribute = $this->lockActiveAttribute($attribute);

            $unitId = $this->validatedMeasurementUnitId(
                $valueType,
                $measurementUnit
            );

            if (
                AttributeBinding::query()
                    ->where(
                        'product_schema_version_id',
                        $lockedSchema->id
                    )
                    ->where(
                        'attribute_definition_id',
                        $lockedAttribute->id
                    )
                    ->exists()
            ) {
                throw new DomainException(
                    'El atributo ya está vinculado a esta versión de schema.'
                );
            }

            return AttributeBinding::query()->create([
                'product_schema_version_id' => $lockedSchema->id,
                'attribute_definition_id' => $lockedAttribute->id,
                'value_type' => $valueType,
                'value_scope' => $valueScope,
                'measurement_unit_id' => $unitId,
            ])->fresh();
        });
    }

    public function reconfigure(
        AttributeBinding $binding,
        AttributeValueType $valueType,
        AttributeValueScope $valueScope,
        ?MeasurementUnit $measurementUnit = null
    ): AttributeBinding {
        return DB::transaction(function () use (
            $binding,
            $valueType,
            $valueScope,
            $measurementUnit
        ): AttributeBinding {
            $locked = AttributeBinding::query()
                ->whereKey($binding->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->lockDraft(
                $locked->schemaVersion()->firstOrFail()
            );

            $this->lockActiveAttribute(
                $locked->attributeDefinition()->firstOrFail()
            );

            $unitId = $this->validatedMeasurementUnitId(
                $valueType,
                $measurementUnit
            );

            $locked->fill([
                'value_type' => $valueType,
                'value_scope' => $valueScope,
                'measurement_unit_id' => $unitId,
            ])->save();

            return $locked->fresh();
        });
    }

    public function remove(AttributeBinding $binding): void
    {
        DB::transaction(function () use ($binding): void {
            $locked = AttributeBinding::query()
                ->whereKey($binding->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->lockDraft(
                $locked->schemaVersion()->firstOrFail()
            );

            $locked->delete();
        });
    }

    private function lockDraft(
        ProductSchemaVersion $schema
    ): ProductSchemaVersion {
        $locked = ProductSchemaVersion::query()
            ->whereKey($schema->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if ($locked->status !== ProductSchemaStatus::Draft) {
            throw new DomainException(
                'Los bindings sólo pueden mutarse mientras el schema está en draft.'
            );
        }

        return $locked;
    }

    private function lockActiveAttribute(
        AttributeDefinition $attribute
    ): AttributeDefinition {
        $locked = AttributeDefinition::query()
            ->whereKey($attribute->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if (
            $locked->status
                !== AttributeDefinitionStatus::Active
        ) {
            throw new DomainException(
                'Un binding nuevo o reconfigurado requiere una definición de atributo activa.'
            );
        }

        return $locked;
    }

    private function validatedMeasurementUnitId(
        AttributeValueType $valueType,
        ?MeasurementUnit $measurementUnit
    ): ?int {
        if ($valueType !== AttributeValueType::Measurement) {
            if ($measurementUnit !== null) {
                throw new DomainException(
                    'Sólo un binding measurement puede declarar una unidad de medida.'
                );
            }

            return null;
        }

        if ($measurementUnit === null) {
            throw new DomainException(
                'Un binding measurement requiere una unidad de medida.'
            );
        }

        $unit = MeasurementUnit::query()
            ->whereKey($measurementUnit->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if ($unit->status !== MeasurementUnitStatus::Active) {
            throw new DomainException(
                'Un binding measurement requiere una unidad de medida activa.'
            );
        }

        $dimension = $unit->dimension()
            ->lockForUpdate()
            ->firstOrFail();

        if (
            $dimension->status
                !== MeasurementDimensionStatus::Active
        ) {
            throw new DomainException(
                'La dimensión de la unidad de medida debe estar activa.'
            );
        }

        return (int) $unit->id;
    }
}
