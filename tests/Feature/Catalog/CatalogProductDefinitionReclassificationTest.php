<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\CatalogProductDefinitionAssignmentManager;
use App\Domain\Catalog\CatalogProductSemanticProfileResolver;
use App\Domain\Catalog\ProductDefinitionManager;
use App\Domain\Catalog\ProductSchemaVersionManager;
use App\Enums\FulfillmentUnavailableFallback;
use App\Enums\InventoryCondition;
use App\Enums\InventoryLocationType;
use App\Enums\InventoryReservationStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\CatalogProduct;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductCategory;
use App\Models\ProductDefinition;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CatalogProductDefinitionReclassificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_reclassification_requires_reason_and_records_specific_audit_atomically(): void
    {
        [, , $product] = $this->context('audit');
        $from = $this->publishedDefinition('audit_from');
        $to = $this->publishedDefinition('audit_to');
        $manager = app(
            CatalogProductDefinitionAssignmentManager::class
        );

        $classified = $manager->classify(
            $product,
            $from
        );

        $this->assertDomainFailure(
            fn () => $manager->reclassify(
                $classified,
                $to,
                '   '
            )
        );

        $genericUpdatedBefore = AuditLog::query()
            ->where(
                'auditable_type',
                CatalogProduct::class
            )
            ->where(
                'auditable_id',
                $classified->id
            )
            ->where('event', 'updated')
            ->count();

        $reclassified = $manager->reclassify(
            $classified,
            $to,
            'Corrección controlada de tipo'
        );

        $this->assertSame(
            $to->id,
            (int) $reclassified->product_definition_id
        );

        $audit = AuditLog::query()
            ->where(
                'auditable_type',
                CatalogProduct::class
            )
            ->where(
                'auditable_id',
                $reclassified->id
            )
            ->where(
                'event',
                'catalog_product.semantic_definition_reclassified'
            )
            ->sole();

        $this->assertSame(
            $from->id,
            (int) $audit->old_values['product_definition_id']
        );
        $this->assertSame(
            $from->key,
            $audit->old_values['product_definition_key']
        );
        $this->assertSame(
            $to->id,
            (int) $audit->new_values['product_definition_id']
        );
        $this->assertSame(
            $to->key,
            $audit->new_values['product_definition_key']
        );
        $this->assertSame(
            'Corrección controlada de tipo',
            $audit->new_values['reason']
        );
        $this->assertGreaterThan(
            0,
            (int) $audit->new_values['product_schema_version_id']
        );

        $this->assertSame(
            $genericUpdatedBefore,
            AuditLog::query()
                ->where(
                    'auditable_type',
                    CatalogProduct::class
                )
                ->where(
                    'auditable_id',
                    $reclassified->id
                )
                ->where('event', 'updated')
                ->count()
        );

        $retry = $manager->reclassify(
            $reclassified,
            $to,
            ''
        );

        $this->assertSame(
            $to->id,
            (int) $retry->product_definition_id
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
                    $reclassified->id
                )
                ->where(
                    'event',
                    'catalog_product.semantic_definition_reclassified'
                )
                ->count()
        );
    }

    public function test_deprecated_assignment_remains_resolvable_and_retirement_is_blocked_while_assigned(): void
    {
        [, , $product] = $this->context('retirement');
        $definition = $this->publishedDefinition(
            'retirement'
        );

        app(
            CatalogProductDefinitionAssignmentManager::class
        )->classify(
            $product,
            $definition
        );

        $definition = app(
            ProductDefinitionManager::class
        )->deprecate($definition);

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

        $this->assertDomainFailure(
            fn () => app(
                ProductDefinitionManager::class
            )->retire($definition)
        );
    }

    public function test_each_capability_specific_evidence_blocks_reclassification(): void
    {
        foreach ([
            'reservation',
            'fractional',
            'variable',
            'preference',
        ] as $blocker) {
            [
                $organization,
                $actor,
                $product,
                $location,
            ] = $this->context(
                $blocker,
                withLocation: true
            );

            $from = $this->publishedDefinition(
                $blocker.'_from'
            );
            $to = $this->publishedDefinition(
                $blocker.'_to'
            );

            $manager = app(
                CatalogProductDefinitionAssignmentManager::class
            );

            $manager->classify(
                $product,
                $from
            );

            $this->seedBlocker(
                $blocker,
                $organization,
                $actor,
                $product,
                $location
            );

            $this->assertDomainFailure(
                fn () => $manager->reclassify(
                    $product->fresh(),
                    $to,
                    'Cambio bloqueado por '.$blocker
                )
            );

            $this->assertSame(
                $from->id,
                (int) $product->fresh()->product_definition_id
            );
        }
    }

    private function seedBlocker(
        string $blocker,
        Organization $organization,
        User $actor,
        CatalogProduct $product,
        InventoryLocation $location
    ): void {
        if ($blocker === 'reservation') {
            $this->insertReservation(
                $organization,
                $actor,
                $product,
                $location,
                'reservation'
            );

            return;
        }

        if ($blocker === 'fractional') {
            DB::table('fractional_containers')->insert([
                'organization_id' => $organization->id,
                'catalog_product_id' => $product->id,
                'product_presentation_id' => null,
                'inventory_location_id' => $location->id,
                'container_code' => 'CSF5-FC-'.$product->id,
                'normalized_container_code' =>
                    'csf5fc'.$product->id,
                'condition' => InventoryCondition::New->value,
                'state' => 'sealed',
                'original_base_quantity' => '1.000000',
                'remaining_base_quantity' => '1.000000',
                'base_unit_code' => $product->base_unit_code,
                'base_quantity_scale' => $product->quantity_scale,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        if ($blocker === 'variable') {
            DB::table('variable_quantity_fulfillments')->insert([
                'organization_id' => $organization->id,
                'public_id' => (string) Str::uuid(),
                'inventory_reservation_id' => null,
                'catalog_product_id' => $product->id,
                'inventory_location_id' => $location->id,
                'condition' => InventoryCondition::New->value,
                'requested_quantity' => '1.000000',
                'measured_quantity' => '1.000000',
                'accepted_quantity' => '1.000000',
                'base_unit_code' => $product->base_unit_code,
                'created_by_user_id' => $actor->id,
                'idempotency_key' =>
                    'csf5:variable:'.$product->id,
                'fingerprint' => str_repeat('b', 64),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        $reservationId = $this->insertReservation(
            $organization,
            $actor,
            $product,
            $location,
            'preference'
        );

        DB::table('fulfillment_preferences')->insert([
            'organization_id' => $organization->id,
            'public_id' => (string) Str::uuid(),
            'inventory_reservation_id' => $reservationId,
            'allow_another_brand' => false,
            'allow_equivalent_product' => false,
            'require_exact_product' => true,
            'consultation_required' => false,
            'consultation_channel' => null,
            'unavailable_line_fallback' =>
                FulfillmentUnavailableFallback::KeepPending->value,
            'created_by_user_id' => $actor->id,
            'idempotency_key' =>
                'csf5:preference:'.$product->id,
            'fingerprint' => str_repeat('c', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('inventory_reservations')
            ->where('id', $reservationId)
            ->update([
                'status' => InventoryReservationStatus::Released->value,
                'released_at' => now(),
                'release_reason' =>
                    'Fixture CSF-5: aislar blocker de preference.',
                'updated_at' => now(),
            ]);
    }

    private function insertReservation(
        Organization $organization,
        User $actor,
        CatalogProduct $product,
        InventoryLocation $location,
        string $suffix
    ): int {
        return (int) DB::table(
            'inventory_reservations'
        )->insertGetId([
            'organization_id' => $organization->id,
            'public_id' => (string) Str::uuid(),
            'catalog_product_id' => $product->id,
            'inventory_location_id' => $location->id,
            'condition' => InventoryCondition::New->value,
            'quantity' => '1.000000',
            'base_unit_code' => $product->base_unit_code,
            'status' => InventoryReservationStatus::Active->value,
            'expires_at' => now()->addHour(),
            'released_at' => null,
            'release_reason' => null,
            'created_by_user_id' => $actor->id,
            'released_by_user_id' => null,
            'idempotency_key' =>
                'csf5:reservation:'.$suffix.':'.$product->id,
            'fingerprint' => str_repeat('d', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
                'slug' => 'csf5-reclass-'.str_replace('_', '-', $suffix),
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
