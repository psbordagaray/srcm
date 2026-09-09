<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\AttributeBindingManager;
use App\Domain\Catalog\AttributeDefinitionManager;
use App\Domain\Catalog\EffectiveSemanticAttribute;
use App\Domain\Catalog\EffectiveSemanticAttributeProvenance;
use App\Domain\Catalog\EffectiveSemanticProfile;
use App\Domain\Catalog\EffectiveSemanticProfileProvenance;
use App\Domain\Catalog\EffectiveSemanticProfileResolver;
use App\Domain\Catalog\MeasurementDimensionManager;
use App\Domain\Catalog\MeasurementUnitManager;
use App\Domain\Catalog\ProductDefinitionManager;
use App\Domain\Catalog\ProductSchemaVersionManager;
use App\Enums\AttributeValueScope;
use App\Enums\AttributeValueType;
use App\Enums\ProductSchemaStatus;
use App\Enums\SemanticProfileResolutionMode;
use App\Models\AttributeBinding;
use App\Models\ProductDefinition;
use App\Models\ProductSchemaVersion;
use DomainException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class EffectiveSemanticProfileResolverTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    public function test_current_published_resolves_deterministically_with_provenance(): void
    {
        $definition = $this->definition('current');
        $schemas = app(ProductSchemaVersionManager::class);
        $bindings = app(AttributeBindingManager::class);

        $zeta = $this->attribute('zeta');
        $alpha = $this->attribute('alpha');

        $draft = $schemas->createDraft($definition);

        $zetaBinding = $bindings->bind(
            $draft,
            $zeta,
            AttributeValueType::Text,
            AttributeValueScope::Product
        );

        $alphaBinding = $bindings->bind(
            $draft,
            $alpha,
            AttributeValueType::Boolean,
            AttributeValueScope::Product
        );

        $published = $schemas->publish($draft);

        $profile = $this->resolver()->currentPublished($definition);

        $this->assertSame(
            SemanticProfileResolutionMode::CurrentPublished,
            $profile->resolutionMode
        );
        $this->assertSame($definition->id, $profile->productDefinitionId);
        $this->assertSame(
            $definition->key,
            $profile->productDefinitionKey
        );
        $this->assertSame(
            $published->id,
            $profile->productSchemaVersionId
        );
        $this->assertSame(ProductSchemaStatus::Published, $profile->schemaStatus);
        $this->assertNotSame('', $profile->publishedAt);
        $this->assertSame(
            [$alpha->key, $zeta->key],
            array_map(
                static fn (EffectiveSemanticAttribute $attribute): string =>
                    $attribute->attributeDefinitionKey,
                $profile->attributes
            )
        );
        $this->assertSame(
            [$alphaBinding->id, $zetaBinding->id],
            array_map(
                static fn (EffectiveSemanticAttribute $attribute): int =>
                    $attribute->attributeBindingId,
                $profile->attributes
            )
        );
        $this->assertSame(
            EffectiveSemanticProfileProvenance::RULE_CURRENT_PUBLISHED,
            $profile->provenance->selectionRule
        );
        $this->assertSame(
            EffectiveSemanticAttributeProvenance::RULE_EXACT_SCHEMA_BINDING,
            $profile->attributes[0]->provenance->resolutionRule
        );
    }

    public function test_current_published_rejects_zero_or_multiple_published_schemas(): void
    {
        $withoutPublished = $this->definition('zero');

        $this->assertDomainRejected(
            fn () => $this->resolver()
                ->currentPublished($withoutPublished)
        );

        $definition = $this->definition('multiple');
        $schemas = app(ProductSchemaVersionManager::class);

        $v1 = $schemas->publish(
            $schemas->createDraft($definition)
        );

        DB::table('catalog_product_schema_versions')->insert([
            'product_definition_id' => $definition->id,
            'version' => 2,
            'status' => ProductSchemaStatus::Published->value,
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(
            ProductSchemaStatus::Published,
            $v1->fresh()->status
        );

        $this->assertDomainRejected(
            fn () => $this->resolver()->currentPublished($definition)
        );
    }

    public function test_current_published_allows_deprecated_definition_but_rejects_retired_definition(): void
    {
        $manager = app(ProductDefinitionManager::class);
        $definition = $this->definition('definition_lifecycle');

        $this->publishEmpty($definition);

        $deprecated = $manager->deprecate($definition);

        $profile = $this->resolver()->currentPublished($deprecated);

        $this->assertSame(
            $definition->id,
            $profile->productDefinitionId
        );

        $retired = $manager->retire($deprecated);

        $this->assertDomainRejected(
            fn () => $this->resolver()->currentPublished($retired)
        );
    }

    public function test_exact_historical_accepts_published_deprecated_and_retired_published_history(): void
    {
        $definition = $this->definition('history');
        $schemas = app(ProductSchemaVersionManager::class);

        $v1 = $schemas->publish(
            $schemas->createDraft($definition)
        );

        $publishedProfile = $this->resolver()
            ->exactHistorical($v1);

        $this->assertSame(
            ProductSchemaStatus::Published,
            $publishedProfile->schemaStatus
        );

        $v2 = $schemas->publish(
            $schemas->createDraft($definition)
        );

        $deprecatedV1 = $v1->fresh();

        $this->assertSame(
            ProductSchemaStatus::Deprecated,
            $deprecatedV1->status
        );

        $deprecatedProfile = $this->resolver()
            ->exactHistorical($deprecatedV1);

        $this->assertSame(
            ProductSchemaStatus::Deprecated,
            $deprecatedProfile->schemaStatus
        );

        $retiredV1 = $schemas->retireDeprecated($deprecatedV1);

        $retiredProfile = $this->resolver()
            ->exactHistorical($retiredV1);

        $this->assertSame(
            ProductSchemaStatus::Retired,
            $retiredProfile->schemaStatus
        );
        $this->assertSame($v1->id, $retiredProfile->productSchemaVersionId);
        $this->assertSame(
            SemanticProfileResolutionMode::ExactHistorical,
            $retiredProfile->resolutionMode
        );
        $this->assertSame(
            $v2->id,
            $this->resolver()
                ->currentPublished($definition)
                ->productSchemaVersionId
        );
    }

    public function test_exact_historical_rejects_draft_and_abandoned_retired_draft(): void
    {
        $definition = $this->definition('draft_rejection');
        $schemas = app(ProductSchemaVersionManager::class);

        $draft = $schemas->createDraft($definition);

        $this->assertDomainRejected(
            fn () => $this->resolver()->exactHistorical($draft)
        );

        $retiredDraft = $schemas->abandonDraft($draft);

        $this->assertNull($retiredDraft->published_at);

        $this->assertDomainRejected(
            fn () => $this->resolver()
                ->exactHistorical($retiredDraft)
        );
    }

    public function test_selected_schema_is_self_contained_and_never_traverses_previous_versions(): void
    {
        $definition = $this->definition('self_contained');
        $schemas = app(ProductSchemaVersionManager::class);
        $bindings = app(AttributeBindingManager::class);

        $firstAttribute = $this->attribute('first');
        $secondAttribute = $this->attribute('second');

        $v1Draft = $schemas->createDraft($definition);

        $bindings->bind(
            $v1Draft,
            $firstAttribute,
            AttributeValueType::Text,
            AttributeValueScope::Product
        );

        $v1 = $schemas->publish($v1Draft);
        $v2Draft = $schemas->createDraft($definition);

        $clone = $v2Draft->attributeBindings()
            ->where('attribute_definition_id', $firstAttribute->id)
            ->firstOrFail();

        $bindings->remove($clone);

        $bindings->bind(
            $v2Draft,
            $secondAttribute,
            AttributeValueType::Integer,
            AttributeValueScope::Product
        );

        $v2 = $schemas->publish($v2Draft);

        $this->assertSame(
            [$secondAttribute->key],
            $this->attributeKeys(
                $this->resolver()->exactHistorical($v2)
            )
        );

        $this->assertSame(
            [$firstAttribute->key],
            $this->attributeKeys(
                $this->resolver()->exactHistorical($v1)
            )
        );

        $this->assertSame(
            [$secondAttribute->key],
            $this->attributeKeys(
                $this->resolver()->currentPublished($definition)
            )
        );
    }

    public function test_measurement_topology_is_resolved_through_unit_and_survives_registry_retirement(): void
    {
        $definition = $this->definition('measurement');
        $dimension = app(MeasurementDimensionManager::class)->create(
            $this->key('dimension', 'mass'),
            'Mass'
        );

        $unitManager = app(MeasurementUnitManager::class);
        $unit = $unitManager->create(
            $dimension,
            $this->key('unit', 'kilogram'),
            'Kilogram',
            'kg'
        );

        $attribute = $this->attribute('weight');
        $schemas = app(ProductSchemaVersionManager::class);
        $draft = $schemas->createDraft($definition);

        app(AttributeBindingManager::class)->bind(
            $draft,
            $attribute,
            AttributeValueType::Measurement,
            AttributeValueScope::Product,
            $unit
        );

        $published = $schemas->publish($draft);

        $resolved = $this->resolver()
            ->exactHistorical($published)
            ->attributes[0];

        $this->assertSame($unit->id, $resolved->measurementUnitId);
        $this->assertSame($unit->key, $resolved->measurementUnitKey);
        $this->assertSame(
            $dimension->id,
            $resolved->measurementDimensionId
        );
        $this->assertSame(
            $dimension->key,
            $resolved->measurementDimensionKey
        );

        $attributeManager = app(AttributeDefinitionManager::class);
        $attributeManager->retire(
            $attributeManager->deprecate($attribute)
        );

        $unitManager->retire(
            $unitManager->deprecate($unit)
        );

        $afterRetirement = $this->resolver()
            ->exactHistorical($published)
            ->attributes[0];

        $this->assertSame(
            $resolved->toArray(),
            $afterRetirement->toArray()
        );
    }

    public function test_mutable_registry_labels_are_not_historical_profile_authority(): void
    {
        $definitionManager = app(ProductDefinitionManager::class);
        $attributeManager = app(AttributeDefinitionManager::class);
        $unitManager = app(MeasurementUnitManager::class);

        $definition = $this->definition('labels');
        $dimension = app(MeasurementDimensionManager::class)->create(
            $this->key('dimension', 'length'),
            'Length'
        );
        $unit = $unitManager->create(
            $dimension,
            $this->key('unit', 'millimeter'),
            'Millimeter',
            'mm'
        );
        $attribute = $this->attribute('width');
        $schemas = app(ProductSchemaVersionManager::class);
        $draft = $schemas->createDraft($definition);

        app(AttributeBindingManager::class)->bind(
            $draft,
            $attribute,
            AttributeValueType::Measurement,
            AttributeValueScope::Product,
            $unit
        );

        $published = $schemas->publish($draft);

        $before = $this->resolver()
            ->exactHistorical($published)
            ->toArray();

        $definitionManager->updateMetadata(
            $definition,
            'Changed definition label',
            'Changed definition description'
        );
        $attributeManager->updateMetadata(
            $attribute,
            'Changed attribute label',
            'Changed attribute description'
        );
        $unitManager->updateMetadata(
            $unit,
            'Changed unit label',
            'changed-symbol',
            'Changed unit description'
        );

        $after = $this->resolver()
            ->exactHistorical($published)
            ->toArray();

        $this->assertSame($before, $after);
        $this->assertArrayNotHasKey('name', $after);
        $this->assertArrayNotHasKey(
            'name',
            $after['attributes'][0]
        );
        $this->assertArrayNotHasKey(
            'symbol',
            $after['attributes'][0]
        );
    }

    public function test_empty_published_schema_resolves_as_valid_empty_profile(): void
    {
        $definition = $this->definition('empty');
        $published = $this->publishEmpty($definition);

        $profile = $this->resolver()->exactHistorical($published);

        $this->assertSame([], $profile->attributes);
        $this->assertSame([], $profile->toArray()['attributes']);
    }

    public function test_scope_is_semantic_metadata_and_does_not_activate_value_runtime(): void
    {
        $definition = $this->definition('scope');
        $attribute = $this->attribute('serial_color');
        $schemas = app(ProductSchemaVersionManager::class);
        $draft = $schemas->createDraft($definition);

        app(AttributeBindingManager::class)->bind(
            $draft,
            $attribute,
            AttributeValueType::Text,
            AttributeValueScope::Variant
        );

        $published = $schemas->publish($draft);
        $resolved = $this->resolver()
            ->exactHistorical($published)
            ->attributes[0];

        $this->assertSame(
            AttributeValueScope::Variant,
            $resolved->valueScope
        );

        $array = $resolved->toArray();

        $this->assertArrayNotHasKey('value', $array);
        $this->assertArrayNotHasKey('runtime', $array);
        $this->assertArrayNotHasKey('variant_id', $array);
        $this->assertArrayNotHasKey('inventory_unit_id', $array);
    }

    public function test_invalid_type_and_scope_fail_closed(): void
    {
        $typeBinding = $this->publishedTextBinding('invalid_type');

        DB::table('catalog_attribute_bindings')
            ->where('id', $typeBinding->id)
            ->update(['value_type' => 'unsupported']);

        $this->assertDomainRejected(
            fn () => $this->resolver()->exactHistorical(
                $typeBinding->schemaVersion()->firstOrFail()
            )
        );

        $scopeBinding = $this->publishedTextBinding('invalid_scope');

        DB::table('catalog_attribute_bindings')
            ->where('id', $scopeBinding->id)
            ->update(['value_scope' => 'unsupported']);

        $this->assertDomainRejected(
            fn () => $this->resolver()->exactHistorical(
                $scopeBinding->schemaVersion()->firstOrFail()
            )
        );
    }

    public function test_measurement_configuration_corruption_fails_closed(): void
    {
        $dimension = app(MeasurementDimensionManager::class)->create(
            $this->key('dimension', 'corruption_length'),
            'Length'
        );
        $unit = app(MeasurementUnitManager::class)->create(
            $dimension,
            $this->key('unit', 'corruption_mm'),
            'Millimeter',
            'mm'
        );

        $textBinding = $this->publishedTextBinding(
            'text_with_unit'
        );

        DB::table('catalog_attribute_bindings')
            ->where('id', $textBinding->id)
            ->update(['measurement_unit_id' => $unit->id]);

        $this->assertDomainRejected(
            fn () => $this->resolver()->exactHistorical(
                $textBinding->schemaVersion()->firstOrFail()
            )
        );

        $definition = $this->definition('measurement_without_unit');
        $attribute = $this->attribute('measurement_without_unit');
        $schemas = app(ProductSchemaVersionManager::class);
        $draft = $schemas->createDraft($definition);

        $binding = app(AttributeBindingManager::class)->bind(
            $draft,
            $attribute,
            AttributeValueType::Measurement,
            AttributeValueScope::Product,
            $unit
        );

        $published = $schemas->publish($draft);

        DB::table('catalog_attribute_bindings')
            ->where('id', $binding->id)
            ->update(['measurement_unit_id' => null]);

        $this->assertDomainRejected(
            fn () => $this->resolver()->exactHistorical($published)
        );
    }

    public function test_invalid_structural_semantic_key_fails_closed(): void
    {
        $binding = $this->publishedTextBinding('bad_key');
        $attributeId = $binding->attribute_definition_id;

        DB::table('catalog_attribute_definitions')
            ->where('id', $attributeId)
            ->update(['key' => 'INVALID KEY']);

        $this->assertDomainRejected(
            fn () => $this->resolver()->exactHistorical(
                $binding->schemaVersion()->firstOrFail()
            )
        );
    }

    public function test_profile_composition_rejects_duplicate_effective_attribute_identity(): void
    {
        $mode = SemanticProfileResolutionMode::ExactHistorical;
        $schemaId = 10;
        $definitionId = 20;
        $definitionKey = 'straleon.catalog.synthetic';

        $provenance = new EffectiveSemanticProfileProvenance(
            resolutionMode: $mode,
            requestedAuthorityType:
                EffectiveSemanticProfileProvenance::AUTHORITY_PRODUCT_SCHEMA_VERSION,
            requestedAuthorityId: $schemaId,
            productDefinitionId: $definitionId,
            productDefinitionKey: $definitionKey,
            productSchemaVersionId: $schemaId,
            selectionRule:
                EffectiveSemanticProfileProvenance::RULE_EXACT_HISTORICAL,
        );

        $attributeOne = $this->syntheticAttribute(
            schemaId: $schemaId,
            bindingId: 1,
            definitionId: 30,
            definitionKey: 'straleon.attribute.synthetic'
        );

        $attributeTwo = $this->syntheticAttribute(
            schemaId: $schemaId,
            bindingId: 2,
            definitionId: 30,
            definitionKey: 'straleon.attribute.synthetic'
        );

        try {
            new EffectiveSemanticProfile(
                resolutionMode: $mode,
                productDefinitionId: $definitionId,
                productDefinitionKey: $definitionKey,
                productSchemaVersionId: $schemaId,
                schemaVersion: 1,
                schemaStatus: ProductSchemaStatus::Published,
                publishedAt: '2026-09-08 00:00:00',
                attributes: [$attributeOne, $attributeTwo],
                provenance: $provenance,
            );

            $this->fail(
                'Se esperaba rechazo de identidad efectiva duplicada.'
            );
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_resolution_executes_no_database_write_statements(): void
    {
        $definition = $this->definition('read_only');
        $this->publishEmpty($definition);

        $writeSql = [];

        DB::listen(function (QueryExecuted $query) use (
            &$writeSql
        ): void {
            $sql = ltrim(strtolower($query->sql));

            if (
                preg_match(
                    '/^(insert|update|delete|replace|alter|create|drop|truncate)\b/',
                    $sql
                ) === 1
            ) {
                $writeSql[] = $query->sql;
            }
        });

        $this->resolver()->currentPublished($definition);

        $this->assertSame([], $writeSql);
    }

    private function resolver(): EffectiveSemanticProfileResolver
    {
        return app(EffectiveSemanticProfileResolver::class);
    }

    private function definition(string $suffix): ProductDefinition
    {
        return app(ProductDefinitionManager::class)->create(
            $this->key('catalog', $suffix),
            'Definition '.$suffix
        );
    }

    private function attribute(string $suffix)
    {
        return app(AttributeDefinitionManager::class)->create(
            $this->key('attribute', $suffix),
            'Attribute '.$suffix
        );
    }

    private function publishEmpty(
        ProductDefinition $definition
    ): ProductSchemaVersion {
        $schemas = app(ProductSchemaVersionManager::class);

        return $schemas->publish(
            $schemas->createDraft($definition)
        );
    }

    private function publishedTextBinding(string $suffix): AttributeBinding
    {
        $definition = $this->definition($suffix);
        $attribute = $this->attribute($suffix);
        $schemas = app(ProductSchemaVersionManager::class);
        $draft = $schemas->createDraft($definition);

        $binding = app(AttributeBindingManager::class)->bind(
            $draft,
            $attribute,
            AttributeValueType::Text,
            AttributeValueScope::Product
        );

        $schemas->publish($draft);

        return $binding->fresh();
    }

    private function syntheticAttribute(
        int $schemaId,
        int $bindingId,
        int $definitionId,
        string $definitionKey
    ): EffectiveSemanticAttribute {
        $provenance = new EffectiveSemanticAttributeProvenance(
            productSchemaVersionId: $schemaId,
            attributeBindingId: $bindingId,
            attributeDefinitionId: $definitionId,
            attributeDefinitionKey: $definitionKey,
            measurementUnitId: null,
            measurementUnitKey: null,
            measurementDimensionId: null,
            measurementDimensionKey: null,
        );

        return new EffectiveSemanticAttribute(
            attributeBindingId: $bindingId,
            attributeDefinitionId: $definitionId,
            attributeDefinitionKey: $definitionKey,
            valueType: AttributeValueType::Text,
            valueScope: AttributeValueScope::Product,
            measurementUnitId: null,
            measurementUnitKey: null,
            measurementDimensionId: null,
            measurementDimensionKey: null,
            provenance: $provenance,
        );
    }

    /**
     * @return list<string>
     */
    private function attributeKeys(
        EffectiveSemanticProfile $profile
    ): array {
        return array_map(
            static fn (EffectiveSemanticAttribute $attribute): string =>
                $attribute->attributeDefinitionKey,
            $profile->attributes
        );
    }

    private function key(string $family, string $suffix): string
    {
        $this->sequence++;

        return 'straleon.'.$family.'.'.$suffix.'_'.$this->sequence;
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
