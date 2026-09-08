<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\ProductDefinitionManager;
use App\Domain\Catalog\ProductSchemaVersionManager;
use App\Enums\ProductDefinitionStatus;
use App\Models\ProductDefinition;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductDefinitionFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_definition_can_be_created_with_stable_semantic_identity(): void
    {
        $definition = app(ProductDefinitionManager::class)->create(
            'straleon.catalog.tyre',
            'Cubierta',
            'Neumático para vehículos.'
        );

        $this->assertSame('straleon.catalog.tyre', $definition->key);
        $this->assertSame('Cubierta', $definition->name);
        $this->assertSame(
            ProductDefinitionStatus::Active,
            $definition->status
        );
        $this->assertDatabaseHas('catalog_product_definitions', [
            'id' => $definition->id,
            'key' => 'straleon.catalog.tyre',
            'status' => 'active',
        ]);
    }

    public function test_definition_key_is_globally_unique(): void
    {
        $manager = app(ProductDefinitionManager::class);

        $manager->create(
            'straleon.catalog.smartphone',
            'Smartphone'
        );

        $this->assertDomainRejected(
            fn () => $manager->create(
                'straleon.catalog.smartphone',
                'Otro smartphone'
            )
        );

        $this->assertDatabaseCount('catalog_product_definitions', 1);
    }

    public function test_invalid_definition_key_is_rejected_without_normalization(): void
    {
        $manager = app(ProductDefinitionManager::class);

        foreach ([
            'Smartphone',
            'straleon catalog smartphone',
            'straleon..smartphone',
            'straleon.catalog.smart-phone',
            'smartphone',
        ] as $key) {
            $this->assertDomainRejected(
                fn () => $manager->create($key, 'Inválida')
            );
        }

        $this->assertDatabaseCount('catalog_product_definitions', 0);
    }

    public function test_definition_key_is_immutable(): void
    {
        $definition = $this->definition();

        $this->assertDomainRejected(function () use ($definition): void {
            $definition->key = 'straleon.catalog.other';
            $definition->save();
        });

        $this->assertSame(
            'straleon.catalog.test_product',
            $definition->fresh()->key
        );
    }

    public function test_definition_lifecycle_is_forward_only(): void
    {
        $manager = app(ProductDefinitionManager::class);
        $definition = $this->definition();

        $definition = $manager->deprecate($definition);

        $this->assertSame(
            ProductDefinitionStatus::Deprecated,
            $definition->status
        );

        $definition = $manager->retire($definition);

        $this->assertSame(
            ProductDefinitionStatus::Retired,
            $definition->status
        );
    }

    public function test_definition_reverse_transition_is_rejected(): void
    {
        $manager = app(ProductDefinitionManager::class);
        $definition = $manager->deprecate($this->definition());

        $this->assertDomainRejected(function () use ($definition): void {
            $definition->status = ProductDefinitionStatus::Active;
            $definition->save();
        });
    }

    public function test_deprecated_definition_allows_metadata_correction_but_retired_does_not(): void
    {
        $manager = app(ProductDefinitionManager::class);
        $definition = $manager->deprecate($this->definition());

        $definition = $manager->updateMetadata(
            $definition,
            'Nombre corregido',
            'Descripción corregida'
        );

        $this->assertSame('Nombre corregido', $definition->name);

        $definition = $manager->retire($definition);

        $this->assertDomainRejected(
            fn () => $manager->updateMetadata(
                $definition,
                'No permitido'
            )
        );
    }

    public function test_retired_definition_cannot_open_new_schema_version(): void
    {
        $definitionManager = app(ProductDefinitionManager::class);
        $schemaManager = app(ProductSchemaVersionManager::class);

        $definition = $definitionManager->deprecate(
            $this->definition()
        );
        $definition = $definitionManager->retire($definition);

        $this->assertDomainRejected(
            fn () => $schemaManager->createDraft($definition)
        );
    }

    public function test_definition_cannot_be_physically_deleted(): void
    {
        $definition = $this->definition();

        $this->assertDomainRejected(
            fn () => $definition->delete()
        );

        $this->assertDatabaseHas('catalog_product_definitions', [
            'id' => $definition->id,
        ]);
    }

    public function test_definition_registry_has_no_organization_category_or_product_assignment_columns(): void
    {
        $columns = Schema::getColumnListing(
            'catalog_product_definitions'
        );

        foreach ([
            'organization_id',
            'product_category_id',
            'catalog_product_id',
            'current_schema_version_id',
        ] as $forbidden) {
            $this->assertNotContains($forbidden, $columns);
        }

        $this->assertFalse(
            Schema::hasColumn(
                'catalog_products',
                'product_definition_id'
            )
        );
    }

    private function definition(): ProductDefinition
    {
        return app(ProductDefinitionManager::class)->create(
            'straleon.catalog.test_product',
            'Producto de prueba'
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
