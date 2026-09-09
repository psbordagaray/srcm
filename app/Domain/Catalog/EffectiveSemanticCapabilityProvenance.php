<?php

namespace App\Domain\Catalog;

use InvalidArgumentException;

final readonly class EffectiveSemanticCapabilityProvenance
{
    public const RULE_EXACT_SCHEMA_CAPABILITY_DECLARATION =
        'declared_by_exact_schema_capability_declaration';

    public function __construct(
        public int $productSchemaVersionId,
        public int $productSchemaCapabilityDeclarationId,
        public int $semanticCapabilityDefinitionId,
        public string $semanticCapabilityKey,
        public string $resolutionRule =
            self::RULE_EXACT_SCHEMA_CAPABILITY_DECLARATION,
    ) {
        if (
            $this->productSchemaVersionId <= 0
            || $this->productSchemaCapabilityDeclarationId <= 0
            || $this->semanticCapabilityDefinitionId <= 0
            || $this->semanticCapabilityKey === ''
            || $this->resolutionRule
                !== self::RULE_EXACT_SCHEMA_CAPABILITY_DECLARATION
        ) {
            throw new InvalidArgumentException(
                'Effective semantic capability provenance identity is invalid.'
            );
        }
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'product_schema_version_id' => $this->productSchemaVersionId,
            'product_schema_capability_declaration_id' =>
                $this->productSchemaCapabilityDeclarationId,
            'semantic_capability_definition_id' =>
                $this->semanticCapabilityDefinitionId,
            'semantic_capability_key' => $this->semanticCapabilityKey,
            'resolution_rule' => $this->resolutionRule,
        ];
    }
}
