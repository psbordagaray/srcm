<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\FractionalContainerConsumptionManager;
use App\Domain\Inventory\FractionalContainerManager;
use App\Domain\Inventory\FractionalContainerOpeningManager;
use App\Domain\Inventory\InventoryMovementConfirmer;
use App\Domain\Inventory\InventoryQuantity;
use App\Enums\FractionalContainerConsumptionPolicy;
use App\Enums\FractionalContainerState;
use App\Enums\InventoryCondition;
use App\Enums\InventoryMovementStatus;
use App\Enums\InventoryMovementType;
use App\Enums\UserRole;
use App\Models\CatalogProduct;
use App\Models\FractionalContainer;
use App\Models\FractionalContainerConsumption;
use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementLine;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductCategory;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FractionalContainerConsumptionPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_sealed_container_requires_active_opening_authorization_without_partial_mutation(): void
    {
        [$organization, $actor, $product, $location] =
            $this->scenario(
                'FC-CONSUME-OPEN-AUTH-MISSING',
                authorizeOpening: false
            );

        $this->receive(
            $organization,
            $actor,
            $product,
            $location,
            '20'
        );

        [$container] = $this->containers(
            $organization,
            $product,
            $location,
            second: false
        );

        $issue = $this->issue(
            $organization,
            $actor,
            $product,
            $location,
            '5'
        );

        $this->assertDomainRejected(
            fn () => app(
                FractionalContainerConsumptionManager::class
            )->confirm($issue, $actor)
        );

        $container->refresh();

        $this->assertSame(
            FractionalContainerState::Sealed,
            $container->state
        );
        $this->assertTrue(
            InventoryQuantity::equal(
                '20',
                $container->remaining_base_quantity
            )
        );
        $this->assertDatabaseCount(
            'fractional_container_opening_events',
            0
        );
        $this->assertDatabaseCount(
            'fractional_container_consumptions',
            0
        );
        $this->assertSame(
            InventoryMovementStatus::Draft,
            $issue->refresh()->status
        );
        $this->assertSame(
            '20.000000',
            $this->balance($product, $location)->quantity
        );
    }

    public function test_authorized_sealed_consumption_records_opening_before_consumption(): void
    {
        [$organization, $actor, $product, $location] =
            $this->scenario('FC-CONSUME-OPEN-AUTHORIZED');

        $this->receive(
            $organization,
            $actor,
            $product,
            $location,
            '20'
        );

        [$container] = $this->containers(
            $organization,
            $product,
            $location,
            second: false
        );

        $issue = $this->issue(
            $organization,
            $actor,
            $product,
            $location,
            '5'
        );
        $line = $issue->lines->firstOrFail();

        app(FractionalContainerConsumptionManager::class)
            ->confirm($issue, $actor);

        $opening = DB::table(
            'fractional_container_opening_events'
        )
            ->where('fractional_container_id', $container->id)
            ->first();
        $history = DB::table(
            'fractional_container_consumptions'
        )
            ->where('inventory_movement_line_id', $line->id)
            ->first();

        $this->assertNotNull($opening);
        $this->assertNotNull($history);
        $this->assertSame(
            FractionalContainerState::Sealed->value,
            (string) $opening->state_before
        );
        $this->assertSame(
            FractionalContainerState::Open->value,
            (string) $opening->state_after
        );
        $this->assertTrue(
            InventoryQuantity::equal(
                $opening->remaining_before,
                $opening->remaining_after
            )
        );
        $this->assertTrue(
            InventoryQuantity::equal(
                '20',
                $opening->remaining_before
            )
        );
        $this->assertSame(
            FractionalContainerState::Open->value,
            (string) $history->state_before
        );
        $this->assertSame(
            FractionalContainerState::Open->value,
            (string) $history->state_after
        );
        $this->assertTrue(
            InventoryQuantity::equal(
                '15',
                $history->remaining_after
            )
        );

        $container->refresh();

        $this->assertSame(
            FractionalContainerState::Open,
            $container->state
        );
        $this->assertTrue(
            InventoryQuantity::equal(
                '15',
                $container->remaining_base_quantity
            )
        );
        $this->assertSame(
            '15.000000',
            $this->balance($product, $location)->quantity
        );
    }

    public function test_open_container_is_exhausted_before_next_sealed_container(): void
    {
        [$organization, $actor, $product, $location] =
            $this->scenario('FC-CONSUME-18-7');

        $this->receive(
            $organization,
            $actor,
            $product,
            $location,
            '220'
        );

        [$first, $second] = $this->containers(
            $organization,
            $product,
            $location
        );

        $warmup = $this->issue(
            $organization,
            $actor,
            $product,
            $location,
            '2'
        );

        app(FractionalContainerConsumptionManager::class)
            ->confirm($warmup, $actor);

        $first->refresh();
        $second->refresh();

        $this->assertSame(
            FractionalContainerState::Open,
            $first->state
        );
        $this->assertSame(
            '18.000000',
            $first->remaining_base_quantity
        );
        $this->assertSame(
            FractionalContainerState::Sealed,
            $second->state
        );

        $sale = $this->issue(
            $organization,
            $actor,
            $product,
            $location,
            '25'
        );

        $confirmed = app(
            FractionalContainerConsumptionManager::class
        )->confirm($sale, $actor);

        $first->refresh();
        $second->refresh();

        $this->assertSame(
            InventoryMovementStatus::Confirmed,
            $confirmed->status
        );
        $this->assertSame(
            FractionalContainerState::Exhausted,
            $first->state
        );
        $this->assertSame(
            '0.000000',
            $first->remaining_base_quantity
        );
        $this->assertSame(
            FractionalContainerState::Open,
            $second->state
        );
        $this->assertSame(
            '193.000000',
            $second->remaining_base_quantity
        );

        $history = DB::table(
            'fractional_container_consumptions'
        )
            ->where(
                'inventory_movement_line_id',
                $sale->lines()->firstOrFail()->id
            )
            ->orderBy('sequence')
            ->get();

        $this->assertCount(2, $history);
        $this->assertSame(
            (int) $first->id,
            (int) $history[0]->fractional_container_id
        );
        $this->assertTrue(
            InventoryQuantity::equal(
                '18',
                $history[0]->consumed_base_quantity
            )
        );
        $this->assertSame(
            (int) $second->id,
            (int) $history[1]->fractional_container_id
        );
        $this->assertTrue(
            InventoryQuantity::equal(
                '7',
                $history[1]->consumed_base_quantity
            )
        );
        $this->assertSame(
            '193.000000',
            $this->balance($product, $location)->quantity
        );
    }

    public function test_sufficient_open_container_does_not_open_next_sealed_container(): void
    {
        [$organization, $actor, $product, $location] =
            $this->scenario('FC-CONSUME-OPEN-FIRST');

        $this->receive(
            $organization,
            $actor,
            $product,
            $location,
            '220'
        );

        [$first, $second] = $this->containers(
            $organization,
            $product,
            $location
        );

        $firstIssue = $this->issue(
            $organization,
            $actor,
            $product,
            $location,
            '2'
        );

        $manager = app(
            FractionalContainerConsumptionManager::class
        );
        $manager->confirm($firstIssue, $actor);

        $secondIssue = $this->issue(
            $organization,
            $actor,
            $product,
            $location,
            '10'
        );
        $manager->confirm($secondIssue, $actor);

        $first->refresh();
        $second->refresh();

        $this->assertSame(
            '8.000000',
            $first->remaining_base_quantity
        );
        $this->assertSame(
            FractionalContainerState::Open,
            $first->state
        );
        $this->assertSame(
            '200.000000',
            $second->remaining_base_quantity
        );
        $this->assertSame(
            FractionalContainerState::Sealed,
            $second->state
        );

        $this->assertSame(
            1,
            DB::table('fractional_container_consumptions')
                ->where(
                    'inventory_movement_line_id',
                    $secondIssue->lines()->firstOrFail()->id
                )
                ->count()
        );
    }

    public function test_repeated_confirmation_is_exactly_idempotent(): void
    {
        [$organization, $actor, $product, $location] =
            $this->scenario('FC-CONSUME-IDEMPOTENT');

        $this->receive(
            $organization,
            $actor,
            $product,
            $location,
            '20'
        );

        [$container] = $this->containers(
            $organization,
            $product,
            $location,
            second: false
        );

        $issue = $this->issue(
            $organization,
            $actor,
            $product,
            $location,
            '5'
        );

        $manager = app(
            FractionalContainerConsumptionManager::class
        );

        $first = $manager->confirm($issue, $actor);
        $historyCount = DB::table(
            'fractional_container_consumptions'
        )->count();

        $second = $manager->confirm($issue->id, $actor);

        $container->refresh();

        $this->assertSame($first->id, $second->id);
        $this->assertSame(
            $historyCount,
            DB::table(
                'fractional_container_consumptions'
            )->count()
        );
        $this->assertSame(
            '15.000000',
            $container->remaining_base_quantity
        );
        $this->assertSame(
            '15.000000',
            $this->balance($product, $location)->quantity
        );
    }

    public function test_inventory_confirmation_failure_rolls_back_container_and_history(): void
    {
        [$organization, $actor, $product, $location] =
            $this->scenario('FC-CONSUME-ATOMIC-ROLLBACK');

        $this->receive(
            $organization,
            $actor,
            $product,
            $location,
            '5'
        );

        [$container] = $this->containers(
            $organization,
            $product,
            $location,
            second: false
        );

        $issue = $this->issue(
            $organization,
            $actor,
            $product,
            $location,
            '10'
        );

        $this->assertDomainRejected(
            fn () => app(
                FractionalContainerConsumptionManager::class
            )->confirm($issue, $actor)
        );

        $container->refresh();

        $this->assertSame(
            FractionalContainerState::Sealed,
            $container->state
        );
        $this->assertSame(
            '20.000000',
            $container->remaining_base_quantity
        );
        $this->assertDatabaseCount(
            'fractional_container_consumptions',
            0
        );
        $this->assertSame(
            InventoryMovementStatus::Draft,
            $issue->refresh()->status
        );
        $this->assertSame(
            '5.000000',
            $this->balance($product, $location)->quantity
        );
    }

    public function test_insufficient_traceable_containers_fail_before_inventory_confirmation(): void
    {
        [$organization, $actor, $product, $location] =
            $this->scenario('FC-CONSUME-TRACEABILITY');

        $this->receive(
            $organization,
            $actor,
            $product,
            $location,
            '30'
        );

        [$container] = $this->containers(
            $organization,
            $product,
            $location,
            second: false
        );

        $issue = $this->issue(
            $organization,
            $actor,
            $product,
            $location,
            '25'
        );

        $this->assertDomainRejected(
            fn () => app(
                FractionalContainerConsumptionManager::class
            )->confirm($issue, $actor)
        );

        $container->refresh();

        $this->assertSame(
            FractionalContainerState::Sealed,
            $container->state
        );
        $this->assertSame(
            '20.000000',
            $container->remaining_base_quantity
        );
        $this->assertDatabaseCount(
            'fractional_container_consumptions',
            0
        );
        $this->assertSame(
            InventoryMovementStatus::Draft,
            $issue->refresh()->status
        );
        $this->assertSame(
            '30.000000',
            $this->balance($product, $location)->quantity
        );
    }

    public function test_closed_presentation_does_not_imply_container_opening(): void
    {
        [$organization, $actor, $product, $location] =
            $this->scenario('FC-CONSUME-CLOSED');

        $this->receive(
            $organization,
            $actor,
            $product,
            $location,
            '200'
        );

        [$container] = $this->containers(
            $organization,
            $product,
            $location,
            firstQuantity: '200',
            second: false
        );

        $movement = $this->movement(
            $organization,
            $actor,
            InventoryMovementType::Issue
        );

        $this->line(
            $movement,
            $product,
            source: $location,
            enteredQuantity: '1',
            conversionFactor: '200',
            baseQuantity: '200',
            enteredUnit: 'drum200',
            baseUnit: 'l'
        );

        $this->assertDomainRejected(
            fn () => app(
                FractionalContainerConsumptionManager::class
            )->confirm($movement, $actor)
        );

        $container->refresh();

        $this->assertSame(
            FractionalContainerState::Sealed,
            $container->state
        );
        $this->assertSame(
            '200.000000',
            $container->remaining_base_quantity
        );
        $this->assertDatabaseCount(
            'fractional_container_consumptions',
            0
        );
        $this->assertSame(
            InventoryMovementStatus::Draft,
            $movement->refresh()->status
        );
    }

    public function test_direct_confirmation_cannot_bypass_fractional_traceability(): void
    {
        [$organization, $actor, $product, $location] =
            $this->scenario('FC-CONSUME-BYPASS');

        $this->receive(
            $organization,
            $actor,
            $product,
            $location,
            '20'
        );

        [$container] = $this->containers(
            $organization,
            $product,
            $location,
            second: false
        );

        $issue = $this->issue(
            $organization,
            $actor,
            $product,
            $location,
            '5'
        );

        $this->assertDomainRejected(
            fn () => app(InventoryMovementConfirmer::class)
                ->confirm($issue, $actor)
        );

        $container->refresh();

        $this->assertSame(
            FractionalContainerState::Sealed,
            $container->state
        );
        $this->assertSame(
            '20.000000',
            $container->remaining_base_quantity
        );
        $this->assertDatabaseCount(
            'fractional_container_consumptions',
            0
        );
        $this->assertSame(
            InventoryMovementStatus::Draft,
            $issue->refresh()->status
        );
        $this->assertSame(
            '20.000000',
            $this->balance($product, $location)->quantity
        );
    }

    public function test_consumption_history_is_immutable_and_policy_scope_is_closed(): void
    {
        [$organization, $actor, $product, $location] =
            $this->scenario('FC-CONSUME-HISTORY');

        $this->receive(
            $organization,
            $actor,
            $product,
            $location,
            '20'
        );

        $this->containers(
            $organization,
            $product,
            $location,
            second: false
        );

        $issue = $this->issue(
            $organization,
            $actor,
            $product,
            $location,
            '5'
        );

        app(FractionalContainerConsumptionManager::class)
            ->confirm($issue, $actor);

        $history = FractionalContainerConsumption::query()
            ->firstOrFail();

        $this->assertSame(
            FractionalContainerConsumptionPolicy::ExhaustOpenContainer,
            $history->policy
        );

        $this->assertDomainRejected(
            fn () => $history->update([
                'consumed_base_quantity' => '4',
            ])
        );

        $history->refresh();

        $this->assertDomainRejected(
            fn () => $history->delete()
        );

        $this->assertDomainRejected(
            fn () => FractionalContainerConsumption::query()
                ->create([
                    'organization_id' => $organization->id,
                    'inventory_movement_line_id' =>
                        $issue->lines()->firstOrFail()->id,
                    'fractional_container_id' =>
                        $history->fractional_container_id,
                    'sequence' => 99,
                    'policy' =>
                        FractionalContainerConsumptionPolicy::
                            ExhaustOpenContainer,
                    'consumed_base_quantity' => '1',
                    'base_unit_code' => 'l',
                    'state_before' =>
                        FractionalContainerState::Open,
                    'state_after' =>
                        FractionalContainerState::Open,
                    'remaining_before' => '15',
                    'remaining_after' => '14',
                ])
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
    private function scenario(
        string $sku,
        bool $authorizeOpening = true
    ): array
    {
        $organization = $this->organization();
        $actor = $this->actor($organization);
        $product = $this->product($sku);
        $location = InventoryLocation::query()
            ->where('organization_id', $organization->id)
            ->where('active', true)
            ->orderBy('id')
            ->firstOrFail();

        if ($authorizeOpening) {
            $this->authorizeOpening(
                $organization,
                $actor,
                $product,
                $location
            );
        }

        return [$organization, $actor, $product, $location];
    }

    private function authorizeOpening(
        Organization $organization,
        User $actor,
        CatalogProduct $product,
        InventoryLocation $location
    ): void {
        app(FractionalContainerOpeningManager::class)->authorize(
            organizationId: $organization->id,
            catalogProductId: $product->id,
            inventoryLocationId: $location->id,
            condition: InventoryCondition::New,
            authorizer: $actor,
            idempotencyKey: 'test-opening-'.hash(
                'sha256',
                (string) $product->sku
            ),
            validFrom: now()->subMinute(),
            validUntil: now()->addHour(),
            maxConcurrentOpenContainers: 10,
            maxNewOpenings: 10
        );
    }

    private function organization(): Organization
    {
        return Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();
    }

    private function actor(Organization $organization): User
    {
        $user = User::factory()->create([
            'role' => UserRole::Admin,
            'email_verified_at' => now(),
        ]);

        OrganizationMembership::withoutEvents(
            fn () => OrganizationMembership::query()->updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'user_id' => $user->id,
                ],
                [
                    'role' => UserRole::Admin->value,
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
                ['slug' => 'fractional-consumption-policy'],
                [
                    'name' => 'Fractional Consumption Policy',
                    'active' => true,
                ]
            )
        );

        return CatalogProduct::withoutEvents(
            fn () => CatalogProduct::query()->create([
                'product_category_id' => $category->id,
                'sku' => $sku,
                'name' => 'Lubricante '.$sku,
                'base_unit_code' => 'l',
                'quantity_scale' => 3,
                'active' => true,
            ])->refresh()
        );
    }

    /**
     * @return list<FractionalContainer>
     */
    private function containers(
        Organization $organization,
        CatalogProduct $product,
        InventoryLocation $location,
        string $firstQuantity = '20',
        bool $second = true
    ): array {
        $manager = app(FractionalContainerManager::class);

        $containers = [
            $manager->register(
                $organization->id,
                $product->id,
                $location->id,
                'DRUM-A-'.$product->sku,
                $firstQuantity
            ),
        ];

        if ($second) {
            $containers[] = $manager->register(
                $organization->id,
                $product->id,
                $location->id,
                'DRUM-B-'.$product->sku,
                '200'
            );
        }

        return $containers;
    }

    private function receive(
        Organization $organization,
        User $actor,
        CatalogProduct $product,
        InventoryLocation $location,
        string $quantity
    ): InventoryMovement {
        $movement = $this->movement(
            $organization,
            $actor,
            InventoryMovementType::Receipt
        );

        $this->line(
            $movement,
            $product,
            destination: $location,
            baseQuantity: $quantity,
            enteredQuantity: $quantity,
            enteredUnit: 'l',
            baseUnit: 'l'
        );

        return app(InventoryMovementConfirmer::class)
            ->confirm($movement, $actor);
    }

    private function issue(
        Organization $organization,
        User $actor,
        CatalogProduct $product,
        InventoryLocation $location,
        string $quantity
    ): InventoryMovement {
        $movement = $this->movement(
            $organization,
            $actor,
            InventoryMovementType::Issue
        );

        $this->line(
            $movement,
            $product,
            source: $location,
            baseQuantity: $quantity,
            enteredQuantity: $quantity,
            enteredUnit: 'l',
            baseUnit: 'l'
        );

        return $movement->refresh()->load('lines');
    }

    private function movement(
        Organization $organization,
        User $actor,
        InventoryMovementType $type
    ): InventoryMovement {
        return InventoryMovement::query()->create([
            'organization_id' => $organization->id,
            'type' => $type,
            'status' => InventoryMovementStatus::Draft,
            'created_by_user_id' => $actor->id,
            'effective_at' => now(),
            'reason' => 'Fractional consumption policy test',
            'idempotency_key' => 'fractional-consume:'.Str::uuid(),
        ]);
    }

    private function line(
        InventoryMovement $movement,
        CatalogProduct $product,
        ?InventoryLocation $source = null,
        ?InventoryLocation $destination = null,
        string $enteredQuantity = '1',
        string $conversionFactor = '1',
        string $baseQuantity = '1',
        string $enteredUnit = 'l',
        string $baseUnit = 'l'
    ): InventoryMovementLine {
        return InventoryMovementLine::query()->create([
            'organization_id' => $movement->organization_id,
            'inventory_movement_id' => $movement->id,
            'sequence' => $movement->lines()->count() + 1,
            'catalog_product_id' => $product->id,
            'condition' => InventoryCondition::New,
            'source_location_id' => $source?->id,
            'destination_location_id' => $destination?->id,
            'entered_quantity' => $enteredQuantity,
            'entered_unit_code' => $enteredUnit,
            'conversion_factor' => $conversionFactor,
            'base_quantity' => $baseQuantity,
            'base_unit_code' => $baseUnit,
        ]);
    }

    private function balance(
        CatalogProduct $product,
        InventoryLocation $location
    ): InventoryBalance {
        return InventoryBalance::query()
            ->where('organization_id', $this->organization()->id)
            ->where('catalog_product_id', $product->id)
            ->where('inventory_location_id', $location->id)
            ->where('condition', InventoryCondition::New->value)
            ->firstOrFail();
    }

    private function assertDomainRejected(callable $callback): void
    {
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
