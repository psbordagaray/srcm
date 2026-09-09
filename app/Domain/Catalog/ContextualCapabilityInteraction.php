<?php

namespace App\Domain\Catalog;

use InvalidArgumentException;

final readonly class ContextualCapabilityInteraction
{
    /**
     * @param array<string, mixed> $policyProvenance
     * @param array<string, mixed>|null $authorizationEvidence
     */
    public function __construct(
        public int $semanticCapabilityDefinitionId,
        public string $semanticCapabilityKey,
        public bool $relevant,
        public bool $visible,
        public bool $enabled,
        public ?string $reasonCode,
        public ?string $explanationSource,
        public array $policyProvenance,
        public ?array $authorizationEvidence,
    ) {
        if (
            $this->semanticCapabilityDefinitionId <= 0
            || trim($this->semanticCapabilityKey) === ''
            || $this->policyProvenance === []
        ) {
            throw new InvalidArgumentException(
                'Contextual capability interaction identity is invalid.'
            );
        }

        if ($this->enabled && (! $this->relevant || ! $this->visible)) {
            throw new InvalidArgumentException(
                'Enabled contextual capability must be relevant and visible.'
            );
        }

        if (! $this->relevant && ($this->visible || $this->enabled)) {
            throw new InvalidArgumentException(
                'Irrelevant contextual capability cannot be visible or enabled.'
            );
        }

        if (
            (! $this->enabled || ! $this->visible)
            && ($this->reasonCode === null || trim($this->reasonCode) === '')
        ) {
            throw new InvalidArgumentException(
                'Disabled or hidden contextual capability requires a reason code.'
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
            'relevant' => $this->relevant,
            'visible' => $this->visible,
            'enabled' => $this->enabled,
            'reason_code' => $this->reasonCode,
            'explanation_source' => $this->explanationSource,
            'policy_provenance' => $this->policyProvenance,
            'authorization_evidence' => $this->authorizationEvidence,
        ];
    }
}
