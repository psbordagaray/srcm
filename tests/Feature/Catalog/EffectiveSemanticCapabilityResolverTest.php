<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\EffectiveSemanticProfileResolver;
use App\Domain\Catalog\ProductDefinitionManager;
use App\Domain\Catalog\ProductSchemaCapabilityManager;
use App\Domain\Catalog\ProductSchemaVersionManager;
use App\Domain\Catalog\SemanticCapabilityDefinitionManager;
use App\Enums\SemanticCapabilityActivationMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EffectiveSemanticCapabilityResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_resolves_exact_schema_capabilities_in_deterministic_key_order(): void
    {
        $definition = app(ProductDefinitionManager::class)->create(
            'straleon.catalog.capability_profile',
            'Capability profile'
        );

        $schemas = app(ProductSchemaVersionManager::class);
        $registry = app(SemanticCapabilityDefinitionManager::class);
        $manager = app(ProductSchemaCapabilityManager::class);

        $zeta = $registry->create(
            'inventory.rotation_policy',
            'Rotation'
        );
        $alpha = $registry->create(
            'inventory.expiry_tracking',
            'Expiry'
        );

        $draft = $schemas->createDraft($definition);

        $manager->declare(
            $draft,
            $zeta,
            SemanticCapabilityActivationMode::Configurable,
            false
        );
        $manager->declare(
            $draft,
            $alpha,
            SemanticCapabilityActivationMode::FixedEnabled,
            true
        );

        $published = $schemas->publish($draft);

        $profile = app(EffectiveSemanticProfileResolver::class)
            ->currentPublished($definition);

        $this->assertSame(
            [
                'inventory.expiry_tracking',
                'inventory.rotation_policy',
            ],
            array_map(
                static fn ($capability) =>
                    $capability->semanticCapabilityKey,
                $profile->capabilities
            )
        );

        $this->assertSame(
            $published->id,
            $profile->capabilities[0]
                ->provenance
                ->productSchemaVersionId
        );

        $array = $profile->toArray();
        $this->assertArrayHasKey('capabilities', $array);
        $this->assertCount(2, $array['capabilities']);
    }

    public function test_exact_historical_capabilities_survive_registry_deprecation_and_retirement(): void
    {
        $definition = app(ProductDefinitionManager::class)->create(
            'straleon.catalog.capability_history',
            'Capability history'
        );

        $schemas = app(ProductSchemaVersionManager::class);
        $registry = app(SemanticCapabilityDefinitionManager::class);
        $manager = app(ProductSchemaCapabilityManager::class);

        $capability = $registry->create(
            'inventory.serial_tracking',
            'Serial'
        );

        $draft = $schemas->createDraft($definition);

        $manager->declare(
            $draft,
            $capability,
            SemanticCapabilityActivationMode::FixedEnabled,
            true
        );

        $published = $schemas->publish($draft);

        $registry->retire(
            $registry->deprecate($capability)
        );

        $profile = app(EffectiveSemanticProfileResolver::class)
            ->exactHistorical($published);

        $this->assertCount(1, $profile->capabilities);
        $this->assertSame(
            'inventory.serial_tracking',
            $profile->capabilities[0]->semanticCapabilityKey
        );
    }
}
