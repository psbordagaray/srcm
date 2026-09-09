<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\CatalogProductDefinitionAssignmentManager;
use App\Domain\Catalog\ProductDefinitionManager;
use App\Domain\Catalog\ProductSchemaVersionManager;
use App\Enums\InventoryCondition;
use App\Enums\InventoryLocationType;
use App\Enums\InventoryReservationStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\CatalogProduct;
use App\Models\InventoryLocation;
use App\Models\InventoryReservation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductCategory;
use App\Models\ProductDefinition;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CatalogProductDefinitionAssignmentFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_initial_classification_is_explicit_and_idempotent(): void
    {
        [, , $product] = $this->context('initial');
        $definition = $this->publishedDefinition('initial');
        $categoryId = $product->product_category_id;

        $manager = app(
            CatalogProductDefinitionAssignmentManager::class
        );

        $classified = $manager->classify(
            $product,
            $definition
        );

        $this->assertSame(
            $definition->id,
            (int) $classified->product_definition_id
        );
        $this->assertSame(
            $categoryId,
            $classified->product_category_id
        );
        $this->assertSame(
            $definition->id,
            $classified->productDefinition->id
        );
        $this->assertNotContains(
            'product_definition_id',
            $classified->getFillable()
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
                    $classified->id
                )
                ->where(
                    'event',
                    'catalog_product.semantic_definition_assigned'
                )
                ->count()
        );

        $retry = $manager->classify(
            $classified,
            $definition
        );

        $this->assertSame(
            $classified->id,
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
                    $classified->id
                )
                ->where(
                    'event',
                    'catalog_product.semantic_definition_assigned'
                )
                ->count()
        );
    }

    public function test_new_assignment_requires_active_definition_with_published_schema(): void
    {
        [, , $product] = $this->context('targets');
        $manager = app(
            CatalogProductDefinitionAssignmentManager::class
        );

        $noSchema = app(
            ProductDefinitionManager::class
        )->create(
            'csf5.no_schema',
            'Sin schema'
        );

        $this->assertDomainFailure(
            fn () => $manager->classify(
                $product,
                $noSchema
            )
        );

        $deprecated = $this->publishedDefinition(
            'deprecated'
        );

        $deprecated = app(
            ProductDefinitionManager::class
        )->deprecate($deprecated);

        $this->assertDomainFailure(
            fn () => $manager->classify(
                $product->fresh(),
                $deprecated
            )
        );

        $retired = $this->publishedDefinition(
            'retired'
        );

        $definitionManager = app(
            ProductDefinitionManager::class
        );

        $retired = $definitionManager
            ->deprecate($retired);

        $retired = $definitionManager
            ->retire($retired);

        $this->assertDomainFailure(
            fn () => $manager->classify(
                $product->fresh(),
                $retired
            )
        );

        $this->assertNull(
            $product->fresh()->product_definition_id
        );
    }

    public function test_initial_classification_is_not_blocked_by_existing_effective_reservation(): void
    {
        [
            $organization,
            $actor,
            $product,
            $location,
        ] = $this->context(
            'with_reservation',
            withLocation: true
        );

        $this->effectiveReservation(
            $organization,
            $actor,
            $product,
            $location,
            'initial'
        );

        $definition = $this->publishedDefinition(
            'initial_existing_reservation'
        );

        $classified = app(
            CatalogProductDefinitionAssignmentManager::class
        )->classify(
            $product,
            $definition
        );

        $this->assertSame(
            $definition->id,
            (int) $classified->product_definition_id
        );
    }

    public function test_classify_cannot_silently_reclassify_and_category_change_does_not_change_semantics(): void
    {
        [, , $product] = $this->context('category');
        $first = $this->publishedDefinition('category_a');
        $second = $this->publishedDefinition('category_b');
        $manager = app(
            CatalogProductDefinitionAssignmentManager::class
        );

        $classified = $manager->classify(
            $product,
            $first
        );

        $this->assertDomainFailure(
            fn () => $manager->classify(
                $classified,
                $second
            )
        );

        $newCategory = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()->create([
                'name' => 'Otra categoría CSF5',
                'slug' => 'csf5-otra-categoria',
                'active' => true,
            ])
        );

        $classified->update([
            'product_category_id' => $newCategory->id,
        ]);

        $this->assertSame(
            $first->id,
            (int) $classified->fresh()->product_definition_id
        );
    }

    private function context(
        string $suffix,
        bool $withLocation = false
    ): array {
        $organization = Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();

        $actor = User::factory()->create([
            'role' => UserRole::Admin,
            'email_verified_at' => now(),
        ]);

        OrganizationMembership::withoutEvents(
            fn () => OrganizationMembership::query()
                ->updateOrCreate(
                    [
                        'organization_id' => $organization->id,
                        'user_id' => $actor->id,
                    ],
                    [
                        'role' => UserRole::Admin,
                        'active' => true,
                    ]
                )
        );

        $actor
            ->forceFill([
                'current_organization_id' => $organization->id,
            ])
            ->saveQuietly();

        $this->actingAs($actor);

        $category = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()->create([
                'name' => 'CSF5 '.$suffix,
                'slug' => 'csf5-'.str_replace('_', '-', $suffix),
                'active' => true,
            ])
        );

        $product = CatalogProduct::withoutEvents(
            fn () => CatalogProduct::query()->create([
                'product_category_id' => $category->id,
                'sku' => 'CSF5-'.strtoupper($suffix),
                'name' => 'Producto CSF5 '.$suffix,
                'base_unit_code' => 'l',
                'quantity_scale' => 3,
                'active' => true,
            ])->refresh()
        );

        $location = null;

        if ($withLocation) {
            $location = InventoryLocation::withoutEvents(
                fn () => InventoryLocation::query()->create([
                    'organization_id' => $organization->id,
                    'name' => 'CSF5 '.$suffix,
                    'type' => InventoryLocationType::Warehouse,
                    'active' => true,
                ])->refresh()
            );
        }

        return [
            $organization,
            $actor,
            $product,
            $location,
        ];
    }

    private function publishedDefinition(
        string $suffix
    ): ProductDefinition {
        $definition = app(
            ProductDefinitionManager::class
        )->create(
            'csf5.'.$suffix,
            'Definición CSF5 '.$suffix
        );

        $schema = app(
            ProductSchemaVersionManager::class
        )->createDraft(
            $definition,
            'CSF5 '.$suffix
        );

        app(
            ProductSchemaVersionManager::class
        )->publish($schema);

        return $definition->fresh();
    }

    private function effectiveReservation(
        Organization $organization,
        User $actor,
        CatalogProduct $product,
        InventoryLocation $location,
        string $suffix
    ): InventoryReservation {
        return InventoryReservation::query()->create([
            'organization_id' => $organization->id,
            'public_id' => (string) Str::uuid(),
            'catalog_product_id' => $product->id,
            'inventory_location_id' => $location->id,
            'condition' => InventoryCondition::New,
            'quantity' => '1.000000',
            'base_unit_code' => $product->base_unit_code,
            'status' => InventoryReservationStatus::Active,
            'expires_at' => now()->addHour(),
            'released_at' => null,
            'release_reason' => null,
            'created_by_user_id' => $actor->id,
            'released_by_user_id' => null,
            'idempotency_key' => 'csf5:reservation:'.$suffix,
            'fingerprint' => str_repeat('a', 64),
        ]);
    }

    private function assertDomainFailure(
        callable $callback
    ): void {
        try {
            $callback();
            $this->fail(
                'Expected domain rejection.'
            );
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }
    }
}
