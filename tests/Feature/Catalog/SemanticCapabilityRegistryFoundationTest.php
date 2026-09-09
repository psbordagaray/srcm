<?php

namespace Tests\Feature\Catalog;

use App\Domain\Authorization\Capability as AuthorizationCapability;
use App\Domain\Catalog\SemanticCapabilityDefinitionManager;
use App\Enums\SemanticCapabilityStatus;
use App\Models\SemanticCapabilityDefinition;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SemanticCapabilityRegistryFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_key_lifecycle_and_metadata_rules_are_enforced(): void
    {
        $manager = app(SemanticCapabilityDefinitionManager::class);

        $capability = $manager->create(
            'inventory.serial_tracking',
            'Serial tracking',
            'Tracks unique serial identity.'
        );

        $this->assertSame(
            SemanticCapabilityStatus::Active,
            $capability->status
        );

        $updated = $manager->updateMetadata(
            $capability,
            'Serial tracking updated'
        );

        $this->assertSame(
            'inventory.serial_tracking',
            $updated->key
        );

        $deprecated = $manager->deprecate($updated);
        $this->assertSame(
            SemanticCapabilityStatus::Deprecated,
            $deprecated->status
        );

        $retired = $manager->retire($deprecated);
        $this->assertSame(
            SemanticCapabilityStatus::Retired,
            $retired->status
        );

        $this->assertDomainRejected(
            fn () => $manager->updateMetadata(
                $retired,
                'Forbidden'
            )
        );
    }

    public function test_duplicate_invalid_key_key_mutation_and_physical_delete_fail_closed(): void
    {
        $manager = app(SemanticCapabilityDefinitionManager::class);
        $capability = $manager->create(
            'inventory.lot_tracking',
            'Lot tracking'
        );

        $this->assertDomainRejected(
            fn () => $manager->create(
                'inventory.lot_tracking',
                'Duplicate'
            )
        );

        $this->assertDomainRejected(
            fn () => $manager->create(
                'not namespaced',
                'Invalid'
            )
        );

        $this->assertDomainRejected(function () use ($capability): void {
            $capability->key = 'inventory.changed';
            $capability->save();
        });

        $this->assertDomainRejected(
            fn () => $capability->delete()
        );
    }

    public function test_semantic_capability_identity_is_not_authorization_capability(): void
    {
        $this->assertNotSame(
            SemanticCapabilityDefinition::class,
            AuthorizationCapability::class
        );
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
