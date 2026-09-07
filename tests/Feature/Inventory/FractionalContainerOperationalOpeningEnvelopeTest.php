<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\FractionalContainerManager;
use App\Domain\Inventory\FractionalContainerOpeningManager;
use App\Domain\Inventory\InventoryQuantity;
use App\Enums\FractionalContainerState;
use App\Enums\InventoryCondition;
use App\Enums\InventoryLocationType;
use App\Enums\UserRole;
use App\Models\CatalogProduct;
use App\Models\FractionalContainer;
use App\Models\FractionalContainerOpeningAuthorization;
use App\Models\FractionalContainerOpeningEvent;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductCategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FractionalContainerOperationalOpeningEnvelopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_admin_authorizes_batch_and_operator_opens_multiple_containers_without_stock_mutation(): void
    {
        [$organization, $admin, $product, $location] =
            $this->scenario('OPEN-ENV-BATCH');

        $operator = $this->actor(
            $organization,
            UserRole::Operator
        );

        [$a, $b, $c] = $this->containers(
            $organization,
            $product,
            $location,
            ['OPEN-BATCH-A', 'OPEN-BATCH-B', 'OPEN-BATCH-C']
        );

        $before = $this->stockFingerprint(
            $organization,
            $product
        );

        $manager = app(
            FractionalContainerOpeningManager::class
        );

        $authorization = $manager->authorize(
            $organization->id,
            $product->id,
            $location->id,
            InventoryCondition::New,
            $admin,
            'opening-auth:batch',
            CarbonImmutable::now()->subMinute(),
            CarbonImmutable::now()->addHours(8),
            3,
            10,
            3,
            [$a->id, $b->id, $c->id]
        );

        $events = $manager->openBatch(
            $authorization,
            $operator,
            [$a->id, $b->id, $c->id],
            'opening-batch:morning'
        );

        $this->assertCount(3, $events);

        foreach ([$a, $b, $c] as $container) {
            $container->refresh();

            $this->assertSame(
                FractionalContainerState::Open,
                $container->state
            );
            $this->assertTrue(
                InventoryQuantity::equal(
                    $container->remaining_base_quantity,
                    '20'
                )
            );
        }

        $this->assertSame(
            $before,
            $this->stockFingerprint(
                $organization,
                $product
            )
        );

        $this->assertDatabaseCount(
            'fractional_container_opening_events',
            3
        );

        $event = $events->first();
        $this->assertInstanceOf(
            FractionalContainerOpeningEvent::class,
            $event
        );
        $this->assertSame(
            FractionalContainerState::Sealed,
            $event->state_before
        );
        $this->assertSame(
            FractionalContainerState::Open,
            $event->state_after
        );
        $this->assertTrue(
            InventoryQuantity::equal(
                $event->remaining_before,
                $event->remaining_after
            )
        );

        $this->assertDomainRejected(
            fn () => $event->delete()
        );

        $this->assertDomainRejected(
            fn () => $authorization
                ->forceFill([
                    'max_concurrent_open_containers' => 99,
                ])
                ->save()
        );
    }

    public function test_authorization_is_idempotent_and_key_collision_fails_closed(): void
    {
        [$organization, $admin, $product, $location] =
            $this->scenario('OPEN-ENV-AUTH-REPLAY');

        [$a, $b] = $this->containers(
            $organization,
            $product,
            $location,
            ['OPEN-AUTH-A', 'OPEN-AUTH-B']
        );

        $manager = app(
            FractionalContainerOpeningManager::class
        );
        $from = CarbonImmutable::now()->subMinute();
        $until = CarbonImmutable::now()->addHours(4);

        $first = $manager->authorize(
            $organization->id,
            $product->id,
            $location->id,
            InventoryCondition::New,
            $admin,
            'opening-auth:replay',
            $from,
            $until,
            2,
            4,
            2,
            [$a->id, $b->id]
        );

        $second = $manager->authorize(
            $organization->id,
            $product->id,
            $location->id,
            InventoryCondition::New,
            $admin,
            'opening-auth:replay',
            $from,
            $until,
            2,
            4,
            2,
            [$b->id, $a->id]
        );

        $this->assertSame($first->id, $second->id);

        $this->assertDomainRejected(
            fn () => $manager->authorize(
                $organization->id,
                $product->id,
                $location->id,
                InventoryCondition::New,
                $admin,
                'opening-auth:replay',
                $from,
                $until,
                3,
                4,
                2,
                [$a->id, $b->id]
            )
        );

        $this->assertDatabaseCount(
            'fractional_container_opening_authorizations',
            1
        );
    }

    public function test_exact_preauthorized_set_rejects_outside_container_atomically(): void
    {
        [$organization, $admin, $product, $location] =
            $this->scenario('OPEN-ENV-EXACT');

        $operator = $this->actor(
            $organization,
            UserRole::Operator
        );

        [$a, $b, $outside] = $this->containers(
            $organization,
            $product,
            $location,
            ['OPEN-EXACT-A', 'OPEN-EXACT-B', 'OPEN-EXACT-X']
        );

        $manager = app(
            FractionalContainerOpeningManager::class
        );

        $authorization = $manager->authorize(
            $organization->id,
            $product->id,
            $location->id,
            InventoryCondition::New,
            $admin,
            'opening-auth:exact',
            CarbonImmutable::now()->subMinute(),
            CarbonImmutable::now()->addHours(3),
            3,
            null,
            2,
            [$a->id, $b->id]
        );

        $this->assertDomainRejected(
            fn () => $manager->openBatch(
                $authorization,
                $operator,
                [$a->id, $outside->id],
                'opening-batch:outside'
            )
        );

        foreach ([$a, $b, $outside] as $container) {
            $this->assertSame(
                FractionalContainerState::Sealed,
                $container->refresh()->state
            );
        }

        $this->assertDatabaseCount(
            'fractional_container_opening_events',
            0
        );
    }

    public function test_concurrent_open_limit_and_window_quota_are_enforced_atomically(): void
    {
        [$organization, $admin, $product, $location] =
            $this->scenario('OPEN-ENV-LIMITS');

        $operator = $this->actor(
            $organization,
            UserRole::Operator
        );

        [$a, $b, $c] = $this->containers(
            $organization,
            $product,
            $location,
            ['OPEN-LIMIT-A', 'OPEN-LIMIT-B', 'OPEN-LIMIT-C']
        );

        $manager = app(
            FractionalContainerOpeningManager::class
        );

        $authorization = $manager->authorize(
            $organization->id,
            $product->id,
            $location->id,
            InventoryCondition::New,
            $admin,
            'opening-auth:limits',
            CarbonImmutable::now()->subMinute(),
            CarbonImmutable::now()->addHours(3),
            2,
            2
        );

        $manager->openBatch(
            $authorization,
            $operator,
            [$a->id, $b->id],
            'opening-batch:limits-first'
        );

        $this->assertDomainRejected(
            fn () => $manager->openBatch(
                $authorization,
                $operator,
                [$c->id],
                'opening-batch:limits-third'
            )
        );

        $this->assertSame(
            FractionalContainerState::Sealed,
            $c->refresh()->state
        );
        $this->assertDatabaseCount(
            'fractional_container_opening_events',
            2
        );
    }

    public function test_operator_can_execute_but_cannot_authorize_and_viewer_cannot_open(): void
    {
        [$organization, $admin, $product, $location] =
            $this->scenario('OPEN-ENV-ROLES');

        $operator = $this->actor(
            $organization,
            UserRole::Operator
        );
        $viewer = $this->actor(
            $organization,
            UserRole::Viewer
        );

        [$a, $b] = $this->containers(
            $organization,
            $product,
            $location,
            ['OPEN-ROLE-A', 'OPEN-ROLE-B']
        );

        $manager = app(
            FractionalContainerOpeningManager::class
        );

        $this->assertDomainRejected(
            fn () => $manager->authorize(
                $organization->id,
                $product->id,
                $location->id,
                InventoryCondition::New,
                $operator,
                'opening-auth:operator-denied',
                CarbonImmutable::now()->subMinute(),
                CarbonImmutable::now()->addHour(),
                2
            )
        );

        $authorization = $manager->authorize(
            $organization->id,
            $product->id,
            $location->id,
            InventoryCondition::New,
            $admin,
            'opening-auth:roles',
            CarbonImmutable::now()->subMinute(),
            CarbonImmutable::now()->addHours(2),
            2
        );

        $this->assertDomainRejected(
            fn () => $manager->openBatch(
                $authorization,
                $viewer,
                [$a->id],
                'opening-batch:viewer-denied'
            )
        );

        $events = $manager->openBatch(
            $authorization,
            $operator,
            [$a->id, $b->id],
            'opening-batch:operator-allowed'
        );

        $this->assertCount(2, $events);
    }

    public function test_future_or_revoked_envelope_blocks_new_opening_but_successful_replay_survives_revocation(): void
    {
        [$organization, $admin, $product, $location] =
            $this->scenario('OPEN-ENV-WINDOW');

        $operator = $this->actor(
            $organization,
            UserRole::Operator
        );

        [$a, $b] = $this->containers(
            $organization,
            $product,
            $location,
            ['OPEN-WINDOW-A', 'OPEN-WINDOW-B']
        );

        $manager = app(
            FractionalContainerOpeningManager::class
        );

        $future = $manager->authorize(
            $organization->id,
            $product->id,
            $location->id,
            InventoryCondition::New,
            $admin,
            'opening-auth:future',
            CarbonImmutable::now()->addHour(),
            CarbonImmutable::now()->addHours(2),
            2
        );

        $this->assertDomainRejected(
            fn () => $manager->openBatch(
                $future,
                $operator,
                [$a->id],
                'opening-batch:future-denied'
            )
        );

        $manager->revoke(
            $future,
            $admin,
            'Cambio de planificación operativa'
        );

        $active = $manager->authorize(
            $organization->id,
            $product->id,
            $location->id,
            InventoryCondition::New,
            $admin,
            'opening-auth:active',
            CarbonImmutable::now()->subMinute(),
            CarbonImmutable::now()->addHours(2),
            2
        );

        $first = $manager->openBatch(
            $active,
            $operator,
            [$a->id],
            'opening-batch:replay-after-revoke'
        );

        $manager->revoke(
            $active,
            $admin,
            'Cierre anticipado del sobre'
        );

        $replay = $manager->openBatch(
            $active,
            $operator,
            [$a->id],
            'opening-batch:replay-after-revoke'
        );

        $this->assertSame(
            $first->first()->id,
            $replay->first()->id
        );

        $this->assertDomainRejected(
            fn () => $manager->openBatch(
                $active,
                $operator,
                [$b->id],
                'opening-batch:new-after-revoke'
            )
        );
    }

    public function test_overlapping_envelopes_for_same_scope_are_rejected_until_scope_partition_exists(): void
    {
        [$organization, $admin, $product, $location] =
            $this->scenario('OPEN-ENV-OVERLAP');

        $manager = app(
            FractionalContainerOpeningManager::class
        );
        $from = CarbonImmutable::now()->subMinute();
        $until = CarbonImmutable::now()->addHours(4);

        $manager->authorize(
            $organization->id,
            $product->id,
            $location->id,
            InventoryCondition::New,
            $admin,
            'opening-auth:overlap-a',
            $from,
            $until,
            4
        );

        $this->assertDomainRejected(
            fn () => $manager->authorize(
                $organization->id,
                $product->id,
                $location->id,
                InventoryCondition::New,
                $admin,
                'opening-auth:overlap-b',
                $from->addHour(),
                $until->addHour(),
                5
            )
        );

        $this->assertDatabaseCount(
            'fractional_container_opening_authorizations',
            1
        );
    }

    public function test_open_batch_replay_is_idempotent_and_new_key_cannot_reopen_same_container(): void
    {
        [$organization, $admin, $product, $location] =
            $this->scenario('OPEN-ENV-OPEN-REPLAY');

        $operator = $this->actor(
            $organization,
            UserRole::Operator
        );

        [$a, $b] = $this->containers(
            $organization,
            $product,
            $location,
            ['OPEN-REPLAY-A', 'OPEN-REPLAY-B']
        );

        $manager = app(
            FractionalContainerOpeningManager::class
        );

        $authorization = $manager->authorize(
            $organization->id,
            $product->id,
            $location->id,
            InventoryCondition::New,
            $admin,
            'opening-auth:open-replay',
            CarbonImmutable::now()->subMinute(),
            CarbonImmutable::now()->addHours(2),
            2
        );

        $first = $manager->openBatch(
            $authorization,
            $operator,
            [$b->id, $a->id],
            'opening-batch:same'
        );

        $second = $manager->openBatch(
            $authorization,
            $operator,
            [$a->id, $b->id],
            'opening-batch:same'
        );

        $this->assertSame(
            $first->pluck('id')->sort()->values()->all(),
            $second->pluck('id')->sort()->values()->all()
        );

        $this->assertDomainRejected(
            fn () => $manager->openBatch(
                $authorization,
                $operator,
                [$a->id],
                'opening-batch:different'
            )
        );

        $this->assertDatabaseCount(
            'fractional_container_opening_events',
            2
        );
    }

    public function test_authorization_rejects_non_preparation_location_without_mutation(): void
    {
        $organization = Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();

        $admin = $this->actor(
            $organization,
            UserRole::Admin
        );
        $product = $this->product(
            'OPEN-ENV-NON-PREPARATION'
        );

        $warehouse = InventoryLocation::query()
            ->where('organization_id', $organization->id)
            ->where(
                'type',
                InventoryLocationType::Warehouse->value
            )
            ->where('active', true)
            ->orderBy('id')
            ->firstOrFail();

        $manager = app(
            FractionalContainerOpeningManager::class
        );

        $this->assertDomainRejected(
            fn () => $manager->authorize(
                $organization->id,
                $product->id,
                $warehouse->id,
                InventoryCondition::New,
                $admin,
                'opening-auth:non-preparation',
                CarbonImmutable::now()->subMinute(),
                CarbonImmutable::now()->addHours(2),
                2
            )
        );

        $this->assertDatabaseCount(
            'fractional_container_opening_authorizations',
            0
        );
    }

    public function test_existing_authorization_cannot_open_after_location_leaves_preparation(): void
    {
        [$organization, $admin, $product, $location] =
            $this->scenario('OPEN-ENV-PREPARATION-DRIFT');

        $operator = $this->actor(
            $organization,
            UserRole::Operator
        );

        [$container] = $this->containers(
            $organization,
            $product,
            $location,
            ['OPEN-PREPARATION-DRIFT-A']
        );

        $manager = app(
            FractionalContainerOpeningManager::class
        );

        $authorization = $manager->authorize(
            $organization->id,
            $product->id,
            $location->id,
            InventoryCondition::New,
            $admin,
            'opening-auth:preparation-drift',
            CarbonImmutable::now()->subMinute(),
            CarbonImmutable::now()->addHours(2),
            2
        );

        $location->forceFill([
            'type' => InventoryLocationType::Warehouse,
        ])->save();

        $this->assertDomainRejected(
            fn () => $manager->openBatch(
                $authorization,
                $operator,
                [$container->id],
                'opening-batch:preparation-drift'
            )
        );

        $container->refresh();

        $this->assertSame(
            FractionalContainerState::Sealed,
            $container->state
        );
        $this->assertDatabaseCount(
            'fractional_container_opening_events',
            0
        );
    }

    /**
     * @return array{
     *     Organization,
     *     User,
     *     CatalogProduct,
     *     InventoryLocation
     * }
     */
    private function scenario(string $sku): array
    {
        $organization = Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();

        $admin = $this->actor(
            $organization,
            UserRole::Admin
        );
        $product = $this->product($sku);

        $warehouse = InventoryLocation::query()
            ->where('organization_id', $organization->id)
            ->where(
                'type',
                InventoryLocationType::Warehouse->value
            )
            ->where('active', true)
            ->orderBy('id')
            ->firstOrFail();

        $location = InventoryLocation::query()->create([
            'organization_id' => $organization->id,
            'parent_id' => $warehouse->id,
            'name' => 'Preparación '.$sku,
            'type' => InventoryLocationType::Preparation,
            'active' => true,
        ]);

        return [
            $organization,
            $admin,
            $product,
            $location,
        ];
    }

    private function actor(
        Organization $organization,
        UserRole $role
    ): User {
        $user = User::factory()->create([
            'role' => $role,
            'email_verified_at' => now(),
        ]);

        OrganizationMembership::withoutEvents(
            fn () => OrganizationMembership::query()->updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'user_id' => $user->id,
                ],
                [
                    'role' => $role->value,
                    'active' => true,
                ]
            )
        );

        $user->forceFill([
            'current_organization_id' => $organization->id,
        ])->saveQuietly();

        return $user->refresh();
    }

    private function product(string $sku): CatalogProduct
    {
        $category = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()->firstOrCreate(
                ['slug' => 'fractional-opening-envelope'],
                [
                    'name' => 'Fractional Opening Envelope',
                    'active' => true,
                ]
            )
        );

        return CatalogProduct::withoutEvents(
            fn () => CatalogProduct::query()->create([
                'product_category_id' => $category->id,
                'sku' => $sku,
                'name' => 'Producto fraccionable '.$sku,
                'base_unit_code' => 'l',
                'quantity_scale' => 3,
                'active' => true,
            ])->refresh()
        );
    }

    /**
     * @param list<string> $codes
     * @return list<FractionalContainer>
     */
    private function containers(
        Organization $organization,
        CatalogProduct $product,
        InventoryLocation $location,
        array $codes
    ): array {
        $manager = app(FractionalContainerManager::class);

        return array_map(
            fn (string $code): FractionalContainer =>
                $manager->register(
                    $organization->id,
                    $product->id,
                    $location->id,
                    $code,
                    '20',
                    InventoryCondition::New
                ),
            $codes
        );
    }

    private function stockFingerprint(
        Organization $organization,
        CatalogProduct $product
    ): array {
        $tables = [
            'inventory_movements',
            'inventory_movement_lines',
            'inventory_balances',
            'inventory_reservations',
        ];

        $counts = [];

        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $query = DB::table($table);

            if (
                Schema::hasColumn(
                    $table,
                    'organization_id'
                )
            ) {
                $query->where(
                    'organization_id',
                    $organization->id
                );
            }

            if (
                Schema::hasColumn(
                    $table,
                    'catalog_product_id'
                )
            ) {
                $query->where(
                    'catalog_product_id',
                    $product->id
                );
            }

            $counts[$table] = $query->count();
        }

        return $counts;
    }

    private function assertDomainRejected(
        callable $callback
    ): void {
        try {
            $callback();
        } catch (DomainException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail(
            'La operación debía ser rechazada por el dominio.'
        );
    }
}
