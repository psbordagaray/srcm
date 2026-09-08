<?php

namespace App\Models;

use App\Enums\AttributeDefinitionStatus;
use App\Enums\AttributeValueScope;
use App\Enums\AttributeValueType;
use App\Enums\MeasurementDimensionStatus;
use App\Enums\MeasurementUnitStatus;
use App\Enums\ProductSchemaStatus;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttributeBinding extends Model
{
    protected $table = 'catalog_attribute_bindings';

    protected $fillable = [
        'product_schema_version_id',
        'attribute_definition_id',
        'value_type',
        'value_scope',
        'measurement_unit_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (AttributeBinding $binding): void {
            static::assertDraft($binding);
            static::assertConfiguration($binding);
        });

        static::updating(function (AttributeBinding $binding): void {
            if (
                $binding->isDirty([
                    'product_schema_version_id',
                    'attribute_definition_id',
                ])
            ) {
                throw new DomainException(
                    'La identidad de un binding de atributo es inmutable.'
                );
            }

            static::assertDraft($binding);

            if (
                $binding->isDirty([
                    'value_type',
                    'value_scope',
                    'measurement_unit_id',
                ])
            ) {
                static::assertConfiguration($binding);
            }
        });

        static::deleting(function (AttributeBinding $binding): void {
            static::assertDraft($binding);
        });
    }

    protected function casts(): array
    {
        return [
            'value_type' => AttributeValueType::class,
            'value_scope' => AttributeValueScope::class,
        ];
    }

    public function schemaVersion(): BelongsTo
    {
        return $this->belongsTo(
            ProductSchemaVersion::class,
            'product_schema_version_id'
        );
    }

    public function attributeDefinition(): BelongsTo
    {
        return $this->belongsTo(
            AttributeDefinition::class,
            'attribute_definition_id'
        );
    }

    public function measurementUnit(): BelongsTo
    {
        return $this->belongsTo(
            MeasurementUnit::class,
            'measurement_unit_id'
        );
    }

    private static function assertDraft(AttributeBinding $binding): void
    {
        $schema = ProductSchemaVersion::query()
            ->whereKey($binding->product_schema_version_id)
            ->first();

        if (
            ! $schema
            || $schema->status !== ProductSchemaStatus::Draft
        ) {
            throw new DomainException(
                'Los bindings sólo pueden mutarse mientras el schema está en draft.'
            );
        }
    }

    private static function assertConfiguration(
        AttributeBinding $binding
    ): void {
        $attribute = AttributeDefinition::query()
            ->whereKey($binding->attribute_definition_id)
            ->first();

        if (
            ! $attribute
            || $attribute->status
                !== AttributeDefinitionStatus::Active
        ) {
            throw new DomainException(
                'Un binding nuevo o reconfigurado requiere una definición de atributo activa.'
            );
        }

        $type = $binding->value_type instanceof AttributeValueType
            ? $binding->value_type
            : AttributeValueType::tryFrom(
                (string) $binding->value_type
            );

        $scope = $binding->value_scope instanceof AttributeValueScope
            ? $binding->value_scope
            : AttributeValueScope::tryFrom(
                (string) $binding->value_scope
            );

        if (! $type || ! $scope) {
            throw new DomainException(
                'El tipo y scope del binding deben pertenecer al contrato CSF-2.'
            );
        }

        if ($type !== AttributeValueType::Measurement) {
            if ($binding->measurement_unit_id !== null) {
                throw new DomainException(
                    'Sólo un binding measurement puede declarar una unidad de medida.'
                );
            }

            return;
        }

        if ($binding->measurement_unit_id === null) {
            throw new DomainException(
                'Un binding measurement requiere una unidad de medida.'
            );
        }

        $unit = MeasurementUnit::query()
            ->whereKey($binding->measurement_unit_id)
            ->first();

        if (
            ! $unit
            || $unit->status !== MeasurementUnitStatus::Active
        ) {
            throw new DomainException(
                'Un binding measurement requiere una unidad de medida activa.'
            );
        }

        $dimension = $unit->dimension()->first();

        if (
            ! $dimension
            || $dimension->status
                !== MeasurementDimensionStatus::Active
        ) {
            throw new DomainException(
                'La dimensión de la unidad de medida debe estar activa.'
            );
        }
    }
}
