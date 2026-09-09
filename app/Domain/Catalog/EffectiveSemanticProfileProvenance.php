<?php

namespace App\Domain\Catalog;

use App\Enums\SemanticProfileResolutionMode;
use InvalidArgumentException;

final readonly class EffectiveSemanticProfileProvenance
{
    public const AUTHORITY_PRODUCT_DEFINITION = 'product_definition';

    public const AUTHORITY_PRODUCT_SCHEMA_VERSION =
        'product_schema_version';

    public const RULE_CURRENT_PUBLISHED =
        'exactly_one_current_published_schema';

    public const RULE_EXACT_HISTORICAL =
        'exact_historical_published_schema';

    public function __construct(
        public SemanticProfileResolutionMode $resolutionMode,
        public string $requestedAuthorityType,
        public int $requestedAuthorityId,
        public int $productDefinitionId,
        public string $productDefinitionKey,
        public int $productSchemaVersionId,
        public string $selectionRule,
    ) {
        if (
            $this->requestedAuthorityId <= 0
            || $this->productDefinitionId <= 0
            || $this->productSchemaVersionId <= 0
            || $this->productDefinitionKey === ''
        ) {
            throw new InvalidArgumentException(
                'Effective semantic profile provenance identity is invalid.'
            );
        }

        $expected = match ($this->resolutionMode) {
            SemanticProfileResolutionMode::CurrentPublished => [
                self::AUTHORITY_PRODUCT_DEFINITION,
                self::RULE_CURRENT_PUBLISHED,
                $this->productDefinitionId,
            ],
            SemanticProfileResolutionMode::ExactHistorical => [
                self::AUTHORITY_PRODUCT_SCHEMA_VERSION,
                self::RULE_EXACT_HISTORICAL,
                $this->productSchemaVersionId,
            ],
        };

        if (
            $this->requestedAuthorityType !== $expected[0]
            || $this->selectionRule !== $expected[1]
            || $this->requestedAuthorityId !== $expected[2]
        ) {
            throw new InvalidArgumentException(
                'Effective semantic profile provenance does not match its resolution mode.'
            );
        }
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'resolution_mode' => $this->resolutionMode->value,
            'requested_authority_type' => $this->requestedAuthorityType,
            'requested_authority_id' => $this->requestedAuthorityId,
            'product_definition_id' => $this->productDefinitionId,
            'product_definition_key' => $this->productDefinitionKey,
            'product_schema_version_id' => $this->productSchemaVersionId,
            'selection_rule' => $this->selectionRule,
        ];
    }
}
