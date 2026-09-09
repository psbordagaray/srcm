<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\ProductDefinitionManager;
use App\Domain\Catalog\ProductSchemaCapabilityManager;
use App\Domain\Catalog\ProductSchemaVersionManager;
use App\Domain\Catalog\SemanticCapabilityDefinitionManager;
use App\Enums\ProductSchemaStatus;
use App\Enums\SemanticCapabilityActivationMode;
use App\Models\ProductSchemaCapabilityDeclaration;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductSchemaCapabilityFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_declaration_is_draft_only_unique_and_fixed_enabled_requires_true_default(): void
    {
        $definition = $this->definition(
            'straleon.catalog.capability_draft'
        );
        $schemas = app(ProductSchemaVersionManager::class);
        $manager = app(ProductSchemaCapabilityManager::class);
        $capability = $this->capability(
            'inventory.serial_tracking'
        );

        $draft = $schemas->createDraft($definition);

        $declaration = $manager->declare(
            $draft,
            $capability,
            SemanticCapabilityActivationMode::FixedEnabled,
            true
        );

        $this->assertTrue($declaration->default_enabled);

        $this->assertDomainRejected(
            fn () => $manager->declare(
                $draft,
                $capability,
                SemanticCapabilityActivationMode::Configurable,
                false
            )
        );

        $other = $this->capability('inventory.expiry_tracking');

        $this->assertDomainRejected(
            fn () => $manager->declare(
                $draft,
                $other,
                SemanticCapabilityActivationMode::FixedEnabled,
                false
            )
        );

        $published = $schemas->publish($draft);

        $this->assertDomainRejected(
            fn () => $manager->reconfigure(
                $declaration,
                SemanticCapabilityActivationMode::Configurable,
                true
            )
        );

        $this->assertSame(
            ProductSchemaStatus::Published,
            $published->status
        );
    }

    public function test_next_draft_atomically_clones_published_capability_declarations(): void
    {
        $definition = $this->definition(
            'straleon.catalog.capability_clone'
        );
        $schemas = app(ProductSchemaVersionManager::class);
        $manager = app(ProductSchemaCapabilityManager::class);
        $capability = $this->capability(
            'inventory.rotation_policy'
        );

        $v1 = $schemas->createDraft($definition);

        $source = $manager->declare(
            $v1,
            $capability,
            SemanticCapabilityActivationMode::Configurable,
            true
        );

        $v1 = $schemas->publish($v1);
        $v2 = $schemas->createDraft($definition);

        $clone = ProductSchemaCapabilityDeclaration::query()
            ->where('product_schema_version_id', $v2->id)
            ->where(
                'semantic_capability_definition_id',
                $capability->id
            )
            ->firstOrFail();

        $this->assertNotSame($source->id, $clone->id);
        $this->assertSame(
            $source->semantic_capability_definition_id,
            $clone->semantic_capability_definition_id
        );
        $this->assertSame(
            $source->activation_mode,
            $clone->activation_mode
        );
        $this->assertSame(
            $source->default_enabled,
            $clone->default_enabled
        );
        $this->assertSame(
            ProductSchemaStatus::Published,
            $v1->fresh()->status
        );
    }

    public function test_republication_requires_active_capability_without_rewriting_published_history(): void
    {
        $definition = $this->definition(
            'straleon.catalog.capability_publication'
        );
        $schemas = app(ProductSchemaVersionManager::class);
        $manager = app(ProductSchemaCapabilityManager::class);
        $registry = app(SemanticCapabilityDefinitionManager::class);
        $capability = $this->capability(
            'inventory.lot_tracking'
        );

        $v1 = $schemas->createDraft($definition);

        $manager->declare(
            $v1,
            $capability,
            SemanticCapabilityActivationMode::Configurable,
            true
        );

        $v1 = $schemas->publish($v1);
        $registry->deprecate($capability);

        $v2 = $schemas->createDraft($definition);

        $this->assertDatabaseHas(
            'catalog_product_schema_capability_declarations',
            [
                'product_schema_version_id' => $v2->id,
                'semantic_capability_definition_id' =>
                    $capability->id,
            ]
        );

        $this->assertDomainRejected(
            fn () => $schemas->publish($v2)
        );

        $this->assertSame(
            ProductSchemaStatus::Published,
            $v1->fresh()->status
        );
        $this->assertSame(
            ProductSchemaStatus::Draft,
            $v2->fresh()->status
        );
    }

    private function definition(string $key)
    {
        return app(ProductDefinitionManager::class)->create(
            $key,
            'Capability schema'
        );
    }

    private function capability(string $key)
    {
        return app(SemanticCapabilityDefinitionManager::class)->create(
            $key,
            'Capability'
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
