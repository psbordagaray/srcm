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
use App\Models\AttributeBinding;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AttributeBindingFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_measurement_binding_derives_dimension_from_unit(): void
    {
        [$schema, $attribute] = $this->schemaAndAttribute();
        [$dimension, $unit] = $this->measurement();

        $binding = $this->manager()->bind(
            $schema,
            $attribute,
            AttributeValueType::Measurement,
            AttributeValueScope::Product,
            $unit
        );

        $this->assertSame(
            AttributeValueType::Measurement,
            $binding->value_type
        );
        $this->assertSame($unit->id, $binding->measurement_unit_id);
        $this->assertSame(
            $dimension->id,
            $binding->measurementUnit
                ->dimension
                ->id
        );

        $this->assertNotContains(
            'measurement_dimension_id',
            Schema::getColumnListing(
                'catalog_attribute_bindings'
            )
        );
    }

    public function test_measurement_and_non_measurement_unit_invariants_fail_closed(): void
    {
        [$schema, $attribute] = $this->schemaAndAttribute();
        [, $unit] = $this->measurement();

        $this->assertDomainRejected(
            fn () => $this->manager()->bind(
                $schema,
                $attribute,
                AttributeValueType::Measurement,
                AttributeValueScope::Product
            )
        );

        $this->assertDomainRejected(
            fn () => $this->manager()->bind(
                $schema,
                $attribute,
                AttributeValueType::Text,
                AttributeValueScope::Product,
                $unit
            )
        );
    }

    public function test_binding_identity_is_unique_and_immutable_inside_a_draft(): void
    {
        [$schema, $attribute] = $this->schemaAndAttribute();

        $binding = $this->manager()->bind(
            $schema,
            $attribute,
            AttributeValueType::Text,
            AttributeValueScope::Product
        );

        $this->assertDomainRejected(
            fn () => $this->manager()->bind(
                $schema,
                $attribute,
                AttributeValueType::Boolean,
                AttributeValueScope::Product
            )
        );

        $other = app(AttributeDefinitionManager::class)->create(
            'straleon.attribute.binding_other',
            'Other'
        );

        $this->assertDomainRejected(function () use (
            $binding,
            $other
        ): void {
            $binding->attribute_definition_id = $other->id;
            $binding->save();
        });
    }

    public function test_draft_binding_can_reconfigure_and_remove_but_published_binding_is_immutable(): void
    {
        [$schema, $attribute] = $this->schemaAndAttribute();

        $binding = $this->manager()->bind(
            $schema,
            $attribute,
            AttributeValueType::Text,
            AttributeValueScope::Product
        );

        $binding = $this->manager()->reconfigure(
            $binding,
            AttributeValueType::Boolean,
            AttributeValueScope::InventoryUnit
        );

        $this->assertSame(
            AttributeValueType::Boolean,
            $binding->value_type
        );
        $this->assertSame(
            AttributeValueScope::InventoryUnit,
            $binding->value_scope
        );

        app(ProductSchemaVersionManager::class)->publish($schema);

        $this->assertDomainRejected(
            fn () => $this->manager()->reconfigure(
                $binding->fresh(),
                AttributeValueType::Text,
                AttributeValueScope::Product
            )
        );

        $this->assertDomainRejected(
            fn () => $this->manager()->remove(
                $binding->fresh()
            )
        );

        $this->assertDatabaseHas(
            'catalog_attribute_bindings',
            ['id' => $binding->id]
        );
    }

    public function test_draft_binding_can_be_removed_physically(): void
    {
        [$schema, $attribute] = $this->schemaAndAttribute();

        $binding = $this->manager()->bind(
            $schema,
            $attribute,
            AttributeValueType::Text,
            AttributeValueScope::Product
        );

        $this->manager()->remove($binding);

        $this->assertDatabaseMissing(
            'catalog_attribute_bindings',
            ['id' => $binding->id]
        );
    }

    private function schemaAndAttribute(): array
    {
        $definition = app(ProductDefinitionManager::class)->create(
            'straleon.catalog.binding_test',
            'Binding test'
        );

        $schema = app(ProductSchemaVersionManager::class)
            ->createDraft($definition);

        $attribute = app(AttributeDefinitionManager::class)->create(
            'straleon.attribute.binding_test',
            'Binding test'
        );

        return [$schema, $attribute];
    }

    private function measurement(): array
    {
        $dimension = app(MeasurementDimensionManager::class)->create(
            'straleon.measurement.dimension.binding_mass',
            'Mass'
        );

        $unit = app(MeasurementUnitManager::class)->create(
            $dimension,
            'straleon.measurement.unit.binding_kilogram',
            'Kilogram',
            'kg'
        );

        return [$dimension, $unit];
    }

    private function manager(): AttributeBindingManager
    {
        return app(AttributeBindingManager::class);
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
