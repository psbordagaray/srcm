<?php

namespace App\Domain\Catalog;

use App\Enums\SemanticCapabilityActivationMode;
use InvalidArgumentException;

final readonly class EffectiveCapabilityPolicyProvenance
{
    public const RESOLVED_SCHEMA_FIXED = 'schema_fixed';
    public const RESOLVED_SCHEMA_DEFAULT = 'schema_default';
    public const RESOLVED_ORGANIZATION_OVERRIDE =
        'organization_override';

    public function __construct(
        public int $productSchemaVersionId,
        public int $productSchemaCapabilityDeclarationId,
        public int $semanticCapabilityDefinitionId,
        public string $semanticCapabilityKey,
        public SemanticCapabilityActivationMode $activationMode,
        public bool $schemaDefaultEnabled,
        public ?int $organizationId,
        public ?int $organizationPolicyBindingId,
        public string $resolvedFrom,
    ) {
        if (
            $this->productSchemaVersionId <= 0
            || $this->productSchemaCapabilityDeclarationId <= 0
            || $this->semanticCapabilityDefinitionId <= 0
            || $this->semanticCapabilityKey === ''
            || ! in_array(
                $this->resolvedFrom,
                [
                    self::RESOLVED_SCHEMA_FIXED,
                    self::RESOLVED_SCHEMA_DEFAULT,
                    self::RESOLVED_ORGANIZATION_OVERRIDE,
                ],
                true
            )
        ) {
            throw new InvalidArgumentException(
                'Effective capability policy provenance identity is invalid.'
            );
        }

        if (
            $this->resolvedFrom
                === self::RESOLVED_ORGANIZATION_OVERRIDE
        ) {
            if (
                $this->organizationId === null
                || $this->organizationId <= 0
                || $this->organizationPolicyBindingId === null
                || $this->organizationPolicyBindingId <= 0
            ) {
                throw new InvalidArgumentException(
                    'Organization override provenance requires organization and binding identity.'
                );
            }

            return;
        }

        if ($this->organizationPolicyBindingId !== null) {
            throw new InvalidArgumentException(
                'Schema-level resolution cannot reference an organization policy binding.'
            );
        }

        if (
            $this->organizationId !== null
            && $this->organizationId <= 0
        ) {
            throw new InvalidArgumentException(
                'Organization identity is invalid.'
            );
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'product_schema_version_id' => $this->productSchemaVersionId,
            'product_schema_capability_declaration_id' =>
                $this->productSchemaCapabilityDeclarationId,
            'semantic_capability_definition_id' =>
                $this->semanticCapabilityDefinitionId,
            'semantic_capability_key' => $this->semanticCapabilityKey,
            'activation_mode' => $this->activationMode->value,
            'schema_default_enabled' => $this->schemaDefaultEnabled,
            'organization_id' => $this->organizationId,
            'organization_policy_binding_id' =>
                $this->organizationPolicyBindingId,
            'resolved_from' => $this->resolvedFrom,
        ];
    }
}
