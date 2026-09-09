<?php

namespace App\Domain\Catalog;

use App\Enums\SemanticCapabilityActivationMode;
use InvalidArgumentException;

final readonly class EffectiveCapabilityPolicy
{
    public function __construct(
        public int $semanticCapabilityDefinitionId,
        public string $semanticCapabilityKey,
        public SemanticCapabilityActivationMode $activationMode,
        public bool $enabled,
        public string $resolvedFrom,
        public bool $schemaDefaultEnabled,
        public ?int $organizationPolicyBindingId,
        public EffectiveCapabilityPolicyProvenance $provenance,
    ) {
        if (
            $this->semanticCapabilityDefinitionId <= 0
            || $this->semanticCapabilityKey === ''
            || $this->provenance->semanticCapabilityDefinitionId
                !== $this->semanticCapabilityDefinitionId
            || $this->provenance->semanticCapabilityKey
                !== $this->semanticCapabilityKey
            || $this->provenance->activationMode
                !== $this->activationMode
            || $this->provenance->resolvedFrom
                !== $this->resolvedFrom
            || $this->provenance->schemaDefaultEnabled
                !== $this->schemaDefaultEnabled
            || $this->provenance->organizationPolicyBindingId
                !== $this->organizationPolicyBindingId
        ) {
            throw new InvalidArgumentException(
                'Effective capability policy is inconsistent.'
            );
        }

        if (
            $this->activationMode
                === SemanticCapabilityActivationMode::FixedEnabled
            && (
                ! $this->enabled
                || ! $this->schemaDefaultEnabled
                || $this->resolvedFrom
                    !== EffectiveCapabilityPolicyProvenance::
                        RESOLVED_SCHEMA_FIXED
            )
        ) {
            throw new InvalidArgumentException(
                'FIXED_ENABLED policy must resolve from schema_fixed as enabled.'
            );
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'semantic_capability_definition_id' =>
                $this->semanticCapabilityDefinitionId,
            'semantic_capability_key' => $this->semanticCapabilityKey,
            'activation_mode' => $this->activationMode->value,
            'enabled' => $this->enabled,
            'resolved_from' => $this->resolvedFrom,
            'schema_default_enabled' => $this->schemaDefaultEnabled,
            'organization_policy_binding_id' =>
                $this->organizationPolicyBindingId,
            'provenance' => $this->provenance->toArray(),
        ];
    }
}
