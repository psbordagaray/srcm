<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\AttributeBindingManager;
use App\Domain\Catalog\AttributeDefinitionManager;
use App\Domain\Catalog\CatalogProductDefinitionAssignmentManager;
use App\Domain\Catalog\CatalogProductSemanticValueManager;
use App\Domain\Catalog\CatalogProductSemanticValueResolver;
use App\Domain\Catalog\MeasurementDimensionManager;
use App\Domain\Catalog\MeasurementUnitManager;
use App\Domain\Catalog\ProductDefinitionManager;
use App\Domain\Catalog\ProductSchemaVersionManager;
use App\Enums\AttributeValueScope;
use App\Enums\AttributeValueType;
use App\Models\AuditLog;
use App\Models\CatalogProduct;
use App\Models\CatalogProductSemanticValue;
use App\Models\ProductCategory;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CatalogProductSemanticValueFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_seven_product_value_types_store_and_resolve_canonically(): void
    {
        [$product, $keys] = $this->productWithProfile(
            'types'
        );

        $manager = app(
            CatalogProductSemanticValueManager::class
        );

        $inputs = [
            $keys['text'] => '  Acero inoxidable  ',
            $keys['boolean'] => false,
            $keys['integer'] => '-42',
            $keys['exact_decimal'] => '0012.3400',
            $keys['measurement'] => '1.250',
            $keys['date'] => '2026-09-09',
            $keys['datetime'] =>
                '2026-09-09T12:34:56.123456-03:00',
        ];

        foreach ($inputs as $key => $value) {
            $manager->set(
                $product,
                $key,
                $value
            );
        }

        $this->assertSame(
            7,
            CatalogProductSemanticValue::query()
                ->where(
                    'catalog_product_id',
                    $product->id
                )
                ->count()
        );

        $resolved = app(
            CatalogProductSemanticValueResolver::class
        )->current(
            $product->fresh()
        );

        $byKey = [];

        foreach ($resolved as $value) {
            $byKey[
                $value->attributeDefinitionKey
            ] = $value;
        }

        $this->assertCount(7, $byKey);
        $this->assertSame(
            'Acero inoxidable',
            $byKey[$keys['text']]->value
        );
        $this->assertFalse(
            $byKey[$keys['boolean']]->value
        );
        $this->assertSame(
            -42,
            $byKey[$keys['integer']]->value
        );
        $this->assertSame(
            '12.340000000000000000',
            $byKey[$keys['exact_decimal']]->value
        );
        $this->assertSame(
            '1.250000000000000000',
            $byKey[$keys['measurement']]->value
        );
        $this->assertSame(
            '2026-09-09',
            $byKey[$keys['date']]->value
        );
        $this->assertSame(
            '2026-09-09T15:34:56.123456Z',
            $byKey[$keys['datetime']]->value
        );

        $this->assertNotNull(
            $byKey[$keys['measurement']]
                ->measurementUnitId
        );
        $this->assertNotNull(
            $byKey[$keys['measurement']]
                ->measurementDimensionId
        );

        $columns = Schema::getColumnListing(
            'catalog_product_semantic_values'
        );

        $this->assertNotContains(
            'measurement_unit_id',
            $columns
        );
        $this->assertNotContains(
            'product_schema_version_id',
            $columns
        );
        $this->assertNotContains(
            'attribute_definition_id',
            $columns
        );
        $this->assertNotContains(
            'value_json',
            $columns
        );
    }

    public function test_set_is_idempotent_update_is_audited_and_clear_is_explicit(): void
    {
        [$product, $keys] = $this->productWithProfile(
            'audit'
        );

        $manager = app(
            CatalogProductSemanticValueManager::class
        );

        $first = $manager->set(
            $product,
            $keys['text'],
            '  Uno  '
        );

        $retry = $manager->set(
            $product,
            $keys['text'],
            'Uno'
        );

        $this->assertSame(
            $first->id,
            $retry->id
        );

        $this->assertSame(
            1,
            AuditLog::query()
                ->where(
                    'auditable_type',
                    CatalogProduct::class
                )
                ->where(
                    'auditable_id',
                    $product->id
                )
                ->where(
                    'event',
                    'catalog_product.semantic_value_set'
                )
                ->count()
        );

        $manager->set(
            $product,
            $keys['text'],
            'Dos'
        );

        $updated = AuditLog::query()
            ->where(
                'auditable_type',
                CatalogProduct::class
            )
            ->where(
                'auditable_id',
                $product->id
            )
            ->where(
                'event',
                'catalog_product.semantic_value_updated'
            )
            ->sole();

        $this->assertSame(
            'Uno',
            $updated->old_values['value']
        );
        $this->assertSame(
            'Dos',
            $updated->new_values['value']
        );
        $this->assertGreaterThan(
            0,
            (int) $updated
                ->new_values['attribute_binding_id']
        );
        $this->assertGreaterThan(
            0,
            (int) $updated
                ->new_values['product_schema_version_id']
        );

        $manager->clear(
            $product,
            $keys['text']
        );

        $this->assertDatabaseMissing(
            'catalog_product_semantic_values',
            [
                'catalog_product_id' => $product->id,
                'attribute_binding_id' =>
                    $first->attribute_binding_id,
            ]
        );

        $this->assertSame(
            1,
            AuditLog::query()
                ->where(
                    'auditable_type',
                    CatalogProduct::class
                )
                ->where(
                    'auditable_id',
                    $product->id
                )
                ->where(
                    'event',
                    'catalog_product.semantic_value_cleared'
                )
                ->count()
        );

        $manager->clear(
            $product,
            $keys['text']
        );

        $this->assertSame(
            1,
            AuditLog::query()
                ->where(
                    'auditable_type',
                    CatalogProduct::class
                )
                ->where(
                    'auditable_id',
                    $product->id
                )
                ->where(
                    'event',
                    'catalog_product.semantic_value_cleared'
                )
                ->count()
        );
    }

    public function test_invalid_payloads_non_product_scope_and_direct_foreign_binding_fail_closed(): void
    {
        [$product, $keys] = $this->productWithProfile(
            'invalid',
            includeVariant: true
        );

        $manager = app(
            CatalogProductSemanticValueManager::class
        );

        foreach ([
            [$keys['boolean'], 'true'],
            [$keys['integer'], '1.5'],
            [$keys['exact_decimal'], 1.25],
            [$keys['date'], '2026-02-31'],
            [$keys['datetime'], '2026-09-09 12:00:00'],
            [$keys['variant'], 'x'],
        ] as [$key, $value]) {
            $this->assertDomainFailure(
                fn () => $manager->set(
                    $product,
                    $key,
                    $value
                )
            );
        }

        [$otherProduct, $otherKeys] =
            $this->productWithProfile(
                'foreign'
            );

        $manager->set(
            $otherProduct,
            $otherKeys['text'],
            'Foreign'
        );

        $foreign = CatalogProductSemanticValue::query()
            ->where(
                'catalog_product_id',
                $otherProduct->id
            )
            ->firstOrFail();

        $this->assertDomainFailure(
            fn () => CatalogProductSemanticValue::query()
                ->create([
                    'catalog_product_id' => $product->id,
                    'attribute_binding_id' =>
                        $foreign->attribute_binding_id,
                    'value_text' => 'No permitido',
                ])
        );

        $this->assertSame(
            0,
            CatalogProductSemanticValue::query()
                ->where(
                    'catalog_product_id',
                    $product->id
                )
                ->count()
        );
    }

    private function productWithProfile(
        string $suffix,
        bool $includeVariant = false
    ): array {
        $category = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()
                ->create([
                    'name' => 'CSF6 '.$suffix,
                    'slug' => 'csf6-'.$suffix,
                    'active' => true,
                ])
        );

        $product = CatalogProduct::withoutEvents(
            fn () => CatalogProduct::query()
                ->create([
                    'product_category_id' =>
                        $category->id,
                    'sku' =>
                        'CSF6-'.strtoupper($suffix),
                    'name' =>
                        'Producto CSF6 '.$suffix,
                    'base_unit_code' => 'unit',
                    'quantity_scale' => 0,
                    'active' => true,
                ])->refresh()
        );

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

        $dimension = app(
            MeasurementDimensionManager::class
        )->create(
            'straleon.measurement.dimension.csf6_'.$suffix,
            'CSF6 dimension '.$suffix
        );

        $unit = app(
            MeasurementUnitManager::class
        )->create(
            $dimension,
            'straleon.measurement.unit.csf6_'.$suffix,
            'CSF6 unit '.$suffix,
            'u'.$suffix
        );

        $types = [
            'text' => AttributeValueType::Text,
            'boolean' => AttributeValueType::Boolean,
            'integer' => AttributeValueType::Integer,
            'exact_decimal' =>
                AttributeValueType::ExactDecimal,
            'measurement' =>
                AttributeValueType::Measurement,
            'date' => AttributeValueType::Date,
            'datetime' => AttributeValueType::DateTime,
        ];

        $keys = [];

        foreach ($types as $name => $type) {
            $key =
                'straleon.attribute.csf6_'
                .$suffix
                .'_'
                .$name;

            $attribute = app(
                AttributeDefinitionManager::class
            )->create(
                $key,
                'CSF6 '.$suffix.' '.$name
            );

            app(
                AttributeBindingManager::class
            )->bind(
                $schema,
                $attribute,
                $type,
                AttributeValueScope::Product,
                $type === AttributeValueType::Measurement
                    ? $unit
                    : null
            );

            $keys[$name] = $key;
        }

        if ($includeVariant) {
            $key =
                'straleon.attribute.csf6_'
                .$suffix
                .'_variant';

            $attribute = app(
                AttributeDefinitionManager::class
            )->create(
                $key,
                'CSF6 '.$suffix.' variant'
            );

            app(
                AttributeBindingManager::class
            )->bind(
                $schema,
                $attribute,
                AttributeValueType::Text,
                AttributeValueScope::Variant
            );

            $keys['variant'] = $key;
        }

        app(
            ProductSchemaVersionManager::class
        )->publish($schema);

        app(
            CatalogProductDefinitionAssignmentManager::class
        )->classify(
            $product,
            $definition
        );

        return [
            $product->fresh(),
            $keys,
            $definition->fresh(),
        ];
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
