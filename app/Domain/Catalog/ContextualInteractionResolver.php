<?php

namespace App\Domain\Catalog;

use App\Domain\Authorization\CapabilityAuthorizationContract;
use App\Domain\Authorization\CapabilityDecision;
use App\Enums\AttributeValueScope;
use DomainException;

final class ContextualInteractionResolver
{
    /**
     * @param list<EffectiveCatalogProductSemanticValue> $currentValues
     * @param list<EffectiveCapabilityPolicy> $capabilityPolicies
     * @param array<string, CapabilityAuthorizationContract> $authorizationByCapabilityKey
     * @param list<string> $requiredAttributeKeys
     * @param list<string> $optionalAttributeKeys
     * @param array<string, array{reason_code:string, explanation_source:?string, authority:string, provenance:array<string,mixed>}> $unavailableAttributes
     * @param list<ContextualActionInteraction> $actions
     */
    public function resolve(
        CatalogProductSemanticResolution $semanticResolution,
        ContextualOperationContext $context,
        array $currentValues = [],
        array $capabilityPolicies = [],
        array $authorizationByCapabilityKey = [],
        array $requiredAttributeKeys = [],
        array $optionalAttributeKeys = [],
        array $unavailableAttributes = [],
        array $actions = [],
    ): ContextualInteractionProfile {
        if ($semanticResolution->catalogProductId !== $context->catalogProductId) {
            throw new DomainException(
                'Contextual resolution product identity does not match operation context.'
            );
        }

        $actions = $this->sortedActions($actions);

        if (! $semanticResolution->classified) {
            if (
                $currentValues !== []
                || $capabilityPolicies !== []
                || $authorizationByCapabilityKey !== []
                || $requiredAttributeKeys !== []
                || $optionalAttributeKeys !== []
                || $unavailableAttributes !== []
            ) {
                throw new DomainException(
                    'Unclassified product cannot receive semantic-specific contextual inputs.'
                );
            }

            return new ContextualInteractionProfile(
                context: $context,
                classified: false,
                productDefinitionId: null,
                productDefinitionKey: null,
                productSchemaVersionId: null,
                attributes: [],
                capabilities: [],
                actions: $actions,
                provenance: [
                    'resolver' => 'contextual_interaction_v1',
                    'semantic_resolution' => $semanticResolution->toArray(),
                ],
            );
        }

        $profile = $semanticResolution->profile;

        if ($profile === null) {
            throw new DomainException(
                'Classified contextual resolution requires an effective semantic profile.'
            );
        }

        $productAttributes = [];

        foreach ($profile->attributes as $attribute) {
            if ($attribute->valueScope !== AttributeValueScope::Product) {
                continue;
            }

            if (isset($productAttributes[$attribute->attributeDefinitionKey])) {
                throw new DomainException(
                    'Product-scope contextual attribute identity is duplicated.'
                );
            }

            $productAttributes[$attribute->attributeDefinitionKey] = $attribute;
        }

        $required = $this->keySet($requiredAttributeKeys, 'required attribute');
        $optional = $this->keySet($optionalAttributeKeys, 'optional attribute');

        foreach ($required as $key => $_) {
            if (isset($optional[$key])) {
                throw new DomainException(
                    'An attribute cannot be both required-now and optional.'
                );
            }
        }

        foreach (array_keys($required + $optional + $unavailableAttributes) as $key) {
            if (! isset($productAttributes[$key])) {
                throw new DomainException(
                    "Contextual operation references unknown product attribute [{$key}]."
                );
            }
        }

        $valuesByBinding = [];

        foreach ($currentValues as $value) {
            if (! $value instanceof EffectiveCatalogProductSemanticValue) {
                throw new DomainException(
                    'Current semantic value input is not canonical.'
                );
            }

            if (
                $value->catalogProductId !== $context->catalogProductId
                || $value->productSchemaVersionId !== $profile->productSchemaVersionId
                || isset($valuesByBinding[$value->attributeBindingId])
            ) {
                throw new DomainException(
                    'Current semantic value authority is inconsistent with contextual subject.'
                );
            }

            $valuesByBinding[$value->attributeBindingId] = $value;
        }

        $attributeResults = [];
        ksort($productAttributes, SORT_STRING);

        foreach ($productAttributes as $key => $attribute) {
            $value = $valuesByBinding[$attribute->attributeBindingId] ?? null;
            $requiredNow = isset($required[$key]);
            $isOptional = isset($optional[$key]);
            $unavailable = $unavailableAttributes[$key] ?? null;

            if ($unavailable !== null) {
                $this->assertUnavailableFact($key, $unavailable);

                $attributeResults[] = new ContextualAttributeRequirement(
                    attributeBindingId: $attribute->attributeBindingId,
                    attributeDefinitionId: $attribute->attributeDefinitionId,
                    attributeDefinitionKey: $key,
                    relevant: true,
                    requiredNow: $requiredNow,
                    status: ContextualAttributeRequirement::STATUS_UNAVAILABLE,
                    visible: true,
                    reasonCode: $unavailable['reason_code'],
                    explanationSource: $unavailable['explanation_source'],
                    provenance: [
                        'attribute' => $attribute->provenance->toArray(),
                        'unavailable_fact' => [
                            'authority' => $unavailable['authority'],
                            'provenance' => $unavailable['provenance'],
                        ],
                    ],
                );
                continue;
            }

            if (! $requiredNow && ! $isOptional) {
                $attributeResults[] = new ContextualAttributeRequirement(
                    attributeBindingId: $attribute->attributeBindingId,
                    attributeDefinitionId: $attribute->attributeDefinitionId,
                    attributeDefinitionKey: $key,
                    relevant: false,
                    requiredNow: false,
                    status: ContextualAttributeRequirement::STATUS_NOT_APPLICABLE,
                    visible: false,
                    reasonCode: 'not_relevant_to_operation',
                    explanationSource: 'operation_context',
                    provenance: [
                        'attribute' => $attribute->provenance->toArray(),
                    ],
                );
                continue;
            }

            if ($value !== null) {
                $attributeResults[] = new ContextualAttributeRequirement(
                    attributeBindingId: $attribute->attributeBindingId,
                    attributeDefinitionId: $attribute->attributeDefinitionId,
                    attributeDefinitionKey: $key,
                    relevant: true,
                    requiredNow: $requiredNow,
                    status: ContextualAttributeRequirement::STATUS_SATISFIED,
                    visible: true,
                    reasonCode: null,
                    explanationSource: null,
                    provenance: [
                        'attribute' => $attribute->provenance->toArray(),
                        'current_value' => $value->toArray(),
                    ],
                );
                continue;
            }

            $attributeResults[] = new ContextualAttributeRequirement(
                attributeBindingId: $attribute->attributeBindingId,
                attributeDefinitionId: $attribute->attributeDefinitionId,
                attributeDefinitionKey: $key,
                relevant: true,
                requiredNow: $requiredNow,
                status: $requiredNow
                    ? ContextualAttributeRequirement::STATUS_MISSING_REQUIRED_NOW
                    : ContextualAttributeRequirement::STATUS_OPTIONAL,
                visible: true,
                reasonCode: $requiredNow
                    ? 'missing_current_semantic_value'
                    : null,
                explanationSource: $requiredNow
                    ? 'catalog_semantic_value_authority'
                    : null,
                provenance: [
                    'attribute' => $attribute->provenance->toArray(),
                ],
            );
        }

        foreach ($valuesByBinding as $bindingId => $value) {
            $matched = false;
            foreach ($productAttributes as $attribute) {
                if ($attribute->attributeBindingId === $bindingId) {
                    $matched = true;
                    break;
                }
            }

            if (! $matched) {
                throw new DomainException(
                    'Current semantic value does not belong to current product-scope profile.'
                );
            }
        }

        $capabilityResults = $this->capabilityInteractions(
            $profile,
            $context,
            $capabilityPolicies,
            $authorizationByCapabilityKey
        );

        return new ContextualInteractionProfile(
            context: $context,
            classified: true,
            productDefinitionId: $semanticResolution->productDefinitionId,
            productDefinitionKey: $semanticResolution->productDefinitionKey,
            productSchemaVersionId: $profile->productSchemaVersionId,
            attributes: $attributeResults,
            capabilities: $capabilityResults,
            actions: $actions,
            provenance: [
                'resolver' => 'contextual_interaction_v1',
                'semantic_resolution' => $semanticResolution->toArray(),
            ],
        );
    }

    /**
     * @param list<EffectiveCapabilityPolicy> $policies
     * @param array<string, CapabilityAuthorizationContract> $authorization
     * @return list<ContextualCapabilityInteraction>
     */
    private function capabilityInteractions(
        EffectiveSemanticProfile $profile,
        ContextualOperationContext $context,
        array $policies,
        array $authorization
    ): array {
        $profileCapabilityKeys = [];

        foreach ($profile->capabilities as $capability) {
            $profileCapabilityKeys[$capability->semanticCapabilityKey] = true;
        }

        $byKey = [];

        foreach ($policies as $policy) {
            if (! $policy instanceof EffectiveCapabilityPolicy) {
                throw new DomainException(
                    'Capability policy input is not canonical.'
                );
            }

            $key = $policy->semanticCapabilityKey;

            if (
                ! isset($profileCapabilityKeys[$key])
                || isset($byKey[$key])
                || $policy->provenance->productSchemaVersionId
                    !== $profile->productSchemaVersionId
                || $policy->provenance->organizationId
                    !== $context->organizationId
            ) {
                throw new DomainException(
                    'Capability policy authority is inconsistent with contextual subject.'
                );
            }

            $byKey[$key] = $policy;
        }

        if (count($byKey) !== count($profileCapabilityKeys)) {
            throw new DomainException(
                'Every effective semantic capability requires an effective policy authority.'
            );
        }

        foreach ($authorization as $key => $contract) {
            if (! is_string($key) || ! isset($byKey[$key])) {
                throw new DomainException(
                    'Authorization evidence references an unknown semantic capability.'
                );
            }

            if (! $contract instanceof CapabilityAuthorizationContract) {
                throw new DomainException(
                    'Authorization evidence is not canonical.'
                );
            }

            if (
                $contract->organizationId !== null
                && $contract->organizationId !== $context->organizationId
            ) {
                throw new DomainException(
                    'Authorization organization does not match contextual operation.'
                );
            }
        }

        ksort($byKey, SORT_STRING);
        $results = [];

        foreach ($byKey as $key => $policy) {
            $evidence = $authorization[$key] ?? null;

            if (! $policy->enabled) {
                $results[] = new ContextualCapabilityInteraction(
                    semanticCapabilityDefinitionId:
                        $policy->semanticCapabilityDefinitionId,
                    semanticCapabilityKey: $key,
                    relevant: false,
                    visible: false,
                    enabled: false,
                    reasonCode: 'effective_capability_disabled',
                    explanationSource: 'effective_capability_policy',
                    policyProvenance: $policy->provenance->toArray(),
                    authorizationEvidence: $evidence?->toArray(),
                );
                continue;
            }

            if ($evidence === null) {
                $results[] = new ContextualCapabilityInteraction(
                    semanticCapabilityDefinitionId:
                        $policy->semanticCapabilityDefinitionId,
                    semanticCapabilityKey: $key,
                    relevant: true,
                    visible: true,
                    enabled: false,
                    reasonCode: 'authorization_unresolved',
                    explanationSource: 'authorization_authority',
                    policyProvenance: $policy->provenance->toArray(),
                    authorizationEvidence: null,
                );
                continue;
            }

            $allowed = $evidence->decision === CapabilityDecision::Allow;

            $results[] = new ContextualCapabilityInteraction(
                semanticCapabilityDefinitionId:
                    $policy->semanticCapabilityDefinitionId,
                semanticCapabilityKey: $key,
                relevant: true,
                visible: true,
                enabled: $allowed,
                reasonCode: $allowed ? null : 'authorization_denied',
                explanationSource: $allowed ? null : 'authorization_authority',
                policyProvenance: $policy->provenance->toArray(),
                authorizationEvidence: $evidence->toArray(),
            );
        }

        return $results;
    }

    /** @param list<ContextualActionInteraction> $actions */
    private function sortedActions(array $actions): array
    {
        foreach ($actions as $action) {
            if (! $action instanceof ContextualActionInteraction) {
                throw new DomainException(
                    'Operation action fact is not a canonical contextual action projection.'
                );
            }
        }

        usort(
            $actions,
            static fn (
                ContextualActionInteraction $left,
                ContextualActionInteraction $right
            ): int => strcmp($left->actionKey, $right->actionKey)
        );

        $seen = [];
        foreach ($actions as $action) {
            if (isset($seen[$action->actionKey])) {
                throw new DomainException(
                    'Operation action identity is duplicated.'
                );
            }
            $seen[$action->actionKey] = true;
        }

        return $actions;
    }

    /** @param list<string> $keys @return array<string, true> */
    private function keySet(array $keys, string $label): array
    {
        $set = [];

        foreach ($keys as $key) {
            if (! is_string($key) || trim($key) === '' || isset($set[$key])) {
                throw new DomainException(
                    "Contextual {$label} identity is invalid or duplicated."
                );
            }
            $set[$key] = true;
        }

        return $set;
    }

    /**
     * @param array{reason_code:string, explanation_source:?string, authority:string, provenance:array<string,mixed>} $fact
     */
    private function assertUnavailableFact(string $key, array $fact): void
    {
        if (
            trim($fact['reason_code'] ?? '') === ''
            || trim($fact['authority'] ?? '') === ''
            || ! isset($fact['provenance'])
            || ! is_array($fact['provenance'])
            || $fact['provenance'] === []
            || (
                array_key_exists('explanation_source', $fact)
                && $fact['explanation_source'] !== null
                && ! is_string($fact['explanation_source'])
            )
        ) {
            throw new DomainException(
                "Unavailable contextual attribute fact [{$key}] is invalid."
            );
        }
    }
}
