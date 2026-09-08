<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\ProductDefinitionManager;
use App\Domain\Catalog\ProductSchemaVersionManager;
use App\Enums\ProductSchemaStatus;
use App\Models\ProductDefinition;
use App\Models\ProductSchemaVersion;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductSchemaVersionFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_draft_receives_version_one(): void
    {
        $draft = $this->schemaManager()->createDraft(
            $this->definition(),
            'Initial semantic contract.'
        );

        $this->assertSame(1, $draft->version);
        $this->assertSame(ProductSchemaStatus::Draft, $draft->status);
        $this->assertNull($draft->published_at);
    }

    public function test_second_open_draft_is_rejected(): void
    {
        $definition = $this->definition();
        $manager = $this->schemaManager();

        $manager->createDraft($definition);

        $this->assertDomainRejected(
            fn () => $manager->createDraft($definition)
        );

        $this->assertDatabaseCount(
            'catalog_product_schema_versions',
            1
        );
    }

    public function test_abandoned_draft_does_not_recycle_version_number(): void
    {
        $definition = $this->definition();
        $manager = $this->schemaManager();

        $first = $manager->createDraft($definition);
        $first = $manager->abandonDraft($first);
        $second = $manager->createDraft($definition);

        $this->assertSame(ProductSchemaStatus::Retired, $first->status);
        $this->assertNotNull($first->retired_at);
        $this->assertSame(2, $second->version);
    }

    public function test_draft_can_be_published(): void
    {
        $draft = $this->schemaManager()->createDraft(
            $this->definition()
        );

        $published = $this->schemaManager()->publish($draft);

        $this->assertSame(
            ProductSchemaStatus::Published,
            $published->status
        );
        $this->assertNotNull($published->published_at);
    }

    public function test_publishing_new_version_deprecates_current_version_atomically(): void
    {
        $definition = $this->definition();
        $manager = $this->schemaManager();

        $v1 = $manager->publish(
            $manager->createDraft($definition)
        );

        $v2 = $manager->publish(
            $manager->createDraft($definition)
        );

        $v1 = $v1->fresh();

        $this->assertSame(
            ProductSchemaStatus::Deprecated,
            $v1->status
        );
        $this->assertNotNull($v1->deprecated_at);
        $this->assertSame(
            ProductSchemaStatus::Published,
            $v2->status
        );

        $this->assertSame(
            1,
            ProductSchemaVersion::query()
                ->where(
                    'product_definition_id',
                    $definition->id
                )
                ->where('status', 'published')
                ->count()
        );
    }

    public function test_lifecycle_timestamps_are_recorded(): void
    {
        $definition = $this->definition();
        $manager = $this->schemaManager();

        $v1 = $manager->publish(
            $manager->createDraft($definition)
        );

        $manager->publish(
            $manager->createDraft($definition)
        );

        $v1 = $v1->fresh();

        $this->assertNotNull($v1->published_at);
        $this->assertNotNull($v1->deprecated_at);

        $v1 = $manager->retireDeprecated($v1);

        $this->assertNotNull($v1->retired_at);
    }

    public function test_schema_version_number_is_immutable(): void
    {
        $draft = $this->schemaManager()->createDraft(
            $this->definition()
        );

        $this->assertDomainRejected(function () use ($draft): void {
            $draft->version = 99;
            $draft->save();
        });
    }

    public function test_schema_parent_definition_is_immutable(): void
    {
        $firstDefinition = $this->definition(
            'straleon.catalog.schema_parent_one'
        );
        $secondDefinition = $this->definition(
            'straleon.catalog.schema_parent_two'
        );

        $draft = $this->schemaManager()->createDraft(
            $firstDefinition
        );

        $this->assertDomainRejected(
            function () use (
                $draft,
                $secondDefinition
            ): void {
                $draft->product_definition_id =
                    $secondDefinition->id;
                $draft->save();
            }
        );
    }

    public function test_published_schema_cannot_edit_change_summary(): void
    {
        $manager = $this->schemaManager();
        $published = $manager->publish(
            $manager->createDraft(
                $this->definition(),
                'Original'
            )
        );

        $this->assertDomainRejected(
            fn () => $manager->updateDraftMetadata(
                $published,
                'Mutated'
            )
        );

        $this->assertDomainRejected(
            function () use ($published): void {
                $published->change_summary = 'Direct mutation';
                $published->save();
            }
        );
    }

    public function test_deprecated_definition_cannot_open_new_draft(): void
    {
        $definitionManager = app(ProductDefinitionManager::class);
        $definition = $definitionManager->deprecate(
            $this->definition()
        );

        $this->assertDomainRejected(
            fn () => $this->schemaManager()
                ->createDraft($definition)
        );
    }

    public function test_draft_cannot_publish_after_definition_is_deprecated(): void
    {
        $definitionManager = app(ProductDefinitionManager::class);
        $definition = $this->definition();
        $manager = $this->schemaManager();

        $draft = $manager->createDraft($definition);
        $definitionManager->deprecate($definition);

        $this->assertDomainRejected(
            fn () => $manager->publish($draft)
        );
    }

    public function test_invalid_schema_transitions_are_rejected(): void
    {
        $manager = $this->schemaManager();
        $draft = $manager->createDraft($this->definition());

        $this->assertDomainRejected(function () use ($draft): void {
            $draft->status = ProductSchemaStatus::Deprecated;
            $draft->deprecated_at = now();
            $draft->save();
        });

        $published = $manager->publish($draft->fresh());

        $this->assertDomainRejected(
            fn () => $manager->abandonDraft($published)
        );
    }

    public function test_schema_version_cannot_be_physically_deleted(): void
    {
        $draft = $this->schemaManager()->createDraft(
            $this->definition()
        );

        $this->assertDomainRejected(
            fn () => $draft->delete()
        );

        $this->assertDatabaseHas(
            'catalog_product_schema_versions',
            ['id' => $draft->id]
        );
    }

    public function test_schema_version_table_defers_bindings_capabilities_and_compiled_schema(): void
    {
        $columns = Schema::getColumnListing(
            'catalog_product_schema_versions'
        );

        foreach ([
            'attributes',
            'compiled_schema',
            'capability_profile',
            'organization_id',
            'parent_schema_id',
        ] as $forbidden) {
            $this->assertNotContains($forbidden, $columns);
        }
    }

    private function definition(
        string $key = 'straleon.catalog.schema_test'
    ): ProductDefinition {
        return app(ProductDefinitionManager::class)->create(
            $key,
            'Schema test'
        );
    }

    private function schemaManager(): ProductSchemaVersionManager
    {
        return app(ProductSchemaVersionManager::class);
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
