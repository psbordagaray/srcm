<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\InventoryMovementCreator;
use App\Domain\Inventory\InventoryMovementDraftData;
use App\Domain\Inventory\InventoryMovementLineData;
use App\Domain\Inventory\InventoryMovementConfirmer;
use App\Enums\InventoryCondition;
use App\Enums\InventoryMovementStatus;
use App\Enums\InventoryMovementType;
use App\Enums\UserRole;
use App\Models\CatalogProduct;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductCategory;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryMovementCreationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_inventory_role_matrix_is_explicit(): void
    {
        $this->assertTrue(UserRole::Admin->canReceiveInventory());
        $this->assertTrue(UserRole::Admin->canIssueInventory());
        $this->assertTrue(UserRole::Admin->canTransferInventory());
        $this->assertTrue(UserRole::Admin->canProcessInventoryReturns());
        $this->assertTrue(UserRole::Admin->canAdjustInventory());
        $this->assertTrue(UserRole::Admin->canCorrectInventory());
        $this->assertTrue(UserRole::Admin->canRebuildInventory());

        $this->assertTrue(UserRole::Operator->canReceiveInventory());
        $this->assertTrue(UserRole::Operator->canIssueInventory());
        $this->assertTrue(UserRole::Operator->canTransferInventory());
        $this->assertTrue(
            UserRole::Operator->canProcessInventoryReturns()
        );
        $this->assertFalse(UserRole::Operator->canAdjustInventory());
        $this->assertFalse(UserRole::Operator->canCorrectInventory());
        $this->assertFalse(UserRole::Operator->canRebuildInventory());

        $this->assertFalse(UserRole::Viewer->canReceiveInventory());
        $this->assertFalse(UserRole::Viewer->canIssueInventory());
        $this->assertFalse(UserRole::Viewer->canTransferInventory());
        $this->assertFalse(
            UserRole::Viewer->canProcessInventoryReturns()
        );
        $this->assertFalse(UserRole::Viewer->canAdjustInventory());
        $this->assertFalse(UserRole::Viewer->canCorrectInventory());
        $this->assertFalse(UserRole::Viewer->canRebuildInventory());

        $this->assertTrue(
            UserRole::Operator->canDraftInventoryMovement(
                InventoryMovementType::Receipt
            )
        );
        $this->assertFalse(
            UserRole::Operator->canDraftInventoryMovement(
                InventoryMovementType::PositiveAdjustment
            )
        );
        $this->assertFalse(
            UserRole::Admin->canDraftInventoryMovement(
                InventoryMovementType::Reversal
            )
        );
        $this->assertTrue(
            UserRole::Admin->canConfirmInventoryMovement(
                InventoryMovementType::Reversal
            )
        );
    }

    public function test_creator_assigns_server_fields_and_exact_quantity(): void
    {
        $organization = $this->organization();
        $actor = $this->user($organization, UserRole::Admin);
        $product = $this->product('Aceite a granel', 'CREATE-OIL');
        $product->forceFill([
            'base_unit_code' => 'l',
            'quantity_scale' => 3,
        ])->saveQuietly();
        $location = $this->location($organization);
        $data = new InventoryMovementDraftData(
            type: InventoryMovementType::Receipt,
            effectiveAt: now(),
            reason: '  Apertura de tambor  ',
            idempotencyKey: 'receipt:create-oil:1',
            lines: [
                new InventoryMovementLineData(
                    catalogProductId: $product->id,
                    condition: InventoryCondition::New,
                    enteredQuantity: '1',
                    enteredUnitCode: 'DRUM',
                    conversionFactor: '200',
                    destinationLocationId: $location->id
                ),
            ],
            sourceType: 'manual_receipt',
            sourceId: 'MR-1',
            sourceReference: 'Tambor 200 L',
            metadata: ['z' => 2, 'a' => 1]
        );

        $creator = app(InventoryMovementCreator::class);
        $first = $creator->create($data, $actor);
        $second = $creator->create($data, $actor);
        $line = $first->lines->sole();

        $this->assertSame($first->id, $second->id);
        $this->assertSame($organization->id, $first->organization_id);
        $this->assertSame($actor->id, $first->created_by_user_id);
        $this->assertSame(InventoryMovementStatus::Draft, $first->status);
        $this->assertNull($first->confirmed_by_user_id);
        $this->assertSame('Apertura de tambor', $first->reason);
        $this->assertSame(1, $line->sequence);
        $this->assertSame('drum', $line->entered_unit_code);
        $this->assertSame('1.000000', $line->entered_quantity);
        $this->assertSame('200.00000000', $line->conversion_factor);
        $this->assertSame('200.000000', $line->base_quantity);
        $this->assertSame('l', $line->base_unit_code);
        $this->assertArrayHasKey(
            '_creation_fingerprint',
            $first->metadata
        );
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseCount('inventory_movement_lines', 1);
    }

    public function test_idempotency_conflict_and_tenant_error_roll_back(): void
    {
        $firstOrganization = $this->organization();
        $firstActor = $this->user(
            $firstOrganization,
            UserRole::Admin
        );
        $product = $this->product('Producto seguro', 'CREATE-SAFE');
        $location = $this->location($firstOrganization);
        $creator = app(InventoryMovementCreator::class);
        $original = $this->receiptData(
            $product,
            $location,
            'receipt:create-safe:1'
        );

        $creator->create($original, $firstActor);

        $conflict = new InventoryMovementDraftData(
            type: InventoryMovementType::Receipt,
            effectiveAt: $original->effectiveAt,
            reason: $original->reason,
            idempotencyKey: $original->idempotencyKey,
            lines: [
                new InventoryMovementLineData(
                    catalogProductId: $product->id,
                    condition: InventoryCondition::New,
                    enteredQuantity: '2',
                    enteredUnitCode: 'unit',
                    destinationLocationId: $location->id
                ),
            ]
        );

        $this->assertDomainFailure(
            fn () => $creator->create($conflict, $firstActor)
        );

        $secondOrganization = $this->newOrganization(
            'Organización de creación ajena'
        );
        $secondActor = $this->user(
            $secondOrganization,
            UserRole::Admin
        );
        $foreignLocation = $this->receiptData(
            $product,
            $location,
            'receipt:foreign-location:1'
        );

        $this->assertDomainFailure(
            fn () => $creator->create($foreignLocation, $secondActor)
        );

        $product->forceFill(['active' => false])->saveQuietly();
        $inactiveProduct = $this->receiptData(
            $product,
            $location,
            'receipt:inactive-product:1'
        );

        $this->assertDomainFailure(
            fn () => $creator->create($inactiveProduct, $firstActor)
        );

        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseCount('inventory_movement_lines', 1);
    }

    public function test_roles_are_enforced_when_creating_and_confirming(): void
    {
        $organization = $this->organization();
        $admin = $this->user($organization, UserRole::Admin);
        $operator = $this->user($organization, UserRole::Operator);
        $viewer = $this->user($organization, UserRole::Viewer);
        $product = $this->product('Producto operativo', 'CREATE-ROLE');
        $location = $this->location($organization);
        $creator = app(InventoryMovementCreator::class);
        $confirmer = app(InventoryMovementConfirmer::class);

        $operatorReceipt = $creator->create(
            $this->receiptData(
                $product,
                $location,
                'receipt:operator:1'
            ),
            $operator
        );
        $confirmed = $confirmer->confirm($operatorReceipt, $operator);

        $this->assertSame(
            InventoryMovementStatus::Confirmed,
            $confirmed->status
        );

        $this->assertDomainFailure(
            fn () => $creator->create(
                $this->receiptData(
                    $product,
                    $location,
                    'receipt:viewer:1'
                ),
                $viewer
            )
        );

        $this->assertDomainFailure(
            fn () => $creator->create(
                new InventoryMovementDraftData(
                    type: InventoryMovementType::PositiveAdjustment,
                    effectiveAt: now(),
                    reason: 'Ajuste no autorizado',
                    idempotencyKey: 'adjustment:operator:1',
                    lines: [
                        new InventoryMovementLineData(
                            catalogProductId: $product->id,
                            condition: InventoryCondition::New,
                            enteredQuantity: '1',
                            enteredUnitCode: 'unit',
                            destinationLocationId: $location->id
                        ),
                    ]
                ),
                $operator
            )
        );

        $this->assertDomainFailure(
            fn () => $creator->create(
                new InventoryMovementDraftData(
                    type: InventoryMovementType::Reversal,
                    effectiveAt: now(),
                    reason: 'Reverso por vía general',
                    idempotencyKey: 'reversal:general:1',
                    lines: [
                        new InventoryMovementLineData(
                            catalogProductId: $product->id,
                            condition: InventoryCondition::New,
                            enteredQuantity: '1',
                            enteredUnitCode: 'unit',
                            destinationLocationId: $location->id
                        ),
                    ]
                ),
                $admin
            )
        );

        $adminDraft = $creator->create(
            $this->receiptData(
                $product,
                $location,
                'receipt:admin-draft:1'
            ),
            $admin
        );

        $this->assertDomainFailure(
            fn () => $confirmer->confirm($adminDraft, $viewer)
        );
        $this->assertSame(
            InventoryMovementStatus::Draft,
            $adminDraft->refresh()->status
        );

        $manualAdjustment = InventoryMovement::query()->create([
            'organization_id' => $organization->id,
            'type' => InventoryMovementType::PositiveAdjustment,
            'status' => InventoryMovementStatus::Draft,
            'created_by_user_id' => $admin->id,
            'effective_at' => now(),
            'reason' => 'Defensa al confirmar',
            'idempotency_key' => 'adjustment:defense:1',
        ]);

        $this->assertDomainFailure(
            fn () => $confirmer->confirm($manualAdjustment, $operator)
        );
        $this->assertSame(
            InventoryMovementStatus::Draft,
            $manualAdjustment->refresh()->status
        );
    }

    private function organization(): Organization
    {
        return Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();
    }

    private function newOrganization(string $name): Organization
    {
        return Organization::withoutEvents(
            fn () => Organization::query()->create([
                'name' => $name,
                'slug' => Str::slug($name),
                'active' => true,
            ])
        );
    }

    private function user(
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

    private function location(
        Organization $organization
    ): InventoryLocation {
        return InventoryLocation::query()
            ->where('organization_id', $organization->id)
            ->orderBy('id')
            ->firstOrFail();
    }

    private function product(
        string $name,
        string $sku
    ): CatalogProduct {
        $category = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()->firstOrCreate(
                ['slug' => 'inventory-creation'],
                [
                    'name' => 'Inventory Creation',
                    'active' => true,
                ]
            )
        );

        return CatalogProduct::withoutEvents(
            fn () => CatalogProduct::query()->create([
                'product_category_id' => $category->id,
                'sku' => $sku,
                'name' => $name,
                'active' => true,
            ])->refresh()
        );
    }

    private function receiptData(
        CatalogProduct $product,
        InventoryLocation $location,
        string $idempotencyKey
    ): InventoryMovementDraftData {
        return new InventoryMovementDraftData(
            type: InventoryMovementType::Receipt,
            effectiveAt: now(),
            reason: 'Recepción de prueba',
            idempotencyKey: $idempotencyKey,
            lines: [
                new InventoryMovementLineData(
                    catalogProductId: $product->id,
                    condition: InventoryCondition::New,
                    enteredQuantity: '1',
                    enteredUnitCode: 'unit',
                    destinationLocationId: $location->id
                ),
            ]
        );
    }

    private function assertDomainFailure(callable $callback): void
    {
        try {
            $callback();
        } catch (DomainException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('La operación de inventario debió ser rechazada.');
    }
}
