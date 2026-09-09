<?php

namespace App\Domain\Catalog;

use App\Enums\AttributeValueScope;
use App\Enums\AttributeValueType;
use App\Enums\ProductDefinitionStatus;
use App\Enums\ProductSchemaStatus;
use App\Enums\SemanticCapabilityActivationMode;
use App\Enums\SemanticCapabilityStatus;
use App\Enums\SemanticProfileResolutionMode;
use App\Models\ProductDefinition;
use App\Models\ProductSchemaVersion;
use DomainException;
use Illuminate\Support\Facades\DB;
use stdClass;

final class EffectiveSemanticProfileResolver
{
    public function currentPublished(
        ProductDefinition $definition
    ): EffectiveSemanticProfile {
        return DB::transaction(function () use (
            $definition
        ): EffectiveSemanticProfile {
            $definitionRow = $this->definitionRow(
                (int) $definition->getKey()
            );

            $definitionStatus = $this->definitionStatus(
                (string) $definitionRow->status
            );

            if ($definitionStatus === ProductDefinitionStatus::Retired) {
                throw new DomainException(
                    'Una definición retirada no posee perfil semántico corriente.'
                );
            }

            $schemas = DB::table('catalog_product_schema_versions')
                ->where(
                    'product_definition_id',
                    (int) $definitionRow->id
                )
                ->where(
                    'status',
                    ProductSchemaStatus::Published->value
                )
                ->orderBy('id')
                ->get();

            if ($schemas->count() !== 1) {
                throw new DomainException(
                    'La resolución corriente requiere exactamente un schema publicado.'
                );
            }

            $schemaRow = $schemas->first();

            if (! $schemaRow instanceof stdClass) {
                throw new DomainException(
                    'No se pudo resolver el schema publicado.'
                );
            }

            return $this->buildProfile(
                $definitionRow,
                $schemaRow,
                SemanticProfileResolutionMode::CurrentPublished,
                EffectiveSemanticProfileProvenance::AUTHORITY_PRODUCT_DEFINITION,
                (int) $definitionRow->id,
                EffectiveSemanticProfileProvenance::RULE_CURRENT_PUBLISHED
            );
        });
    }

    public function exactHistorical(
        ProductSchemaVersion $schema
    ): EffectiveSemanticProfile {
        return DB::transaction(function () use (
            $schema
        ): EffectiveSemanticProfile {
            $schemaRow = DB::table('catalog_product_schema_versions')
                ->where('id', (int) $schema->getKey())
                ->first();

            if (! $schemaRow instanceof stdClass) {
                throw new DomainException(
                    'La versión de schema solicitada no existe.'
                );
            }

            $schemaStatus = $this->schemaStatus(
                (string) $schemaRow->status
            );

            if ($schemaStatus === ProductSchemaStatus::Draft) {
                throw new DomainException(
                    'Un draft no es autoridad semántica histórica publicada.'
                );
            }

            $this->publishedAt($schemaRow);

            $definitionRow = $this->definitionRow(
                (int) $schemaRow->product_definition_id
            );

            return $this->buildProfile(
                $definitionRow,
                $schemaRow,
                SemanticProfileResolutionMode::ExactHistorical,
                EffectiveSemanticProfileProvenance::AUTHORITY_PRODUCT_SCHEMA_VERSION,
                (int) $schemaRow->id,
                EffectiveSemanticProfileProvenance::RULE_EXACT_HISTORICAL
            );
        });
    }

    private function buildProfile(
        stdClass $definitionRow,
        stdClass $schemaRow,
        SemanticProfileResolutionMode $mode,
        string $requestedAuthorityType,
        int $requestedAuthorityId,
        string $selectionRule
    ): EffectiveSemanticProfile {
        $definitionId = (int) $definitionRow->id;
        $definitionKey = (string) $definitionRow->key;
        $schemaId = (int) $schemaRow->id;

        if (
            $definitionId <= 0
            || $schemaId <= 0
            || (int) $schemaRow->product_definition_id
                !== $definitionId
        ) {
            throw new DomainException(
                'La versión de schema no pertenece a la definición semántica esperada.'
            );
        }

        SemanticKey::assertValid($definitionKey);
        $this->definitionStatus((string) $definitionRow->status);

        $schemaStatus = $this->schemaStatus(
            (string) $schemaRow->status
        );

        if ($schemaStatus === ProductSchemaStatus::Draft) {
            throw new DomainException(
                'Un draft no puede producir un perfil semántico efectivo publicado.'
            );
        }

        if (
            $mode === SemanticProfileResolutionMode::CurrentPublished
            && $schemaStatus !== ProductSchemaStatus::Published
        ) {
            throw new DomainException(
                'La resolución corriente seleccionó un schema no publicado.'
            );
        }

        $publishedAt = $this->publishedAt($schemaRow);
        $version = (int) $schemaRow->version;

        if ($version <= 0) {
            throw new DomainException(
                'La versión de schema posee una identidad inválida.'
            );
        }

        $attributes = [];
        $definitionIds = [];
        $definitionKeys = [];

        $bindings = DB::table('catalog_attribute_bindings')
            ->where('product_schema_version_id', $schemaId)
            ->get();

        foreach ($bindings as $bindingRow) {
            if (! $bindingRow instanceof stdClass) {
                throw new DomainException(
                    'El binding semántico no pudo resolverse.'
                );
            }

            $attribute = $this->resolveAttribute(
                $schemaId,
                $bindingRow
            );

            if (
                isset(
                    $definitionIds[$attribute->attributeDefinitionId]
                )
                || isset(
                    $definitionKeys[$attribute->attributeDefinitionKey]
                )
            ) {
                throw new DomainException(
                    'El schema contiene identidad efectiva de atributo duplicada.'
                );
            }

            $definitionIds[$attribute->attributeDefinitionId] = true;
            $definitionKeys[$attribute->attributeDefinitionKey] = true;
            $attributes[] = $attribute;
        }

        usort(
            $attributes,
            static function (
                EffectiveSemanticAttribute $left,
                EffectiveSemanticAttribute $right
            ): int {
                $keyOrder = strcmp(
                    $left->attributeDefinitionKey,
                    $right->attributeDefinitionKey
                );

                return $keyOrder !== 0
                    ? $keyOrder
                    : $left->attributeDefinitionId
                        <=> $right->attributeDefinitionId;
            }
        );

        $capabilities = $this->resolveCapabilities($schemaId);

        $provenance = new EffectiveSemanticProfileProvenance(
            resolutionMode: $mode,
            requestedAuthorityType: $requestedAuthorityType,
            requestedAuthorityId: $requestedAuthorityId,
            productDefinitionId: $definitionId,
            productDefinitionKey: $definitionKey,
            productSchemaVersionId: $schemaId,
            selectionRule: $selectionRule,
        );

        return new EffectiveSemanticProfile(
            resolutionMode: $mode,
            productDefinitionId: $definitionId,
            productDefinitionKey: $definitionKey,
            productSchemaVersionId: $schemaId,
            schemaVersion: $version,
            schemaStatus: $schemaStatus,
            publishedAt: $publishedAt,
            attributes: $attributes,
            capabilities: $capabilities,
            provenance: $provenance,
        );
    }

    private function resolveAttribute(
        int $schemaId,
        stdClass $bindingRow
    ): EffectiveSemanticAttribute {
        $bindingId = (int) $bindingRow->id;
        $attributeDefinitionId =
            (int) $bindingRow->attribute_definition_id;

        if (
            $bindingId <= 0
            || $attributeDefinitionId <= 0
            || (int) $bindingRow->product_schema_version_id
                !== $schemaId
        ) {
            throw new DomainException(
                'El binding semántico posee una identidad inválida.'
            );
        }

        $attributeRow = DB::table('catalog_attribute_definitions')
            ->where('id', $attributeDefinitionId)
            ->first();

        if (! $attributeRow instanceof stdClass) {
            throw new DomainException(
                'El binding referencia una definición de atributo inexistente.'
            );
        }

        $attributeKey = (string) $attributeRow->key;
        SemanticKey::assertValid($attributeKey);

        $valueType = AttributeValueType::tryFrom(
            (string) $bindingRow->value_type
        );

        $valueScope = AttributeValueScope::tryFrom(
            (string) $bindingRow->value_scope
        );

        if (! $valueType || ! $valueScope) {
            throw new DomainException(
                'El binding contiene tipo o scope fuera del contrato CSF-2.'
            );
        }

        $unitId = $bindingRow->measurement_unit_id === null
            ? null
            : (int) $bindingRow->measurement_unit_id;

        $unitKey = null;
        $dimensionId = null;
        $dimensionKey = null;

        if ($valueType !== AttributeValueType::Measurement) {
            if ($unitId !== null) {
                throw new DomainException(
                    'Un binding no measurement no puede resolver unidad de medida.'
                );
            }
        } else {
            if ($unitId === null || $unitId <= 0) {
                throw new DomainException(
                    'Un binding measurement requiere una unidad de medida.'
                );
            }

            $unitRow = DB::table('catalog_measurement_units')
                ->where('id', $unitId)
                ->first();

            if (! $unitRow instanceof stdClass) {
                throw new DomainException(
                    'El binding measurement referencia una unidad inexistente.'
                );
            }

            $unitKey = (string) $unitRow->key;
            SemanticKey::assertValid($unitKey);

            $dimensionId = (int) $unitRow->measurement_dimension_id;

            if ($dimensionId <= 0) {
                throw new DomainException(
                    'La unidad de medida referencia una dimensión inválida.'
                );
            }

            $dimensionRow = DB::table(
                'catalog_measurement_dimensions'
            )
                ->where('id', $dimensionId)
                ->first();

            if (! $dimensionRow instanceof stdClass) {
                throw new DomainException(
                    'La unidad de medida referencia una dimensión inexistente.'
                );
            }

            $dimensionKey = (string) $dimensionRow->key;
            SemanticKey::assertValid($dimensionKey);
        }

        $provenance = new EffectiveSemanticAttributeProvenance(
            productSchemaVersionId: $schemaId,
            attributeBindingId: $bindingId,
            attributeDefinitionId: $attributeDefinitionId,
            attributeDefinitionKey: $attributeKey,
            measurementUnitId: $unitId,
            measurementUnitKey: $unitKey,
            measurementDimensionId: $dimensionId,
            measurementDimensionKey: $dimensionKey,
        );

        return new EffectiveSemanticAttribute(
            attributeBindingId: $bindingId,
            attributeDefinitionId: $attributeDefinitionId,
            attributeDefinitionKey: $attributeKey,
            valueType: $valueType,
            valueScope: $valueScope,
            measurementUnitId: $unitId,
            measurementUnitKey: $unitKey,
            measurementDimensionId: $dimensionId,
            measurementDimensionKey: $dimensionKey,
            provenance: $provenance,
        );
    }

    /**
     * @return list<EffectiveSemanticCapability>
     */
    private function resolveCapabilities(int $schemaId): array
    {
        $capabilities = [];
        $definitionIds = [];
        $definitionKeys = [];

        $declarations = DB::table(
            'catalog_product_schema_capability_declarations'
        )
            ->where('product_schema_version_id', $schemaId)
            ->get();

        foreach ($declarations as $declarationRow) {
            if (! $declarationRow instanceof stdClass) {
                throw new DomainException(
                    'La capability declaration no pudo resolverse.'
                );
            }

            $capability = $this->resolveCapability(
                $schemaId,
                $declarationRow
            );

            if (
                isset(
                    $definitionIds[
                        $capability->semanticCapabilityDefinitionId
                    ]
                )
                || isset(
                    $definitionKeys[
                        $capability->semanticCapabilityKey
                    ]
                )
            ) {
                throw new DomainException(
                    'El schema contiene identidad efectiva de capability duplicada.'
                );
            }

            $definitionIds[
                $capability->semanticCapabilityDefinitionId
            ] = true;
            $definitionKeys[
                $capability->semanticCapabilityKey
            ] = true;
            $capabilities[] = $capability;
        }

        usort(
            $capabilities,
            static function (
                EffectiveSemanticCapability $left,
                EffectiveSemanticCapability $right
            ): int {
                $keyOrder = strcmp(
                    $left->semanticCapabilityKey,
                    $right->semanticCapabilityKey
                );

                return $keyOrder !== 0
                    ? $keyOrder
                    : $left->semanticCapabilityDefinitionId
                        <=> $right->semanticCapabilityDefinitionId;
            }
        );

        return $capabilities;
    }

    private function resolveCapability(
        int $schemaId,
        stdClass $declarationRow
    ): EffectiveSemanticCapability {
        $declarationId = (int) $declarationRow->id;
        $definitionId =
            (int) $declarationRow->semantic_capability_definition_id;

        if (
            $declarationId <= 0
            || $definitionId <= 0
            || (int) $declarationRow->product_schema_version_id
                !== $schemaId
        ) {
            throw new DomainException(
                'La capability declaration posee una identidad inválida.'
            );
        }

        $definitionRow = DB::table(
            'catalog_semantic_capability_definitions'
        )
            ->where('id', $definitionId)
            ->first();

        if (! $definitionRow instanceof stdClass) {
            throw new DomainException(
                'La capability declaration referencia una definición inexistente.'
            );
        }

        $key = (string) $definitionRow->key;
        SemanticKey::assertValid($key);

        if (
            ! SemanticCapabilityStatus::tryFrom(
                (string) $definitionRow->status
            )
        ) {
            throw new DomainException(
                'La capability posee un lifecycle inválido.'
            );
        }

        $mode = SemanticCapabilityActivationMode::tryFrom(
            (string) $declarationRow->activation_mode
        );

        if (! $mode) {
            throw new DomainException(
                'La capability declaration posee un activation mode inválido.'
            );
        }

        $defaultEnabled = $this->storedBoolean(
            $declarationRow->default_enabled,
            'La capability declaration no posee un default boolean válido.'
        );

        if (
            $mode === SemanticCapabilityActivationMode::FixedEnabled
            && ! $defaultEnabled
        ) {
            throw new DomainException(
                'FIXED_ENABLED requiere default_enabled=true.'
            );
        }

        $provenance = new EffectiveSemanticCapabilityProvenance(
            productSchemaVersionId: $schemaId,
            productSchemaCapabilityDeclarationId: $declarationId,
            semanticCapabilityDefinitionId: $definitionId,
            semanticCapabilityKey: $key,
        );

        return new EffectiveSemanticCapability(
            declarationId: $declarationId,
            semanticCapabilityDefinitionId: $definitionId,
            semanticCapabilityKey: $key,
            activationMode: $mode,
            provenance: $provenance,
        );
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

    private function definitionRow(int $definitionId): stdClass
    {
        if ($definitionId <= 0) {
            throw new DomainException(
                'La definición semántica solicitada posee una identidad inválida.'
            );
        }

        $definitionRow = DB::table('catalog_product_definitions')
            ->where('id', $definitionId)
            ->first();

        if (! $definitionRow instanceof stdClass) {
            throw new DomainException(
                'La definición semántica solicitada no existe.'
            );
        }

        SemanticKey::assertValid((string) $definitionRow->key);

        return $definitionRow;
    }

    private function definitionStatus(
        string $status
    ): ProductDefinitionStatus {
        $resolved = ProductDefinitionStatus::tryFrom($status);

        if (! $resolved) {
            throw new DomainException(
                'La definición posee un estado semántico inválido.'
            );
        }

        return $resolved;
    }

    private function schemaStatus(string $status): ProductSchemaStatus
    {
        $resolved = ProductSchemaStatus::tryFrom($status);

        if (! $resolved) {
            throw new DomainException(
                'La versión de schema posee un estado inválido.'
            );
        }

        return $resolved;
    }

    private function publishedAt(stdClass $schemaRow): string
    {
        $publishedAt = $schemaRow->published_at === null
            ? ''
            : trim((string) $schemaRow->published_at);

        if ($publishedAt === '') {
            throw new DomainException(
                'La autoridad semántica histórica requiere published_at.'
            );
        }

        return $publishedAt;
    }
}
