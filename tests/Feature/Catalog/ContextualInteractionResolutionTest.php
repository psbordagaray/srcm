<?php

namespace Tests\Feature\Catalog;

use App\Domain\Authorization\Capability;
use App\Domain\Authorization\CapabilityAuthorizationContract;
use App\Domain\Authorization\CapabilityDecision;
use App\Domain\Authorization\CapabilityPrincipal;
use App\Domain\Authorization\CapabilityScope;
use App\Domain\Catalog\CatalogProductSemanticResolution;
use App\Domain\Catalog\ContextualActionInteraction;
use App\Domain\Catalog\ContextualAttributeRequirement;
use App\Domain\Catalog\ContextualInteractionResolver;
use App\Domain\Catalog\ContextualOperationContext;
use App\Domain\Catalog\EffectiveCapabilityPolicy;
use App\Domain\Catalog\EffectiveCapabilityPolicyProvenance;
use App\Domain\Catalog\EffectiveCatalogProductSemanticValue;
use App\Domain\Catalog\EffectiveSemanticAttribute;
use App\Domain\Catalog\EffectiveSemanticAttributeProvenance;
use App\Domain\Catalog\EffectiveSemanticCapability;
use App\Domain\Catalog\EffectiveSemanticCapabilityProvenance;
use App\Domain\Catalog\EffectiveSemanticProfile;
use App\Domain\Catalog\EffectiveSemanticProfileProvenance;
use App\Enums\AttributeValueScope;
use App\Enums\AttributeValueType;
use App\Enums\ProductSchemaStatus;
use App\Enums\SemanticCapabilityActivationMode;
use App\Enums\SemanticProfileResolutionMode;
use DomainException;
use Tests\TestCase;

class ContextualInteractionResolutionTest extends TestCase
{
    public function test_unclassified_product_remains_explicit_without_semantic_inference(): void
    {
        $resolution = new CatalogProductSemanticResolution(
            catalogProductId: 100,
            classified: false,
            productDefinitionId: null,
            productDefinitionKey: null,
            profile: null,
        );

        $action = new ContextualActionInteraction(
            actionKey: 'catalog.classify',
            visible: true,
            enabled: true,
            authority: 'catalog_product_definition_assignment',
            reasonCode: null,
            explanationSource: null,
            provenance: ['authority' => 'csf5_assignment_manager'],
        );

        $profile = (new ContextualInteractionResolver())->resolve(
            semanticResolution: $resolution,
            context: new ContextualOperationContext(
                operation: 'catalog.edit',
                catalogProductId: 100,
                organizationId: 7,
            ),
            actions: [$action],
        );

        $this->assertFalse($profile->classified);
        $this->assertSame([], $profile->attributes);
        $this->assertSame([], $profile->capabilities);
        $this->assertSame('unclassified', $profile->toArray()['classification_status']);
        $this->assertSame('catalog.classify', $profile->actions[0]->actionKey);
    }

    public function test_required_now_missing_value_is_contextual_not_schema_global(): void
    {
        $profile = $this->resolve(
            required: ['product.color'],
            optional: [],
        );

        $color = $profile->attributes[0];
        $note = $profile->attributes[1];

        $this->assertSame('product.color', $color->attributeDefinitionKey);
        $this->assertTrue($color->requiredNow);
        $this->assertSame(
            ContextualAttributeRequirement::STATUS_MISSING_REQUIRED_NOW,
            $color->status
        );
        $this->assertSame('missing_current_semantic_value', $color->reasonCode);

        $this->assertSame('product.note', $note->attributeDefinitionKey);
        $this->assertFalse($note->relevant);
        $this->assertFalse($note->visible);
        $this->assertSame(
            ContextualAttributeRequirement::STATUS_NOT_APPLICABLE,
            $note->status
        );
    }

    public function test_current_value_satisfies_requirement_with_exact_binding_identity(): void
    {
        $value = new EffectiveCatalogProductSemanticValue(
            catalogProductId: 100,
            productSchemaVersionId: 20,
            attributeBindingId: 1,
            attributeDefinitionId: 11,
            attributeDefinitionKey: 'product.color',
            valueType: AttributeValueType::Text,
            valueScope: AttributeValueScope::Product,
            value: 'red',
            measurementUnitId: null,
            measurementUnitKey: null,
            measurementDimensionId: null,
            measurementDimensionKey: null,
        );

        $first = $this->resolve(
            required: ['product.color'],
            optional: [],
            values: [$value],
        );
        $second = $this->resolve(
            required: ['product.color'],
            optional: [],
            values: [$value],
        );

        $color = $first->attributes[0];

        $this->assertSame(
            ContextualAttributeRequirement::STATUS_SATISFIED,
            $color->status
        );
        $this->assertSame(
            1,
            $color->provenance['current_value']['attribute_binding_id']
        );
        $this->assertSame('red', $color->provenance['current_value']['value']);
        $this->assertSame($first->toArray(), $second->toArray());
    }

    public function test_disabled_effective_capability_is_hidden_with_policy_provenance(): void
    {
        $profile = $this->resolve(
            required: [],
            optional: [],
            policies: [$this->policy(false)],
            withCapability: true,
        );

        $capability = $profile->capabilities[0];

        $this->assertFalse($capability->relevant);
        $this->assertFalse($capability->visible);
        $this->assertFalse($capability->enabled);
        $this->assertSame('effective_capability_disabled', $capability->reasonCode);
        $this->assertSame(
            20,
            $capability->policyProvenance['product_schema_version_id']
        );
    }

    public function test_authorization_deny_never_becomes_enabled_and_allow_preserves_evidence(): void
    {
        $denied = $this->resolve(
            required: [],
            optional: [],
            policies: [$this->policy(true)],
            authorization: [
                'inventory.fractional_container' => $this->authorization(false),
            ],
            withCapability: true,
        );

        $this->assertFalse($denied->capabilities[0]->enabled);
        $this->assertSame(
            'authorization_denied',
            $denied->capabilities[0]->reasonCode
        );

        $allowed = $this->resolve(
            required: [],
            optional: [],
            policies: [$this->policy(true)],
            authorization: [
                'inventory.fractional_container' => $this->authorization(true),
            ],
            withCapability: true,
        );

        $this->assertTrue($allowed->capabilities[0]->enabled);
        $this->assertSame(
            'ALLOW',
            $allowed->capabilities[0]
                ->authorizationEvidence['decision']
        );
        $this->assertSame(
            'test_authority',
            $allowed->capabilities[0]
                ->authorizationEvidence['authorization_source']
        );
    }

    public function test_unknown_attribute_authority_fails_closed(): void
    {
        $this->expectException(DomainException::class);

        $this->resolve(
            required: ['product.unknown'],
            optional: [],
        );
    }

    public function test_tenant_mismatch_in_effective_policy_fails_closed(): void
    {
        $this->expectException(DomainException::class);

        $this->resolve(
            required: [],
            optional: [],
            policies: [$this->policy(true, 8)],
            withCapability: true,
        );
    }

    public function test_operation_action_fact_preserves_owning_authority_and_reason(): void
    {
        $action = new ContextualActionInteraction(
            actionKey: 'inventory.reserve',
            visible: true,
            enabled: false,
            authority: 'commercial_availability_reader',
            reasonCode: 'insufficient_commercial_availability',
            explanationSource: 'commerce_availability',
            provenance: ['position_id' => 'product:100/location:3'],
        );

        $profile = $this->resolve(
            required: [],
            optional: [],
            actions: [$action],
        );

        $this->assertFalse($profile->actions[0]->enabled);
        $this->assertSame(
            'commercial_availability_reader',
            $profile->actions[0]->authority
        );
        $this->assertSame(
            'insufficient_commercial_availability',
            $profile->actions[0]->reasonCode
        );
    }

    /**
     * @param list<string> $required
     * @param list<string> $optional
     * @param list<EffectiveCatalogProductSemanticValue> $values
     * @param list<EffectiveCapabilityPolicy> $policies
     * @param array<string, CapabilityAuthorizationContract> $authorization
     * @param list<ContextualActionInteraction> $actions
     */
    private function resolve(
        array $required,
        array $optional,
        array $values = [],
        array $policies = [],
        array $authorization = [],
        bool $withCapability = false,
        array $actions = [],
    ) {
        $semanticProfile = $this->semanticProfile($withCapability);

        $semantic = new CatalogProductSemanticResolution(
            catalogProductId: 100,
            classified: true,
            productDefinitionId: 10,
            productDefinitionKey: 'test.product',
            profile: $semanticProfile,
        );

        return (new ContextualInteractionResolver())->resolve(
            semanticResolution: $semantic,
            context: new ContextualOperationContext(
                operation: 'catalog.edit',
                catalogProductId: 100,
                organizationId: 7,
            ),
            currentValues: $values,
            capabilityPolicies: $policies,
            authorizationByCapabilityKey: $authorization,
            requiredAttributeKeys: $required,
            optionalAttributeKeys: $optional,
            actions: $actions,
        );
    }

    private function semanticProfile(bool $withCapability): EffectiveSemanticProfile
    {
        $attributes = [
            $this->attribute(1, 11, 'product.color'),
            $this->attribute(2, 12, 'product.note'),
        ];

        $capabilities = $withCapability
            ? [$this->capability()]
            : [];

        $provenance = new EffectiveSemanticProfileProvenance(
            resolutionMode: SemanticProfileResolutionMode::CurrentPublished,
            requestedAuthorityType:
                EffectiveSemanticProfileProvenance::AUTHORITY_PRODUCT_DEFINITION,
            requestedAuthorityId: 10,
            productDefinitionId: 10,
            productDefinitionKey: 'test.product',
            productSchemaVersionId: 20,
            selectionRule:
                EffectiveSemanticProfileProvenance::RULE_CURRENT_PUBLISHED,
        );

        return new EffectiveSemanticProfile(
            resolutionMode: SemanticProfileResolutionMode::CurrentPublished,
            productDefinitionId: 10,
            productDefinitionKey: 'test.product',
            productSchemaVersionId: 20,
            schemaVersion: 1,
            schemaStatus: ProductSchemaStatus::Published,
            publishedAt: '2026-09-09T00:00:00+00:00',
            attributes: $attributes,
            provenance: $provenance,
            capabilities: $capabilities,
        );
    }

    private function attribute(
        int $bindingId,
        int $definitionId,
        string $key
    ): EffectiveSemanticAttribute {
        $provenance = new EffectiveSemanticAttributeProvenance(
            productSchemaVersionId: 20,
            attributeBindingId: $bindingId,
            attributeDefinitionId: $definitionId,
            attributeDefinitionKey: $key,
            measurementUnitId: null,
            measurementUnitKey: null,
            measurementDimensionId: null,
            measurementDimensionKey: null,
        );

        return new EffectiveSemanticAttribute(
            attributeBindingId: $bindingId,
            attributeDefinitionId: $definitionId,
            attributeDefinitionKey: $key,
            valueType: AttributeValueType::Text,
            valueScope: AttributeValueScope::Product,
            measurementUnitId: null,
            measurementUnitKey: null,
            measurementDimensionId: null,
            measurementDimensionKey: null,
            provenance: $provenance,
        );
    }

    private function capability(): EffectiveSemanticCapability
    {
        $provenance = new EffectiveSemanticCapabilityProvenance(
            productSchemaVersionId: 20,
            productSchemaCapabilityDeclarationId: 30,
            semanticCapabilityDefinitionId: 40,
            semanticCapabilityKey: 'inventory.fractional_container',
        );

        return new EffectiveSemanticCapability(
            declarationId: 30,
            semanticCapabilityDefinitionId: 40,
            semanticCapabilityKey: 'inventory.fractional_container',
            activationMode: SemanticCapabilityActivationMode::Configurable,
            provenance: $provenance,
        );
    }

    private function policy(
        bool $enabled,
        int $organizationId = 7
    ): EffectiveCapabilityPolicy {
        $provenance = new EffectiveCapabilityPolicyProvenance(
            productSchemaVersionId: 20,
            productSchemaCapabilityDeclarationId: 30,
            semanticCapabilityDefinitionId: 40,
            semanticCapabilityKey: 'inventory.fractional_container',
            activationMode: SemanticCapabilityActivationMode::Configurable,
            schemaDefaultEnabled: $enabled,
            organizationId: $organizationId,
            organizationPolicyBindingId: null,
            resolvedFrom:
                EffectiveCapabilityPolicyProvenance::RESOLVED_SCHEMA_DEFAULT,
        );

        return new EffectiveCapabilityPolicy(
            semanticCapabilityDefinitionId: 40,
            semanticCapabilityKey: 'inventory.fractional_container',
            activationMode: SemanticCapabilityActivationMode::Configurable,
            enabled: $enabled,
            resolvedFrom:
                EffectiveCapabilityPolicyProvenance::RESOLVED_SCHEMA_DEFAULT,
            schemaDefaultEnabled: $enabled,
            organizationPolicyBindingId: null,
            provenance: $provenance,
        );
    }

    private function authorization(bool $allowed): CapabilityAuthorizationContract
    {
        return new CapabilityAuthorizationContract(
            capability: new Capability('catalog.semantic.interact'),
            principalType: CapabilityPrincipal::User,
            principalId: '42',
            scopeType: CapabilityScope::Organization,
            scopeId: '7',
            environmentId: null,
            organizationId: 7,
            releaseSha: null,
            decision: $allowed
                ? CapabilityDecision::Allow
                : CapabilityDecision::Deny,
            authorizationSource: $allowed ? 'test_authority' : null,
            evidenceRef: $allowed ? 'test:evidence:42' : null,
        );
    }
}
