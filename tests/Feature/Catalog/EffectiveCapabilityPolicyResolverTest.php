<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\EffectiveCapabilityPolicyProvenance;
use App\Domain\Catalog\EffectiveCapabilityPolicyResolver;
use App\Domain\Catalog\EffectiveSemanticProfileResolver;
use App\Domain\Catalog\OrganizationCapabilityPolicyManager;
use App\Domain\Catalog\ProductDefinitionManager;
use App\Domain\Catalog\ProductSchemaCapabilityManager;
use App\Domain\Catalog\ProductSchemaVersionManager;
use App\Domain\Catalog\SemanticCapabilityDefinitionManager;
use App\Enums\SemanticCapabilityActivationMode;
use App\Models\Organization;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EffectiveCapabilityPolicyResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_fixed_enabled_ignores_organization_binding(): void
    {
        [$profile, $capability] = $this->profile(
            'straleon.catalog.policy_fixed',
            'inventory.serial_tracking',
            SemanticCapabilityActivationMode::FixedEnabled,
            true
        );

        $organization = $this->organization('Policy fixed');

        app(OrganizationCapabilityPolicyManager::class)->set(
            $organization,
            $capability,
            false
        );

        $policy = app(EffectiveCapabilityPolicyResolver::class)
            ->currentForOrganization(
                $profile,
                $organization
            )[0];

        $this->assertTrue($policy->enabled);
        $this->assertSame(
            EffectiveCapabilityPolicyProvenance::
                RESOLVED_SCHEMA_FIXED,
            $policy->resolvedFrom
        );
        $this->assertNull(
            $policy->organizationPolicyBindingId
        );
    }

    public function test_configurable_uses_default_override_and_reset_inherit(): void
    {
        [$profile, $capability] = $this->profile(
            'straleon.catalog.policy_configurable',
            'inventory.rotation_policy',
            SemanticCapabilityActivationMode::Configurable,
            true
        );

        $organization = $this->organization(
            'Policy configurable'
        );
        $resolver = app(EffectiveCapabilityPolicyResolver::class);
        $manager = app(OrganizationCapabilityPolicyManager::class);

        $default = $resolver->currentForOrganization(
            $profile,
            $organization
        )[0];

        $this->assertTrue($default->enabled);
        $this->assertSame(
            EffectiveCapabilityPolicyProvenance::
                RESOLVED_SCHEMA_DEFAULT,
            $default->resolvedFrom
        );

        $binding = $manager->set(
            $organization,
            $capability,
            false
        );

        $overridden = $resolver->currentForOrganization(
            $profile,
            $organization
        )[0];

        $this->assertFalse($overridden->enabled);
        $this->assertSame(
            EffectiveCapabilityPolicyProvenance::
                RESOLVED_ORGANIZATION_OVERRIDE,
            $overridden->resolvedFrom
        );
        $this->assertSame(
            $binding->id,
            $overridden->organizationPolicyBindingId
        );

        $manager->reset($organization, $capability);

        $reset = $resolver->currentForOrganization(
            $profile,
            $organization
        )[0];

        $this->assertTrue($reset->enabled);
        $this->assertSame(
            EffectiveCapabilityPolicyProvenance::
                RESOLVED_SCHEMA_DEFAULT,
            $reset->resolvedFrom
        );
    }

    public function test_policy_cannot_manufacture_undeclared_applicability(): void
    {
        $definition = app(ProductDefinitionManager::class)->create(
            'straleon.catalog.policy_dormant',
            'Policy dormant'
        );

        $schemas = app(ProductSchemaVersionManager::class);
        $published = $schemas->publish(
            $schemas->createDraft($definition)
        );

        $capability = app(
            SemanticCapabilityDefinitionManager::class
        )->create(
            'inventory.expiry_tracking',
            'Expiry'
        );

        $organization = $this->organization('Policy dormant');

        app(OrganizationCapabilityPolicyManager::class)->set(
            $organization,
            $capability,
            true
        );

        $profile = app(EffectiveSemanticProfileResolver::class)
            ->currentPublished($definition);

        $this->assertSame($published->id, $profile->productSchemaVersionId);
        $this->assertCount(0, $profile->capabilities);
        $this->assertCount(
            0,
            app(EffectiveCapabilityPolicyResolver::class)
                ->currentForOrganization(
                    $profile,
                    $organization
                )
        );
    }

    public function test_current_organization_policy_is_not_applied_to_exact_historical_profile(): void
    {
        [$current, $capability, $published] = $this->profile(
            'straleon.catalog.policy_history',
            'inventory.lot_tracking',
            SemanticCapabilityActivationMode::Configurable,
            false,
            true
        );

        $organization = $this->organization('Policy history');

        app(OrganizationCapabilityPolicyManager::class)->set(
            $organization,
            $capability,
            true
        );

        $historical = app(EffectiveSemanticProfileResolver::class)
            ->exactHistorical($published);

        $resolver = app(EffectiveCapabilityPolicyResolver::class);

        $this->assertDomainRejected(
            fn () => $resolver->currentForOrganization(
                $historical,
                $organization
            )
        );

        $schemaOnly = $resolver->schemaDefaults($historical);

        $this->assertFalse($schemaOnly[0]->enabled);
        $this->assertSame(
            EffectiveCapabilityPolicyProvenance::
                RESOLVED_SCHEMA_DEFAULT,
            $schemaOnly[0]->resolvedFrom
        );
        $this->assertNull(
            $schemaOnly[0]->provenance->organizationId
        );

        $this->assertCount(1, $current->capabilities);
    }

    private function profile(
        string $definitionKey,
        string $capabilityKey,
        SemanticCapabilityActivationMode $mode,
        bool $defaultEnabled,
        bool $includePublished = false
    ): array {
        $definition = app(ProductDefinitionManager::class)->create(
            $definitionKey,
            'Policy profile'
        );

        $schemas = app(ProductSchemaVersionManager::class);
        $capability = app(
            SemanticCapabilityDefinitionManager::class
        )->create(
            $capabilityKey,
            'Policy capability'
        );

        $draft = $schemas->createDraft($definition);

        app(ProductSchemaCapabilityManager::class)->declare(
            $draft,
            $capability,
            $mode,
            $defaultEnabled
        );

        $published = $schemas->publish($draft);
        $profile = app(EffectiveSemanticProfileResolver::class)
            ->currentPublished($definition);

        return $includePublished
            ? [$profile, $capability, $published]
            : [$profile, $capability];
    }

    private function organization(string $name): Organization
    {
        return Organization::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'active' => true,
        ]);
    }

    private function assertDomainRejected(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Se esperaba DomainException.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }
    }
}
