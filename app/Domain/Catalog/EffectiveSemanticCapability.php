<?php

namespace App\Domain\Catalog;

use App\Enums\SemanticCapabilityActivationMode;
use InvalidArgumentException;

final readonly class EffectiveSemanticCapability
{
    public function __construct(
        public int $declarationId,
        public int $semanticCapabilityDefinitionId,
        public string $semanticCapabilityKey,
        public SemanticCapabilityActivationMode $activationMode,
        public EffectiveSemanticCapabilityProvenance $provenance,
    ) {
        if (
            $this->declarationId <= 0
            || $this->semanticCapabilityDefinitionId <= 0
            || $this->semanticCapabilityKey === ''
            || $this->provenance
                ->productSchemaCapabilityDeclarationId
                !== $this->declarationId
            || $this->provenance
                ->semanticCapabilityDefinitionId
                !== $this->semanticCapabilityDefinitionId
            || $this->provenance
                ->semanticCapabilityKey
                !== $this->semanticCapabilityKey
        ) {
            throw new InvalidArgumentException(
                'Effective semantic capability identity is invalid.'
            );
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'declaration_id' => $this->declarationId,
            'semantic_capability_definition_id' =>
                $this->semanticCapabilityDefinitionId,
            'semantic_capability_key' => $this->semanticCapabilityKey,
            'activation_mode' => $this->activationMode->value,
            'provenance' => $this->provenance->toArray(),
        ];
    }
}
