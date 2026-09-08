<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\AttributeDefinitionManager;
use App\Enums\AttributeDefinitionStatus;
use App\Models\AttributeDefinition;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AttributeDefinitionFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_attribute_definition_can_be_created(): void
    {
        $attribute = app(AttributeDefinitionManager::class)->create(
            'straleon.catalog.attribute.power',
            'Potencia'
        );

        $this->assertSame(
            'straleon.catalog.attribute.power',
            $attribute->key
        );
        $this->assertSame(
            AttributeDefinitionStatus::Active,
            $attribute->status
        );
    }

    public function test_attribute_key_is_globally_unique(): void
    {
        $manager = app(AttributeDefinitionManager::class);

        $manager->create(
            'straleon.catalog.attribute.material',
            'Material'
        );

        $this->assertDomainRejected(
            fn () => $manager->create(
                'straleon.catalog.attribute.material',
                'Material duplicado'
            )
        );

        $this->assertDatabaseCount(
            'catalog_attribute_definitions',
            1
        );
    }

    public function test_invalid_attribute_key_is_rejected_without_normalization(): void
    {
        $manager = app(AttributeDefinitionManager::class);

        foreach ([
            'Power',
            'power',
            'straleon catalog power',
            'straleon.catalog.attribute.power-rating',
        ] as $key) {
            $this->assertDomainRejected(
                fn () => $manager->create($key, 'Inválido')
            );
        }
    }

    public function test_attribute_key_is_immutable(): void
    {
        $attribute = $this->attribute();

        $this->assertDomainRejected(function () use ($attribute): void {
            $attribute->key =
                'straleon.catalog.attribute.changed';
            $attribute->save();
        });

        $this->assertSame(
            'straleon.catalog.attribute.test_attribute',
            $attribute->fresh()->key
        );
    }

    public function test_attribute_lifecycle_is_forward_only(): void
    {
        $manager = app(AttributeDefinitionManager::class);
        $attribute = $this->attribute();

        $attribute = $manager->deprecate($attribute);

        $this->assertSame(
            AttributeDefinitionStatus::Deprecated,
            $attribute->status
        );

        $attribute = $manager->retire($attribute);

        $this->assertSame(
            AttributeDefinitionStatus::Retired,
            $attribute->status
        );
    }

    public function test_attribute_reverse_transition_is_rejected(): void
    {
        $manager = app(AttributeDefinitionManager::class);
        $attribute = $manager->deprecate($this->attribute());

        $this->assertDomainRejected(function () use ($attribute): void {
            $attribute->status = AttributeDefinitionStatus::Active;
            $attribute->save();
        });
    }

    public function test_deprecated_attribute_allows_metadata_correction_but_retired_does_not(): void
    {
        $manager = app(AttributeDefinitionManager::class);
        $attribute = $manager->deprecate($this->attribute());

        $attribute = $manager->updateMetadata(
            $attribute,
            'Nombre corregido'
        );

        $this->assertSame('Nombre corregido', $attribute->name);

        $attribute = $manager->retire($attribute);

        $this->assertDomainRejected(
            fn () => $manager->updateMetadata(
                $attribute,
                'No permitido'
            )
        );
    }

    public function test_attribute_definition_cannot_be_physically_deleted(): void
    {
        $attribute = $this->attribute();

        $this->assertDomainRejected(
            fn () => $attribute->delete()
        );

        $this->assertDatabaseHas(
            'catalog_attribute_definitions',
            ['id' => $attribute->id]
        );
    }

    public function test_attribute_definition_defers_typing_binding_and_publication_flags(): void
    {
        $columns = Schema::getColumnListing(
            'catalog_attribute_definitions'
        );

        foreach ([
            'data_type',
            'measurement_dimension',
            'allowed_scopes',
            'required',
            'filterable',
            'searchable',
            'comparable',
            'variant_axis',
            'storefront_visible',
            'network_visible',
        ] as $forbidden) {
            $this->assertNotContains($forbidden, $columns);
        }
    }

    private function attribute(): AttributeDefinition
    {
        return app(AttributeDefinitionManager::class)->create(
            'straleon.catalog.attribute.test_attribute',
            'Atributo de prueba'
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
