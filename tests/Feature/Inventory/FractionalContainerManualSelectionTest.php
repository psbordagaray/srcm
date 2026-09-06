<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\FractionalContainerConsumptionManager;
use App\Domain\Inventory\FractionalContainerManager;
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

class FractionalContainerManualSelectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_manual_selection_can_choose_sealed_container_instead_of_open_container(): void
    {
        [$organization, $actor, $product, $location] =
            $this->scenario('FC-MANUAL-SEALED-FIRST');

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
            $location,
            '20',
            '200'
        );

        $warmup = $this->issue(
            $organization,
            $actor,
            $product,
            $location,
            '2'
        );

        $manager = app(
            FractionalContainerConsumptionManager::class
        );
        $manager->confirm($warmup, $actor);

        $sale = $this->issue(
            $organization,
            $actor,
            $product,
            $location,
            '10'
        );
        $line = $sale->lines->firstOrFail();

        $confirmed = $manager->confirm(
            $sale,
            $actor,
            FractionalContainerConsumptionPolicy::ManualSelection,
            [
                $line->id => [$second->id],
            ]
        );

        $first->refresh();
        $second->refresh();

        $this->assertSame(
            InventoryMovementStatus::Confirmed,
            $confirmed->status
        );
        $this->assertSame(
            FractionalContainerState::Open,
            $first->state
        );
        $this->assertSame(
            '18.000000',
            $first->remaining_base_quantity
        );
        $this->assertSame(
            FractionalContainerState::Open,
            $second->state
        );
        $this->assertSame(
            '190.000000',
            $second->remaining_base_quantity
        );

        $history = DB::table(
            'fractional_container_consumptions'
        )
            ->where('inventory_movement_line_id', $line->id)
            ->orderBy('sequence')
            ->get();

        $this->assertCount(1, $history);
        $this->assertSame(
            (int) $second->id,
            (int) $history[0]->fractional_container_id
        );
        $this->assertSame(
            FractionalContainerConsumptionPolicy::ManualSelection->value,
            (string) $history[0]->policy
        );
        $this->assertSame(
            '208.000000',
            $this->balance($product, $location)->quantity
        );
    }

    public function test_manual_selection_consumes_selected_containers_in_explicit_order(): void
    {
        [$organization, $actor, $product, $location] =
            $this->scenario('FC-MANUAL-ORDER');

        $this->receive(
            $organization,
            $actor,
            $product,
            $location,
            '40'
        );

        [$first, $second] = $this->containers(
            $organization,
            $product,
            $location,
            '20',
            '20'
        );

        $sale = $this->issue(
            $organization,
            $actor,
            $product,
            $location,
            '25'
        );
        $line = $sale->lines->firstOrFail();

        app(FractionalContainerConsumptionManager::class)
            ->confirm(
                $sale,
                $actor,
                FractionalContainerConsumptionPolicy::ManualSelection,
                [
                    $line->id => [$second->id, $first->id],
                ]
            );

        $first->refresh();
        $second->refresh();

        $this->assertSame(
            FractionalContainerState::Open,
            $first->state
        );
        $this->assertSame(
            '15.000000',
            $first->remaining_base_quantity
        );
        $this->assertSame(
            FractionalContainerState::Exhausted,
            $second->state
        );
        $this->assertSame(
            '0.000000',
            $second->remaining_base_quantity
        );

        $history = DB::table(
            'fractional_container_consumptions'
        )
            ->where('inventory_movement_line_id', $line->id)
            ->orderBy('sequence')
            ->get();

        $this->assertCount(2, $history);
        $this->assertSame(
            (int) $second->id,
            (int) $history[0]->fractional_container_id
        );
        $this->assertTrue(
            InventoryQuantity::equal(
                '20',
                $history[0]->consumed_base_quantity
            )
        );
        $this->assertSame(
            (int) $first->id,
            (int) $history[1]->fractional_container_id
        );
        $this->assertTrue(
            InventoryQuantity::equal(
                '5',
                $history[1]->consumed_base_quantity
            )
        );
    }

    public function test_manual_selection_never_opens_unselected_container_when_selected_capacity_is_insufficient(): void
    {
        [$organization, $actor, $product, $location] =
            $this->scenario('FC-MANUAL-NO-IMPLICIT');

        $this->receive(
            $organization,
            $actor,
            $product,
            $location,
            '40'
        );

        [$first, $second] = $this->containers(
            $organization,
            $product,
            $location,
            '10',
            '30'
        );

        $sale = $this->issue(
            $organization,
            $actor,
            $product,
            $location,
            '15'
        );
        $line = $sale->lines->firstOrFail();

        $this->assertDomainRejected(
            fn () => app(
                FractionalContainerConsumptionManager::class
            )->confirm(
                $sale,
                $actor,
                FractionalContainerConsumptionPolicy::ManualSelection,
                [
                    $line->id => [$first->id],
                ]
            )
        );

        $first->refresh();
        $second->refresh();

        $this->assertSame(
            FractionalContainerState::Sealed,
            $first->state
        );
        $this->assertSame(
            '10.000000',
            $first->remaining_base_quantity
        );
        $this->assertSame(
            FractionalContainerState::Sealed,
            $second->state
        );
        $this->assertSame(
            '30.000000',
            $second->remaining_base_quantity
        );
        $this->assertDatabaseCount(
            'fractional_container_consumptions',
            0
        );
        $this->assertSame(
            InventoryMovementStatus::Draft,
            $sale->refresh()->status
        );
        $this->assertSame(
            '40.000000',
            $this->balance($product, $location)->quantity
        );
    }

    public function test_manual_selection_rejects_duplicate_container_ids_before_mutation(): void
    {
        [$organization, $actor, $product, $location] =
            $this->scenario('FC-MANUAL-DUPLICATE');

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
            '20'
        );

        $sale = $this->issue(
            $organization,
            $actor,
            $product,
            $location,
            '5'
        );
        $line = $sale->lines->firstOrFail();

        $this->assertDomainRejected(
            fn () => app(
                FractionalContainerConsumptionManager::class
            )->confirm(
                $sale,
                $actor,
                FractionalContainerConsumptionPolicy::ManualSelection,
                [
                    $line->id => [
                        $container->id,
                        $container->id,
                    ],
                ]
            )
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
    }

    public function test_manual_selection_rejects_container_from_other_product(): void
    {
        [$organization, $actor, $product, $location] =
            $this->scenario('FC-MANUAL-PRODUCT');

        $otherProduct = $this->product('FC-MANUAL-OTHER');

        $this->receive(
            $organization,
            $actor,
            $product,
            $location,
            '20'
        );

        $wrongContainer = app(FractionalContainerManager::class)
            ->register(
                $organization->id,
                $otherProduct->id,
                $location->id,
                'DRUM-WRONG-'.$otherProduct->sku,
                '20'
            );

        $sale = $this->issue(
            $organization,
            $actor,
            $product,
            $location,
            '5'
        );
        $line = $sale->lines->firstOrFail();

        $this->assertDomainRejected(
            fn () => app(
                FractionalContainerConsumptionManager::class
            )->confirm(
                $sale,
                $actor,
                FractionalContainerConsumptionPolicy::ManualSelection,
                [
                    $line->id => [$wrongContainer->id],
                ]
            )
        );

        $wrongContainer->refresh();

        $this->assertSame(
            FractionalContainerState::Sealed,
            $wrongContainer->state
        );
        $this->assertSame(
            '20.000000',
            $wrongContainer->remaining_base_quantity
        );
        $this->assertDatabaseCount(
            'fractional_container_consumptions',
            0
        );
    }

    public function test_manual_selection_requires_explicit_selection_for_every_line(): void
    {
        [$organization, $actor, $product, $location] =
            $this->scenario('FC-MANUAL-EVERY-LINE');

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
            '20'
        );

        $movement = $this->movement(
            $organization,
            $actor,
            InventoryMovementType::Issue
        );

        $firstLine = $this->line(
            $movement,
            $product,
            source: $location,
            baseQuantity: '5',
            enteredQuantity: '5'
        );

        $this->line(
            $movement,
            $product,
            source: $location,
            baseQuantity: '5',
            enteredQuantity: '5'
        );

        $this->assertDomainRejected(
            fn () => app(
                FractionalContainerConsumptionManager::class
            )->confirm(
                $movement,
                $actor,
                FractionalContainerConsumptionPolicy::ManualSelection,
                [
                    $firstLine->id => [$container->id],
                ]
            )
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
            $movement->refresh()->status
        );
    }

    public function test_manual_selection_replay_is_idempotent_and_mismatch_fails_closed(): void
    {
        [$organization, $actor, $product, $location] =
            $this->scenario('FC-MANUAL-REPLAY');

        $this->receive(
            $organization,
            $actor,
            $product,
            $location,
            '40'
        );

        [$first, $second] = $this->containers(
            $organization,
            $product,
            $location,
            '20',
            '20'
        );

        $sale = $this->issue(
            $organization,
            $actor,
            $product,
            $location,
            '5'
        );
        $line = $sale->lines->firstOrFail();

        $manager = app(
            FractionalContainerConsumptionManager::class
        );
        $selection = [
            $line->id => [$second->id, $first->id],
        ];

        $firstConfirmation = $manager->confirm(
            $sale,
            $actor,
            FractionalContainerConsumptionPolicy::ManualSelection,
            $selection
        );

        $historyCount = DB::table(
            'fractional_container_consumptions'
        )->count();

        $secondConfirmation = $manager->confirm(
            $sale->id,
            $actor,
            FractionalContainerConsumptionPolicy::ManualSelection,
            $selection
        );

        $this->assertSame(
            $firstConfirmation->id,
            $secondConfirmation->id
        );
        $this->assertSame(
            $historyCount,
            DB::table(
                'fractional_container_consumptions'
            )->count()
        );

        $second->refresh();

        $this->assertSame(
            '15.000000',
            $second->remaining_base_quantity
        );

        $this->assertDomainRejected(
            fn () => $manager->confirm(
                $sale->id,
                $actor,
                FractionalContainerConsumptionPolicy::ManualSelection,
                [
                    $line->id => [$first->id],
                ]
            )
        );

        $second->refresh();

        $this->assertSame(
            '15.000000',
            $second->remaining_base_quantity
        );
        $this->assertSame(
            $historyCount,
            DB::table(
                'fractional_container_consumptions'
            )->count()
        );
    }

    public function test_generic_confirmer_rejects_unknown_fractional_policy_value(): void
    {
        [$organization, $actor, $product, $location] =
            $this->scenario('FC-MANUAL-UNKNOWN-POLICY');

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
            '20'
        );

        $sale = $this->issue(
            $organization,
            $actor,
            $product,
            $location,
            '5'
        );
        $line = $sale->lines->firstOrFail();

        DB::table('fractional_container_consumptions')
            ->insert([
                'organization_id' => $organization->id,
                'inventory_movement_line_id' => $line->id,
                'fractional_container_id' => $container->id,
                'sequence' => 1,
                'policy' => 'policy_not_registered',
                'consumed_base_quantity' => '5',
                'base_unit_code' => 'l',
                'state_before' =>
                    FractionalContainerState::Sealed->value,
                'state_after' =>
                    FractionalContainerState::Open->value,
                'remaining_before' => '20',
                'remaining_after' => '15',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $this->assertDomainRejected(
            fn () => app(InventoryMovementConfirmer::class)
                ->confirm($sale, $actor)
        );

        $this->assertSame(
            InventoryMovementStatus::Draft,
            $sale->refresh()->status
        );
        $this->assertSame(
            '20.000000',
            $this->balance($product, $location)->quantity
        );
        $this->assertSame(
            FractionalContainerState::Sealed,
            $container->refresh()->state
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
        $organization = $this->organization();
        $actor = $this->actor($organization);
        $product = $this->product($sku);
        $location = InventoryLocation::query()
            ->where('organization_id', $organization->id)
            ->where('active', true)
            ->orderBy('id')
            ->firstOrFail();

        return [$organization, $actor, $product, $location];
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
                ['slug' => 'fractional-manual-selection'],
                [
                    'name' => 'Fractional Manual Selection',
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
        string $firstQuantity,
        ?string $secondQuantity = null
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

        if ($secondQuantity !== null) {
            $containers[] = $manager->register(
                $organization->id,
                $product->id,
                $location->id,
                'DRUM-B-'.$product->sku,
                $secondQuantity
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
            enteredQuantity: $quantity
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
            enteredQuantity: $quantity
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
            'reason' => 'Fractional manual selection test',
            'idempotency_key' => 'fractional-manual:'.Str::uuid(),
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
