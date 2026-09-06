<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\FractionalContainerManager;
use App\Domain\Inventory\InventoryMovementConfirmer;
use App\Enums\FractionalContainerState;
use App\Enums\InventoryCondition;
use App\Enums\InventoryMovementStatus;
use App\Enums\InventoryMovementType;
use App\Enums\UserRole;
use App\Models\CatalogProduct;
use App\Models\FractionalContainer;
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

class FractionalContainerReceiptProvenanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_registers_container_from_confirmed_receipt_with_immutable_origin(): void
    {
        [$organization, $actor, $product, $location] =
            $this->scenario('FC-RECEIPT-PROVENANCE');

        $line = $this->confirmedInboundLine(
            $organization,
            $actor,
            $product,
            $location,
            InventoryMovementType::Receipt,
            '40'
        );

        $container = app(FractionalContainerManager::class)
            ->registerFromReceiptLine(
                $line->id,
                'DRUM-RP-001',
                '25'
            );

        $this->assertSame(
            (int) $line->id,
            (int) $container->received_inventory_movement_line_id
        );
        $this->assertSame(
            (int) $line->id,
            (int) $container->receiptLine->id
        );
        $this->assertSame(
            (int) $organization->id,
            (int) $container->organization_id
        );
        $this->assertSame(
            (int) $product->id,
            (int) $container->catalog_product_id
        );
        $this->assertSame(
            (int) $location->id,
            (int) $container->inventory_location_id
        );
        $this->assertSame(
            InventoryCondition::New,
            $container->condition
        );
        $this->assertSame(
            FractionalContainerState::Sealed,
            $container->state
        );
        $this->assertSame(
            '25.000000',
            $container->original_base_quantity
        );
        $this->assertSame(
            '25.000000',
            $container->remaining_base_quantity
        );
        $this->assertSame(
            (string) $line->base_unit_code,
            (string) $container->base_unit_code
        );

        $this->assertDomainRejected(
            fn () => $container->update([
                'received_inventory_movement_line_id' => null,
            ])
        );

        $this->assertSame(
            (int) $line->id,
            (int) $container->refresh()
                ->received_inventory_movement_line_id
        );
    }

    public function test_receipt_line_can_be_split_across_containers_but_never_overbound(): void
    {
        [$organization, $actor, $product, $location] =
            $this->scenario('FC-RECEIPT-SPLIT');

        $line = $this->confirmedInboundLine(
            $organization,
            $actor,
            $product,
            $location,
            InventoryMovementType::Receipt,
            '40'
        );

        $manager = app(FractionalContainerManager::class);

        $first = $manager->registerFromReceiptLine(
            $line->id,
            'DRUM-SPLIT-A',
            '25'
        );
        $second = $manager->registerFromReceiptLine(
            $line->id,
            'DRUM-SPLIT-B',
            '15'
        );

        $this->assertSame(
            (int) $line->id,
            (int) $first->received_inventory_movement_line_id
        );
        $this->assertSame(
            (int) $line->id,
            (int) $second->received_inventory_movement_line_id
        );

        $this->assertDomainRejected(
            fn () => $manager->registerFromReceiptLine(
                $line->id,
                'DRUM-SPLIT-C',
                '0.001'
            )
        );

        $this->assertDatabaseCount('fractional_containers', 2);
        $this->assertDatabaseMissing(
            'fractional_containers',
            [
                'normalized_container_code' => 'drumsplitc',
            ]
        );
    }

    public function test_registration_is_idempotent_for_same_receipt_origin_and_collision_safe_across_origins(): void
    {
        [$organization, $actor, $product, $location] =
            $this->scenario('FC-RECEIPT-IDEMPOTENT');

        $firstLine = $this->confirmedInboundLine(
            $organization,
            $actor,
            $product,
            $location,
            InventoryMovementType::Receipt,
            '20'
        );
        $secondLine = $this->confirmedInboundLine(
            $organization,
            $actor,
            $product,
            $location,
            InventoryMovementType::Receipt,
            '20'
        );

        $manager = app(FractionalContainerManager::class);

        $first = $manager->registerFromReceiptLine(
            $firstLine->id,
            'DRUM-IDEMPOTENT',
            '20'
        );

        $replay = $manager->registerFromReceiptLine(
            $firstLine->id,
            ' drum-idempotent ',
            '20.000'
        );

        $this->assertSame($first->id, $replay->id);
        $this->assertDatabaseCount('fractional_containers', 1);

        $this->assertDomainRejected(
            fn () => $manager->registerFromReceiptLine(
                $secondLine->id,
                'DRUM-IDEMPOTENT',
                '20'
            )
        );

        $this->assertDatabaseCount('fractional_containers', 1);
        $this->assertSame(
            (int) $firstLine->id,
            (int) $first->refresh()
                ->received_inventory_movement_line_id
        );
    }

    public function test_rejects_draft_receipt_and_confirmed_non_receipt_origin(): void
    {
        [$organization, $actor, $product, $location] =
            $this->scenario('FC-RECEIPT-REJECT');

        $draftLine = $this->draftInboundLine(
            $organization,
            $actor,
            $product,
            $location,
            InventoryMovementType::Receipt,
            '20'
        );

        $manager = app(FractionalContainerManager::class);

        $this->assertDomainRejected(
            fn () => $manager->registerFromReceiptLine(
                $draftLine->id,
                'DRUM-DRAFT',
                '20'
            )
        );

        $returnLine = $this->confirmedInboundLine(
            $organization,
            $actor,
            $product,
            $location,
            InventoryMovementType::CustomerReturn,
            '20'
        );

        $this->assertDomainRejected(
            fn () => $manager->registerFromReceiptLine(
                $returnLine->id,
                'DRUM-RETURN',
                '20'
            )
        );

        $this->assertDatabaseCount('fractional_containers', 0);
    }

    public function test_legacy_registration_remains_valid_but_has_no_receipt_provenance(): void
    {
        [$organization, $actor, $product, $location] =
            $this->scenario('FC-RECEIPT-LEGACY');

        $container = app(FractionalContainerManager::class)
            ->register(
                $organization->id,
                $product->id,
                $location->id,
                'DRUM-LEGACY',
                '20'
            );

        $this->assertNull(
            $container->received_inventory_movement_line_id
        );
        $this->assertNull($container->receiptLine);
        $this->assertSame(
            FractionalContainerState::Sealed,
            $container->state
        );
    }

    public function test_receipt_provenance_registration_does_not_create_or_modify_stock(): void
    {
        [$organization, $actor, $product, $location] =
            $this->scenario('FC-RECEIPT-NO-STOCK');

        $line = $this->confirmedInboundLine(
            $organization,
            $actor,
            $product,
            $location,
            InventoryMovementType::Receipt,
            '40'
        );

        $movementCount = DB::table('inventory_movements')->count();
        $movementLineCount = DB::table(
            'inventory_movement_lines'
        )->count();
        $balanceRows = DB::table('inventory_balances')
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
        $reservationCount = DB::table(
            'inventory_reservations'
        )->count();

        app(FractionalContainerManager::class)
            ->registerFromReceiptLine(
                $line->id,
                'DRUM-NO-STOCK',
                '40'
            );

        $this->assertSame(
            $movementCount,
            DB::table('inventory_movements')->count()
        );
        $this->assertSame(
            $movementLineCount,
            DB::table('inventory_movement_lines')->count()
        );
        $this->assertSame(
            $balanceRows,
            DB::table('inventory_balances')
                ->orderBy('id')
                ->get()
                ->map(fn ($row) => (array) $row)
                ->all()
        );
        $this->assertSame(
            $reservationCount,
            DB::table('inventory_reservations')->count()
        );
        $this->assertDatabaseCount('fractional_containers', 1);
    }

    public function test_model_rejects_forged_provenance_that_is_not_confirmed_receipt(): void
    {
        [$organization, $actor, $product, $location] =
            $this->scenario('FC-RECEIPT-FORGED');

        $draftLine = $this->draftInboundLine(
            $organization,
            $actor,
            $product,
            $location,
            InventoryMovementType::Receipt,
            '20'
        );

        $this->assertDomainRejected(
            fn () => FractionalContainer::query()->create([
                'organization_id' => $organization->id,
                'catalog_product_id' => $product->id,
                'inventory_location_id' => $location->id,
                'received_inventory_movement_line_id' =>
                    $draftLine->id,
                'container_code' => 'DRUM-FORGED',
                'condition' => InventoryCondition::New,
                'state' => FractionalContainerState::Sealed,
                'original_base_quantity' => '20',
                'remaining_base_quantity' => '20',
                'base_unit_code' => 'l',
                'base_quantity_scale' => 3,
            ])
        );

        $this->assertDatabaseCount('fractional_containers', 0);
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
        $actor = $this->actor($organization);
        $product = $this->product($sku);
        $location = InventoryLocation::query()
            ->where('organization_id', $organization->id)
            ->where('active', true)
            ->orderBy('id')
            ->firstOrFail();

        return [$organization, $actor, $product, $location];
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
                ['slug' => 'fractional-receipt-provenance'],
                [
                    'name' => 'Fractional Receipt Provenance',
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

    private function confirmedInboundLine(
        Organization $organization,
        User $actor,
        CatalogProduct $product,
        InventoryLocation $location,
        InventoryMovementType $type,
        string $quantity
    ): InventoryMovementLine {
        $line = $this->draftInboundLine(
            $organization,
            $actor,
            $product,
            $location,
            $type,
            $quantity
        );

        app(InventoryMovementConfirmer::class)->confirm(
            $line->inventory_movement_id,
            $actor
        );

        return $line->refresh();
    }

    private function draftInboundLine(
        Organization $organization,
        User $actor,
        CatalogProduct $product,
        InventoryLocation $location,
        InventoryMovementType $type,
        string $quantity
    ): InventoryMovementLine {
        $movement = InventoryMovement::query()->create([
            'organization_id' => $organization->id,
            'type' => $type,
            'status' => InventoryMovementStatus::Draft,
            'created_by_user_id' => $actor->id,
            'effective_at' => now(),
            'reason' => 'Fractional receipt provenance test',
            'idempotency_key' =>
                'fractional-receipt:'.Str::uuid(),
        ]);

        return InventoryMovementLine::query()->create([
            'organization_id' => $organization->id,
            'inventory_movement_id' => $movement->id,
            'sequence' => 1,
            'catalog_product_id' => $product->id,
            'condition' => InventoryCondition::New,
            'source_location_id' => null,
            'destination_location_id' => $location->id,
            'entered_quantity' => $quantity,
            'entered_unit_code' => 'l',
            'conversion_factor' => '1',
            'base_quantity' => $quantity,
            'base_unit_code' => 'l',
        ]);
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
