<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\AttributeBindingManager;
use App\Domain\Catalog\AttributeDefinitionManager;
use App\Domain\Catalog\MeasurementDimensionManager;
use App\Domain\Catalog\MeasurementUnitManager;
use App\Domain\Catalog\ProductDefinitionManager;
use App\Domain\Catalog\ProductSchemaVersionManager;
use App\Enums\AttributeValueScope;
use App\Enums\AttributeValueType;
use App\Enums\ProductSchemaStatus;
use App\Models\AttributeBinding;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductSchemaBindingVersioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_draft_starts_empty_and_next_draft_clones_published_bindings(): void
    {
        $definition = $this->definition();
        $schemas = app(ProductSchemaVersionManager::class);

        $v1 = $schemas->createDraft($definition);

        $this->assertCount(0, $v1->attributeBindings);

        $attribute = $this->attribute();

        $sourceBinding = app(AttributeBindingManager::class)->bind(
            $v1,
            $attribute,
            AttributeValueType::Text,
            AttributeValueScope::Product
        );

        $v1 = $schemas->publish($v1);

        $v2 = $schemas->createDraft($definition);
        $clone = $v2->attributeBindings()->firstOrFail();

        $this->assertNotSame($sourceBinding->id, $clone->id);
        $this->assertSame(
            $sourceBinding->attribute_definition_id,
            $clone->attribute_definition_id
        );
        $this->assertSame(
            $sourceBinding->value_type,
            $clone->value_type
        );
        $this->assertSame(
            $sourceBinding->value_scope,
            $clone->value_scope
        );
        $this->assertSame(
            ProductSchemaStatus::Published,
            $v1->fresh()->status
        );
    }

    public function test_clone_preserves_deprecated_attribute_but_republication_fails_without_deprecating_prior_schema(): void
    {
        $definition = $this->definition(
            'straleon.catalog.binding_deprecated_attribute'
        );

        $schemas = app(ProductSchemaVersionManager::class);
        $attributes = app(AttributeDefinitionManager::class);

        $attribute = $attributes->create(
            'straleon.attribute.deprecated_clone',
            'Deprecated clone'
        );

        $v1 = $schemas->createDraft($definition);

        app(AttributeBindingManager::class)->bind(
            $v1,
            $attribute,
            AttributeValueType::Text,
            AttributeValueScope::Product
        );

        $v1 = $schemas->publish($v1);
        $attributes->deprecate($attribute);

        $v2 = $schemas->createDraft($definition);

        $this->assertDatabaseHas(
            'catalog_attribute_bindings',
            [
                'product_schema_version_id' => $v2->id,
                'attribute_definition_id' => $attribute->id,
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

    public function test_clone_preserves_deprecated_measurement_unit_but_republication_fails(): void
    {
        $definition = $this->definition(
            'straleon.catalog.binding_deprecated_unit'
        );

        $schemas = app(ProductSchemaVersionManager::class);
        $dimension = app(MeasurementDimensionManager::class)->create(
            'straleon.measurement.dimension.versioning_mass',
            'Mass'
        );

        $unitManager = app(MeasurementUnitManager::class);
        $unit = $unitManager->create(
            $dimension,
            'straleon.measurement.unit.versioning_kilogram',
            'Kilogram',
            'kg'
        );

        $attribute = $this->attribute(
            'straleon.attribute.versioning_weight'
        );

        $v1 = $schemas->createDraft($definition);

        app(AttributeBindingManager::class)->bind(
            $v1,
            $attribute,
            AttributeValueType::Measurement,
            AttributeValueScope::Product,
            $unit
        );

        $v1 = $schemas->publish($v1);
        $unitManager->deprecate($unit);

        $v2 = $schemas->createDraft($definition);
        $clone = $v2->attributeBindings()->firstOrFail();

        $this->assertSame(
            $unit->id,
            $clone->measurement_unit_id
        );

        $this->assertDomainRejected(
            fn () => $schemas->publish($v2)
        );

        $this->assertSame(
            ProductSchemaStatus::Published,
            $v1->fresh()->status
        );
    }

    public function test_successful_new_publication_atomically_deprecates_prior_schema(): void
    {
        $definition = $this->definition(
            'straleon.catalog.binding_publish'
        );

        $schemas = app(ProductSchemaVersionManager::class);
        $attribute = $this->attribute(
            'straleon.attribute.binding_publish'
        );

        $v1 = $schemas->createDraft($definition);

        app(AttributeBindingManager::class)->bind(
            $v1,
            $attribute,
            AttributeValueType::ExactDecimal,
            AttributeValueScope::Product
        );

        $v1 = $schemas->publish($v1);
        $v2 = $schemas->createDraft($definition);

        $v2 = $schemas->publish($v2);

        $this->assertSame(
            ProductSchemaStatus::Deprecated,
            $v1->fresh()->status
        );
        $this->assertSame(
            ProductSchemaStatus::Published,
            $v2->status
        );
        $this->assertSame(
            1,
            AttributeBinding::query()
                ->where(
                    'product_schema_version_id',
                    $v1->id
                )
                ->count()
        );
        $this->assertSame(
            1,
            AttributeBinding::query()
                ->where(
                    'product_schema_version_id',
                    $v2->id
                )
                ->count()
        );
    }

    private function definition(
        string $key = 'straleon.catalog.binding_versioning'
    ) {
        return app(ProductDefinitionManager::class)->create(
            $key,
            'Binding versioning'
        );
    }

    private function attribute(
        string $key = 'straleon.attribute.binding_versioning'
    ) {
        return app(AttributeDefinitionManager::class)->create(
            $key,
            'Binding versioning'
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
