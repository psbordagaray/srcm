<?php

namespace App\Domain\Catalog;

use App\Enums\AttributeDefinitionStatus;
use App\Enums\AttributeValueScope;
use App\Enums\AttributeValueType;
use App\Enums\MeasurementDimensionStatus;
use App\Enums\MeasurementUnitStatus;
use App\Enums\ProductDefinitionStatus;
use App\Enums\ProductSchemaStatus;
use App\Enums\SemanticCapabilityActivationMode;
use App\Enums\SemanticCapabilityStatus;
use App\Models\AttributeDefinition;
use App\Models\MeasurementDimension;
use App\Models\MeasurementUnit;
use App\Models\ProductDefinition;
use App\Models\ProductSchemaVersion;
use DomainException;
use Illuminate\Support\Facades\DB;

class ProductSchemaVersionManager
{
    public function createDraft(
        ProductDefinition $definition,
        ?string $changeSummary = null
    ): ProductSchemaVersion {
        return DB::transaction(function () use (
            $definition,
            $changeSummary
        ): ProductSchemaVersion {
            $lockedDefinition = $this->lockDefinition($definition);

            $this->assertDefinitionActive($lockedDefinition);

            if (
                ProductSchemaVersion::query()
                    ->where(
                        'product_definition_id',
                        $lockedDefinition->id
                    )
                    ->where('status', ProductSchemaStatus::Draft->value)
                    ->exists()
            ) {
                throw new DomainException(
                    'La definición de producto ya posee un draft abierto.'
                );
            }

            $published = ProductSchemaVersion::query()
                ->where(
                    'product_definition_id',
                    $lockedDefinition->id
                )
                ->where(
                    'status',
                    ProductSchemaStatus::Published->value
                )
                ->lockForUpdate()
                ->get();

            if ($published->count() > 1) {
                throw new DomainException(
                    'La definición posee múltiples schemas publicados.'
                );
            }

            $maxVersion = ProductSchemaVersion::query()
                ->where(
                    'product_definition_id',
                    $lockedDefinition->id
                )
                ->max('version');

            $draft = ProductSchemaVersion::query()->create([
                'product_definition_id' => $lockedDefinition->id,
                'version' => ((int) $maxVersion) + 1,
                'status' => ProductSchemaStatus::Draft,
                'change_summary' => $changeSummary,
            ]);

            if ($published->isEmpty()) {
                return $draft->fresh();
            }

            $source = $published->first();

            $bindings = DB::table('catalog_attribute_bindings')
                ->where(
                    'product_schema_version_id',
                    $source->id
                )
                ->lockForUpdate()
                ->get();

            $now = now();

            foreach ($bindings as $binding) {
                DB::table('catalog_attribute_bindings')->insert([
                    'product_schema_version_id' => $draft->id,
                    'attribute_definition_id' =>
                        $binding->attribute_definition_id,
                    'value_type' => $binding->value_type,
                    'value_scope' => $binding->value_scope,
                    'measurement_unit_id' =>
                        $binding->measurement_unit_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $declarations = DB::table(
                'catalog_product_schema_capability_declarations'
            )
                ->where(
                    'product_schema_version_id',
                    $source->id
                )
                ->lockForUpdate()
                ->get();

            foreach ($declarations as $declaration) {
                DB::table(
                    'catalog_product_schema_capability_declarations'
                )->insert([
                    'product_schema_version_id' => $draft->id,
                    'semantic_capability_definition_id' =>
                        $declaration->semantic_capability_definition_id,
                    'activation_mode' => $declaration->activation_mode,
                    'default_enabled' => $declaration->default_enabled,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return $draft->fresh();
        });
    }

    public function updateDraftMetadata(
        ProductSchemaVersion $version,
        ?string $changeSummary
    ): ProductSchemaVersion {
        return DB::transaction(function () use (
            $version,
            $changeSummary
        ): ProductSchemaVersion {
            $locked = $this->lockVersion($version);

            if ($locked->status !== ProductSchemaStatus::Draft) {
                throw new DomainException(
                    'Sólo un draft puede modificar su change summary.'
                );
            }

            $locked->change_summary = $changeSummary;
            $locked->save();

            return $locked->fresh();
        });
    }

    public function publish(
        ProductSchemaVersion $version
    ): ProductSchemaVersion {
        return DB::transaction(function () use (
            $version
        ): ProductSchemaVersion {
            $definition = ProductDefinition::query()
                ->whereKey($version->product_definition_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertDefinitionActive($definition);

            $locked = ProductSchemaVersion::query()
                ->whereKey($version->getKey())
                ->where(
                    'product_definition_id',
                    $definition->id
                )
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== ProductSchemaStatus::Draft) {
                throw new DomainException(
                    'Sólo un draft puede publicarse.'
                );
            }

            $this->validateBindingsForPublication($locked);
            $this->validateCapabilityDeclarationsForPublication(
                $locked
            );

            $published = ProductSchemaVersion::query()
                ->where(
                    'product_definition_id',
                    $definition->id
                )
                ->where(
                    'status',
                    ProductSchemaStatus::Published->value
                )
                ->lockForUpdate()
                ->get();

            if ($published->count() > 1) {
                throw new DomainException(
                    'La definición posee múltiples schemas publicados.'
                );
            }

            $now = now();

            if ($published->isNotEmpty()) {
                $semanticValue = DB::table(
                    'catalog_product_semantic_values as semantic_values'
                )
                    ->join(
                        'catalog_products as products',
                        'products.id',
                        '=',
                        'semantic_values.catalog_product_id'
                    )
                    ->where(
                        'products.product_definition_id',
                        $definition->id
                    )
                    ->select('semantic_values.id')
                    ->first();

                if ($semanticValue !== null) {
                    throw new DomainException(
                        'La definición posee productos con valores semánticos. '
                        .'Revise y descarte esos valores antes de publicar '
                        .'un schema sucesor.'
                    );
                }

                $current = $published->first();
                $current->status = ProductSchemaStatus::Deprecated;
                $current->deprecated_at = $now;
                $current->save();
            }

            $locked->status = ProductSchemaStatus::Published;
            $locked->published_at = $now;
            $locked->save();

            return $locked->fresh();
        });
    }

    public function abandonDraft(
        ProductSchemaVersion $version
    ): ProductSchemaVersion {
        return DB::transaction(function () use (
            $version
        ): ProductSchemaVersion {
            $this->lockDefinitionById(
                (int) $version->product_definition_id
            );

            $locked = $this->lockVersion($version);

            if ($locked->status !== ProductSchemaStatus::Draft) {
                throw new DomainException(
                    'Sólo un draft puede abandonarse.'
                );
            }

            $locked->status = ProductSchemaStatus::Retired;
            $locked->retired_at = now();
            $locked->save();

            return $locked->fresh();
        });
    }

    public function retireDeprecated(
        ProductSchemaVersion $version
    ): ProductSchemaVersion {
        return DB::transaction(function () use (
            $version
        ): ProductSchemaVersion {
            $this->lockDefinitionById(
                (int) $version->product_definition_id
            );

            $locked = $this->lockVersion($version);

            if ($locked->status !== ProductSchemaStatus::Deprecated) {
                throw new DomainException(
                    'Sólo una versión de schema deprecada puede retirarse.'
                );
            }

            $locked->status = ProductSchemaStatus::Retired;
            $locked->retired_at = now();
            $locked->save();

            return $locked->fresh();
        });
    }

    private function validateBindingsForPublication(
        ProductSchemaVersion $schema
    ): void {
        $bindings = DB::table('catalog_attribute_bindings')
            ->where(
                'product_schema_version_id',
                $schema->id
            )
            ->lockForUpdate()
            ->get();

        foreach ($bindings as $binding) {
            $attribute = AttributeDefinition::query()
                ->whereKey($binding->attribute_definition_id)
                ->lockForUpdate()
                ->first();

            if (
                ! $attribute
                || $attribute->status
                    !== AttributeDefinitionStatus::Active
            ) {
                throw new DomainException(
                    'Publicar el schema requiere definiciones de atributo activas.'
                );
            }

            $type = AttributeValueType::tryFrom(
                (string) $binding->value_type
            );

            $scope = AttributeValueScope::tryFrom(
                (string) $binding->value_scope
            );

            if (! $type || ! $scope) {
                throw new DomainException(
                    'El schema contiene un binding fuera del contrato CSF-2.'
                );
            }

            if ($type !== AttributeValueType::Measurement) {
                if ($binding->measurement_unit_id !== null) {
                    throw new DomainException(
                        'Un binding no measurement no puede declarar unidad de medida.'
                    );
                }

                continue;
            }

            if ($binding->measurement_unit_id === null) {
                throw new DomainException(
                    'Un binding measurement requiere unidad de medida.'
                );
            }

            $unit = MeasurementUnit::query()
                ->whereKey($binding->measurement_unit_id)
                ->lockForUpdate()
                ->first();

            if (
                ! $unit
                || $unit->status
                    !== MeasurementUnitStatus::Active
            ) {
                throw new DomainException(
                    'Publicar un binding measurement requiere una unidad activa.'
                );
            }

            $dimension = MeasurementDimension::query()
                ->whereKey($unit->measurement_dimension_id)
                ->lockForUpdate()
                ->first();

            if (
                ! $dimension
                || $dimension->status
                    !== MeasurementDimensionStatus::Active
            ) {
                throw new DomainException(
                    'Publicar un binding measurement requiere una dimensión activa.'
                );
            }
        }
    }

    private function validateCapabilityDeclarationsForPublication(
        ProductSchemaVersion $schema
    ): void {
        $declarations = DB::table(
            'catalog_product_schema_capability_declarations'
        )
            ->where(
                'product_schema_version_id',
                $schema->id
            )
            ->lockForUpdate()
            ->get();

        $seenCapabilityIds = [];

        foreach ($declarations as $declaration) {
            $capabilityId =
                (int) $declaration->semantic_capability_definition_id;

            if (
                $capabilityId <= 0
                || isset($seenCapabilityIds[$capabilityId])
            ) {
                throw new DomainException(
                    'El schema contiene identidad de capability duplicada o inválida.'
                );
            }

            $seenCapabilityIds[$capabilityId] = true;

            $capability = DB::table(
                'catalog_semantic_capability_definitions'
            )
                ->where('id', $capabilityId)
                ->lockForUpdate()
                ->first();

            if (! $capability) {
                throw new DomainException(
                    'Publicar el schema requiere capability definitions existentes.'
                );
            }

            SemanticKey::assertValid((string) $capability->key);

            $status = SemanticCapabilityStatus::tryFrom(
                (string) $capability->status
            );

            if ($status !== SemanticCapabilityStatus::Active) {
                throw new DomainException(
                    'Publicar el schema requiere capability definitions activas.'
                );
            }

            $mode = SemanticCapabilityActivationMode::tryFrom(
                (string) $declaration->activation_mode
            );

            if (! $mode) {
                throw new DomainException(
                    'El schema contiene una capability declaration con activation mode inválido.'
                );
            }

            $defaultEnabled = $this->storedBoolean(
                $declaration->default_enabled,
                'El schema contiene una capability declaration sin default boolean válido.'
            );

            if (
                $mode
                    === SemanticCapabilityActivationMode::FixedEnabled
                && ! $defaultEnabled
            ) {
                throw new DomainException(
                    'FIXED_ENABLED requiere default_enabled=true.'
                );
            }
        }
    }

    private function storedBoolean(
        mixed $value,
        string $message
    ): bool {
        return match (true) {
            $value === true,
            $value === 1,
            $value === '1' => true,
            $value === false,
            $value === 0,
            $value === '0' => false,
            default => throw new DomainException($message),
        };
    }

    private function lockDefinition(
        ProductDefinition $definition
    ): ProductDefinition {
        return $this->lockDefinitionById(
            (int) $definition->getKey()
        );
    }

    private function lockDefinitionById(
        int $definitionId
    ): ProductDefinition {
        return ProductDefinition::query()
            ->whereKey($definitionId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockVersion(
        ProductSchemaVersion $version
    ): ProductSchemaVersion {
        return ProductSchemaVersion::query()
            ->whereKey($version->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertDefinitionActive(
        ProductDefinition $definition
    ): void {
        if (
            $definition->status
                !== ProductDefinitionStatus::Active
        ) {
            throw new DomainException(
                'La definición de producto debe estar activa.'
            );
        }
    }
}
