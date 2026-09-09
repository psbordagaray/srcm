<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\AttributeBindingManager;
use App\Domain\Catalog\AttributeDefinitionManager;
use App\Domain\Catalog\CatalogProductDefinitionAssignmentManager;
use App\Domain\Catalog\CatalogProductSemanticValueManager;
use App\Domain\Catalog\ProductDefinitionManager;
use App\Domain\Catalog\ProductSchemaVersionManager;
use App\Enums\AttributeValueScope;
use App\Enums\AttributeValueType;
use App\Models\CatalogProduct;
use App\Models\ProductCategory;
use App\Models\ProductDefinition;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogProductSemanticValueLifecycleGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_semantic_value_blocks_reclassification_until_explicit_clear(): void
    {
        [$product, $definition, $key] =
            $this->classifiedProduct('reclass');

        $target = $this->publishedDefinition(
            'reclass_target'
        );

        $valueManager = app(
            CatalogProductSemanticValueManager::class
        );

        $valueManager->set(
            $product,
            $key,
            'Valor actual'
        );

        $assignmentManager = app(
            CatalogProductDefinitionAssignmentManager::class
        );

        $this->assertDomainFailure(
            fn () => $assignmentManager
                ->reclassify(
                    $product->fresh(),
                    $target,
                    'No debe atravesar valores'
                )
        );

        $this->assertSame(
            $definition->id,
            (int) $product
                ->fresh()
                ->product_definition_id
        );

        $valueManager->clear(
            $product->fresh(),
            $key
        );

        $reclassified = $assignmentManager
            ->reclassify(
                $product->fresh(),
                $target,
                'Valores revisados y descartados'
            );

        $this->assertSame(
            $target->id,
            (int) $reclassified
                ->product_definition_id
        );
    }

    public function test_existing_semantic_value_blocks_successor_schema_publication_until_explicit_clear(): void
    {
        [$product, $definition, $key] =
            $this->classifiedProduct(
                'schema'
            );

        $valueManager = app(
            CatalogProductSemanticValueManager::class
        );

        $valueManager->set(
            $product,
            $key,
            'Valor actual'
        );

        $schemaManager = app(
            ProductSchemaVersionManager::class
        );

        $draft = $schemaManager->createDraft(
            $definition,
            'Sucesor CSF6'
        );

        $this->assertDomainFailure(
            fn () => $schemaManager->publish(
                $draft
            )
        );

        $valueManager->clear(
            $product->fresh(),
            $key
        );

        $published = $schemaManager->publish(
            $draft->fresh()
        );

        $this->assertSame(
            'published',
            $published->status->value
        );
    }

    private function classifiedProduct(
        string $suffix
    ): array {
        $category = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()
                ->create([
                    'name' => 'CSF6 guard '.$suffix,
                    'slug' => 'csf6-guard-'.$suffix,
                    'active' => true,
                ])
        );

        $product = CatalogProduct::withoutEvents(
            fn () => CatalogProduct::query()
                ->create([
                    'product_category_id' =>
                        $category->id,
                    'sku' =>
                        'CSF6-GUARD-'.strtoupper($suffix),
                    'name' =>
                        'Producto CSF6 guard '.$suffix,
                    'base_unit_code' => 'unit',
                    'quantity_scale' => 0,
                    'active' => true,
                ])->refresh()
        );

        $definition = app(
            ProductDefinitionManager::class
        )->create(
            'straleon.csf6.guard.'.$suffix,
            'CSF6 guard '.$suffix
        );

        $schema = app(
            ProductSchemaVersionManager::class
        )->createDraft(
            $definition,
            'CSF6 guard '.$suffix
        );

        $key =
            'straleon.attribute.csf6_guard_'
            .$suffix;

        $attribute = app(
            AttributeDefinitionManager::class
        )->create(
            $key,
            'CSF6 guard '.$suffix
        );

        app(
            AttributeBindingManager::class
        )->bind(
            $schema,
            $attribute,
            AttributeValueType::Text,
            AttributeValueScope::Product
        );

        app(
            ProductSchemaVersionManager::class
        )->publish(
            $schema
        );

        app(
            CatalogProductDefinitionAssignmentManager::class
        )->classify(
            $product,
            $definition
        );

        return [
            $product->fresh(),
            $definition->fresh(),
            $key,
        ];
    }

    private function publishedDefinition(
        string $suffix
    ): ProductDefinition {
        $definition = app(
            ProductDefinitionManager::class
        )->create(
            'straleon.csf6.'.$suffix,
            'CSF6 '.$suffix
        );

        $schema = app(
            ProductSchemaVersionManager::class
        )->createDraft(
            $definition,
            'CSF6 '.$suffix
        );

        app(
            ProductSchemaVersionManager::class
        )->publish(
            $schema
        );

        return $definition->fresh();
    }

    private function assertDomainFailure(
        callable $callback
    ): void {
        try {
            $callback();
            $this->fail(
                'Expected DomainException.'
            );
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }
    }
}
