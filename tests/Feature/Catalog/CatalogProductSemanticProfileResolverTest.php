<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\CatalogProductDefinitionAssignmentManager;
use App\Domain\Catalog\CatalogProductSemanticProfileResolver;
use App\Domain\Catalog\ProductDefinitionManager;
use App\Domain\Catalog\ProductSchemaVersionManager;
use App\Enums\ProductDefinitionStatus;
use App\Models\CatalogProduct;
use App\Models\ProductCategory;
use App\Models\ProductDefinition;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogProductSemanticProfileResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_null_assignment_returns_explicit_unclassified_resolution(): void
    {
        $product = $this->product('unclassified');

        $resolution = app(
            CatalogProductSemanticProfileResolver::class
        )->current($product);

        $this->assertFalse(
            $resolution->classified
        );
        $this->assertNull(
            $resolution->productDefinitionId
        );
        $this->assertNull(
            $resolution->productDefinitionKey
        );
        $this->assertNull(
            $resolution->profile
        );
        $this->assertSame(
            'unclassified',
            $resolution->toArray()['classification_status']
        );
    }

    public function test_assigned_product_delegates_to_current_published_semantic_profile(): void
    {
        $product = $this->product('classified');
        $definition = $this->publishedDefinition(
            'resolver'
        );

        app(
            CatalogProductDefinitionAssignmentManager::class
        )->classify(
            $product,
            $definition
        );

        $resolution = app(
            CatalogProductSemanticProfileResolver::class
        )->current(
            $product->fresh()
        );

        $this->assertTrue(
            $resolution->classified
        );
        $this->assertSame(
            $definition->id,
            $resolution->productDefinitionId
        );
        $this->assertSame(
            $definition->key,
            $resolution->productDefinitionKey
        );
        $this->assertNotNull(
            $resolution->profile
        );
        $this->assertSame(
            $definition->id,
            $resolution->profile->productDefinitionId
        );
    }

    public function test_corrupt_retired_current_assignment_fails_closed(): void
    {
        $product = $this->product('corrupt');
        $definition = $this->publishedDefinition(
            'corrupt'
        );

        app(
            CatalogProductDefinitionAssignmentManager::class
        )->classify(
            $product,
            $definition
        );

        DB::table(
            'catalog_product_definitions'
        )
            ->where('id', $definition->id)
            ->update([
                'status' =>
                    ProductDefinitionStatus::Retired->value,
            ]);

        $this->expectException(
            DomainException::class
        );

        app(
            CatalogProductSemanticProfileResolver::class
        )->current(
            $product->fresh()
        );
    }

    private function product(
        string $suffix
    ): CatalogProduct {
        $category = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()->create([
                'name' => 'CSF5 resolver '.$suffix,
                'slug' => 'csf5-resolver-'.str_replace('_', '-', $suffix),
                'active' => true,
            ])
        );

        return CatalogProduct::withoutEvents(
            fn () => CatalogProduct::query()->create([
                'product_category_id' => $category->id,
                'sku' => 'CSF5-R-'.strtoupper($suffix),
                'name' => 'Producto resolver '.$suffix,
                'base_unit_code' => 'unit',
                'quantity_scale' => 0,
                'active' => true,
            ])->refresh()
        );
    }

    private function publishedDefinition(
        string $suffix
    ): ProductDefinition {
        $definition = app(
            ProductDefinitionManager::class
        )->create(
            'csf5.'.$suffix,
            'Definición resolver '.$suffix
        );

        $schema = app(
            ProductSchemaVersionManager::class
        )->createDraft(
            $definition,
            'Resolver '.$suffix
        );

        app(
            ProductSchemaVersionManager::class
        )->publish($schema);

        return $definition->fresh();
    }
}
