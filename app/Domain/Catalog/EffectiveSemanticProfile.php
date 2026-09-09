<?php

namespace App\Domain\Catalog;

use App\Enums\ProductSchemaStatus;
use App\Enums\SemanticProfileResolutionMode;
use InvalidArgumentException;

final readonly class EffectiveSemanticProfile
{
    /**
     * @param list<EffectiveSemanticAttribute> $attributes
     * @param list<EffectiveSemanticCapability> $capabilities
     */
    public function __construct(
        public SemanticProfileResolutionMode $resolutionMode,
        public int $productDefinitionId,
        public string $productDefinitionKey,
        public int $productSchemaVersionId,
        public int $schemaVersion,
        public ProductSchemaStatus $schemaStatus,
        public string $publishedAt,
        public array $attributes,
        public EffectiveSemanticProfileProvenance $provenance,
        public array $capabilities = [],
    ) {
        if (
            $this->productDefinitionId <= 0
            || $this->productDefinitionKey === ''
            || $this->productSchemaVersionId <= 0
            || $this->schemaVersion <= 0
            || trim($this->publishedAt) === ''
            || $this->schemaStatus === ProductSchemaStatus::Draft
        ) {
            throw new InvalidArgumentException(
                'Effective semantic profile identity is invalid.'
            );
        }

        if (
            $this->resolutionMode
                === SemanticProfileResolutionMode::CurrentPublished
            && $this->schemaStatus !== ProductSchemaStatus::Published
        ) {
            throw new InvalidArgumentException(
                'Current published resolution requires a published schema.'
            );
        }

        if (
            $this->provenance->resolutionMode !== $this->resolutionMode
            || $this->provenance->productDefinitionId
                !== $this->productDefinitionId
            || $this->provenance->productDefinitionKey
                !== $this->productDefinitionKey
            || $this->provenance->productSchemaVersionId
                !== $this->productSchemaVersionId
        ) {
            throw new InvalidArgumentException(
                'Effective semantic profile provenance is inconsistent.'
            );
        }

        $definitionIds = [];
        $definitionKeys = [];
        $lastKey = null;
        $lastId = null;

        foreach ($this->attributes as $attribute) {
            if (! $attribute instanceof EffectiveSemanticAttribute) {
                throw new InvalidArgumentException(
                    'Effective semantic profile attributes must use the canonical attribute projection.'
                );
            }

            if (
                $attribute->provenance->productSchemaVersionId
                    !== $this->productSchemaVersionId
            ) {
                throw new InvalidArgumentException(
                    'Effective semantic profile contains an attribute from another schema.'
                );
            }

            if (
                isset($definitionIds[$attribute->attributeDefinitionId])
                || isset($definitionKeys[$attribute->attributeDefinitionKey])
            ) {
                throw new InvalidArgumentException(
                    'Effective semantic profile contains duplicate attribute identity.'
                );
            }

            if (
                $lastKey !== null
                && (
                    strcmp(
                        $attribute->attributeDefinitionKey,
                        $lastKey
                    ) < 0
                    || (
                        $attribute->attributeDefinitionKey === $lastKey
                        && $attribute->attributeDefinitionId <= $lastId
                    )
                )
            ) {
                throw new InvalidArgumentException(
                    'Effective semantic profile attributes are not deterministically ordered.'
                );
            }

            $definitionIds[$attribute->attributeDefinitionId] = true;
            $definitionKeys[$attribute->attributeDefinitionKey] = true;
            $lastKey = $attribute->attributeDefinitionKey;
            $lastId = $attribute->attributeDefinitionId;
        }

        $capabilityIds = [];
        $capabilityKeys = [];
        $lastCapabilityKey = null;
        $lastCapabilityId = null;

        foreach ($this->capabilities as $capability) {
            if (! $capability instanceof EffectiveSemanticCapability) {
                throw new InvalidArgumentException(
                    'Effective semantic profile capabilities must use the canonical capability projection.'
                );
            }

            if (
                $capability->provenance->productSchemaVersionId
                    !== $this->productSchemaVersionId
            ) {
                throw new InvalidArgumentException(
                    'Effective semantic profile contains a capability from another schema.'
                );
            }

            if (
                isset(
                    $capabilityIds[
                        $capability->semanticCapabilityDefinitionId
                    ]
                )
                || isset(
                    $capabilityKeys[
                        $capability->semanticCapabilityKey
                    ]
                )
            ) {
                throw new InvalidArgumentException(
                    'Effective semantic profile contains duplicate capability identity.'
                );
            }

            if (
                $lastCapabilityKey !== null
                && (
                    strcmp(
                        $capability->semanticCapabilityKey,
                        $lastCapabilityKey
                    ) < 0
                    || (
                        $capability->semanticCapabilityKey
                            === $lastCapabilityKey
                        && $capability
                            ->semanticCapabilityDefinitionId
                            <= $lastCapabilityId
                    )
                )
            ) {
                throw new InvalidArgumentException(
                    'Effective semantic profile capabilities are not deterministically ordered.'
                );
            }

            $capabilityIds[
                $capability->semanticCapabilityDefinitionId
            ] = true;
            $capabilityKeys[
                $capability->semanticCapabilityKey
            ] = true;
            $lastCapabilityKey =
                $capability->semanticCapabilityKey;
            $lastCapabilityId =
                $capability->semanticCapabilityDefinitionId;
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'resolution_mode' => $this->resolutionMode->value,
            'product_definition_id' => $this->productDefinitionId,
            'product_definition_key' => $this->productDefinitionKey,
            'product_schema_version_id' =>
                $this->productSchemaVersionId,
            'schema_version' => $this->schemaVersion,
            'schema_status' => $this->schemaStatus->value,
            'published_at' => $this->publishedAt,
            'attributes' => array_map(
                static fn (
                    EffectiveSemanticAttribute $attribute
                ): array => $attribute->toArray(),
                $this->attributes
            ),
            'capabilities' => array_map(
                static fn (
                    EffectiveSemanticCapability $capability
                ): array => $capability->toArray(),
                $this->capabilities
            ),
            'provenance' => $this->provenance->toArray(),
        ];
    }
}
