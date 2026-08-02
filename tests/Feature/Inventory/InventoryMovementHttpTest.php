<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\InventoryMovementCreator;
use App\Domain\Inventory\InventoryMovementDraftData;
use App\Domain\Inventory\InventoryMovementLineData;
use App\Enums\InventoryCondition;
use App\Enums\InventoryLocationType;
use App\Enums\InventoryMovementStatus;
use App\Enums\InventoryMovementType;
use App\Enums\UserRole;
use App\Http\Middleware\RequireOrganization;
use App\Models\CatalogProduct;
use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductCategory;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryMovementHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_active_members_may_view_but_only_operational_roles_may_create(): void
    {
        $organization = $this->organization();
        $admin = $this->user($organization, UserRole::Admin);
        $operator = $this->user($organization, UserRole::Operator);
        $viewer = $this->user($organization, UserRole::Viewer);

        foreach ([$admin, $operator, $viewer] as $user) {
            $this->actingAs($user)
                ->get(route('inventory-movements.index'))
                ->assertOk()
                ->assertSee('Movimientos');
        }

        foreach ([$admin, $operator] as $user) {
            $this->actingAs($user)
                ->get(route('inventory-movements.create'))
                ->assertOk()
                ->assertSee('Nuevo movimiento');
        }

        $this->actingAs($viewer)
            ->get(route('inventory-movements.create'))
            ->assertForbidden();

        $this->assertTrue(
            UserRole::Admin->canDraftAnyInventoryMovement()
        );
        $this->assertTrue(
            UserRole::Operator->canDraftAnyInventoryMovement()
        );
        $this->assertFalse(
            UserRole::Viewer->canDraftAnyInventoryMovement()
        );

        $indexRoute = app('router')->getRoutes()->getByName(
            'inventory-movements.index'
        );
        $storeRoute = app('router')->getRoutes()->getByName(
            'inventory-movements.store'
        );
        $confirmRoute = app('router')->getRoutes()->getByName(
            'inventory-movements.confirm'
        );

        $this->assertSame(['GET', 'HEAD'], $indexRoute->methods());
        $this->assertSame(['POST'], $storeRoute->methods());
        $this->assertSame(['PATCH'], $confirmRoute->methods());
        $this->assertContains(
            RequireOrganization::class,
            $confirmRoute->gatherMiddleware()
        );
        $this->assertContains(
            'can:draft-inventory-movements',
            $storeRoute->gatherMiddleware()
        );
    }

    public function test_operator_creates_idempotent_receipt_with_server_product_unit(): void
    {
        $organization = $this->organization();
        $operator = $this->user($organization, UserRole::Operator);
        $product = $this->product(
            'Aceite operativo web',
            'MOV-HTTP-OIL',
            'l',
            3
        );
        $location = $this->location($organization);
        $payload = $this->payload(
            InventoryMovementType::Receipt,
            $product,
            quantity: '2,500',
            destination: $location,
            reference: '  Remito   42  '
        );

        $this->actingAs($operator)
            ->post(route('inventory-movements.store'), $payload)
            ->assertRedirect(route('inventory-movements.index'))
            ->assertSessionHas('success');

        $movement = InventoryMovement::query()->sole();
        $line = $movement->lines()->sole();

        $this->assertSame(InventoryMovementType::Receipt, $movement->type);
        $this->assertSame(InventoryMovementStatus::Draft, $movement->status);
        $this->assertSame($operator->id, $movement->created_by_user_id);
        $this->assertSame('Carga operativa desde HTTP', $movement->reason);
        $this->assertSame('Remito 42', $movement->source_reference);
        $this->assertSame('l', $line->entered_unit_code);
        $this->assertSame('l', $line->base_unit_code);
        $this->assertSame('2.500000', $line->entered_quantity);
        $this->assertSame('2.500000', $line->base_quantity);
        $this->assertNull($line->source_location_id);
        $this->assertSame($location->id, $line->destination_location_id);

        $this->actingAs($operator)
            ->post(route('inventory-movements.store'), $payload)
            ->assertRedirect(route('inventory-movements.index'));

        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseCount('inventory_movement_lines', 1);
    }

    public function test_transfer_draft_preserves_multiple_lines_and_locations(): void
    {
        $organization = $this->organization();
        $operator = $this->user($organization, UserRole::Operator);
        $source = $this->location($organization);
        $destination = $this->newLocation(
            $organization,
            'Destino operativo HTTP'
        );
        $firstProduct = $this->product(
            'Control remoto operativo',
            'MOV-HTTP-REMOTE'
        );
        $secondProduct = $this->product(
            'Cable operativo',
            'MOV-HTTP-CABLE'
        );
        $payload = $this->payload(
            InventoryMovementType::Transfer,
            $firstProduct,
            quantity: '2',
            source: $source,
            destination: $destination
        );
        $payload['lines'][] = [
            'catalog_product_id' => $secondProduct->id,
            'condition' => InventoryCondition::Used->value,
            'entered_quantity' => '1',
            'source_location_id' => $source->id,
            'destination_location_id' => $destination->id,
            'notes' => 'Segunda línea transferida',
        ];

        $this->actingAs($operator)
            ->post(route('inventory-movements.store'), $payload)
            ->assertRedirect(route('inventory-movements.index'));

        $movement = InventoryMovement::query()->sole();
        $lines = $movement->lines()->get();

        $this->assertSame(InventoryMovementType::Transfer, $movement->type);
        $this->assertCount(2, $lines);
        $this->assertSame([1, 2], $lines->pluck('sequence')->all());
        $this->assertTrue($lines->every(
            fn ($line): bool =>
                (int) $line->source_location_id === (int) $source->id
                && (int) $line->destination_location_id
                    === (int) $destination->id
        ));
    }

    public function test_receipt_confirmation_projects_once_and_is_idempotent(): void
    {
        $organization = $this->organization();
        $operator = $this->user($organization, UserRole::Operator);
        $viewer = $this->user($organization, UserRole::Viewer);
        $product = $this->product(
            'Producto para confirmación web',
            'MOV-HTTP-CONFIRM'
        );
        $location = $this->location($organization);
        $payload = $this->payload(
            InventoryMovementType::Receipt,
            $product,
            quantity: '4',
            destination: $location
        );

        $this->actingAs($operator)
            ->post(route('inventory-movements.store'), $payload);

        $movement = InventoryMovement::query()->sole();

        $this->actingAs($viewer)
            ->patch(route('inventory-movements.confirm', $movement))
            ->assertForbidden();

        $this->actingAs($operator)
            ->patch(route('inventory-movements.confirm', $movement))
            ->assertRedirect()
            ->assertSessionHas(
                'success',
                'Movimiento confirmado y proyectado correctamente.'
            );

        $movement->refresh();
        $this->assertSame(
            InventoryMovementStatus::Confirmed,
            $movement->status
        );
        $this->assertSame($operator->id, $movement->confirmed_by_user_id);
        $this->assertNotNull($movement->confirmed_at);

        $this->actingAs($operator)
            ->patch(route('inventory-movements.confirm', $movement))
            ->assertSessionHas('success');

        $balance = InventoryBalance::query()
            ->where('organization_id', $organization->id)
            ->where('catalog_product_id', $product->id)
            ->where('inventory_location_id', $location->id)
            ->where('condition', InventoryCondition::New->value)
            ->sole();

        $this->assertSame('4.000000', $balance->quantity);
        $this->assertDatabaseCount('inventory_movements', 1);
    }

    public function test_validation_rejects_invalid_location_shapes_and_foreign_data(): void
    {
        $organization = $this->organization();
        $other = $this->newOrganization('Tenant de ubicación ajena');
        $operator = $this->user($organization, UserRole::Operator);
        $product = $this->product(
            'Producto con ubicaciones protegidas',
            'MOV-HTTP-SCOPE'
        );
        $location = $this->location($organization);
        $foreignLocation = $this->newLocation(
            $other,
            'Ubicación ajena HTTP'
        );

        $foreign = $this->payload(
            InventoryMovementType::Receipt,
            $product,
            destination: $foreignLocation
        );
        $this->actingAs($operator)
            ->post(route('inventory-movements.store'), $foreign)
            ->assertSessionHasErrors(
                'lines.0.destination_location_id'
            );

        $forbiddenSource = $this->payload(
            InventoryMovementType::Receipt,
            $product,
            source: $location,
            destination: $location
        );
        $this->actingAs($operator)
            ->post(route('inventory-movements.store'), $forbiddenSource)
            ->assertSessionHasErrors('lines.0.source_location_id');

        $sameLocation = $this->payload(
            InventoryMovementType::Transfer,
            $product,
            source: $location,
            destination: $location
        );
        $this->actingAs($operator)
            ->post(route('inventory-movements.store'), $sameLocation)
            ->assertSessionHasErrors(
                'lines.0.destination_location_id'
            );

        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_domain_permissions_and_route_binding_fail_closed(): void
    {
        $organization = $this->organization();
        $other = $this->newOrganization('Tenant de movimiento ajeno');
        $operator = $this->user($organization, UserRole::Operator);
        $admin = $this->user($organization, UserRole::Admin);
        $foreignAdmin = $this->user($other, UserRole::Admin);
        $product = $this->product(
            'Producto de permisos HTTP',
            'MOV-HTTP-PERMISSION'
        );
        $location = $this->location($organization);
        $adjustment = $this->payload(
            InventoryMovementType::PositiveAdjustment,
            $product,
            destination: $location
        );

        $this->actingAs($operator)
            ->post(route('inventory-movements.store'), $adjustment)
            ->assertRedirect()
            ->assertSessionHas(
                'error',
                'El rol del usuario no puede crear este tipo de movimiento.'
            );

        $this->assertDatabaseCount('inventory_movements', 0);

        $this->actingAs($admin)
            ->post(route('inventory-movements.store'), $adjustment)
            ->assertRedirect(route('inventory-movements.index'));

        $movement = InventoryMovement::query()->sole();

        $this->actingAs($foreignAdmin)
            ->patch(route('inventory-movements.confirm', $movement))
            ->assertNotFound();

        $this->assertSame(
            InventoryMovementStatus::Draft,
            $movement->refresh()->status
        );
    }

    public function test_index_is_scoped_and_searches_product_identity(): void
    {
        $organization = $this->organization();
        $other = $this->newOrganization('Tenant secreto de movimientos');
        $operator = $this->user($organization, UserRole::Operator);
        $viewer = $this->user($organization, UserRole::Viewer);
        $foreignOperator = $this->user($other, UserRole::Operator);
        $visibleProduct = $this->product(
            'Control visible del libro',
            'MOV-HTTP-VISIBLE'
        );
        $secretProduct = $this->product(
            'Producto secreto del libro',
            'MOV-HTTP-SECRET'
        );
        $visibleLocation = $this->location($organization);
        $secretLocation = $this->newLocation(
            $other,
            'Depósito secreto del libro'
        );

        $this->draft(
            $operator,
            $visibleProduct,
            $visibleLocation,
            'inventory-http:visible'
        );
        $this->draft(
            $foreignOperator,
            $secretProduct,
            $secretLocation,
            'inventory-http:secret'
        );

        $this->actingAs($viewer)
            ->get(route('inventory-movements.index', [
                'search' => 'MOV-HTTP-VISIBLE',
                'status' => InventoryMovementStatus::Draft->value,
                'type' => InventoryMovementType::Receipt->value,
            ]))
            ->assertOk()
            ->assertSee('Control visible del libro')
            ->assertSee('MOV-HTTP-VISIBLE')
            ->assertDontSee('Producto secreto del libro')
            ->assertDontSee('MOV-HTTP-SECRET');
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
                ['role' => $role->value, 'active' => true]
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

    private function newLocation(
        Organization $organization,
        string $name
    ): InventoryLocation {
        return InventoryLocation::withoutEvents(
            fn () => InventoryLocation::query()->create([
                'organization_id' => $organization->id,
                'name' => $name,
                'type' => InventoryLocationType::Warehouse,
                'active' => true,
            ])
        );
    }

    private function product(
        string $name,
        string $sku,
        string $unit = 'unit',
        int $scale = 0
    ): CatalogProduct {
        $category = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()->firstOrCreate(
                ['slug' => 'inventory-movement-http'],
                [
                    'name' => 'Inventory Movement HTTP',
                    'active' => true,
                ]
            )
        );

        return CatalogProduct::withoutEvents(
            fn () => CatalogProduct::query()->create([
                'product_category_id' => $category->id,
                'sku' => $sku,
                'name' => $name,
                'base_unit_code' => $unit,
                'quantity_scale' => $scale,
                'active' => true,
            ])->refresh()
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(
        InventoryMovementType $type,
        CatalogProduct $product,
        string $quantity = '1',
        ?InventoryLocation $source = null,
        ?InventoryLocation $destination = null,
        ?string $reference = null
    ): array {
        return [
            'type' => $type->value,
            'effective_at' => now()->format('Y-m-d\TH:i'),
            'reason' => '  Carga   operativa desde HTTP  ',
            'source_reference' => $reference,
            'idempotency_key' => 'inventory-ui:'.Str::uuid(),
            'lines' => [[
                'catalog_product_id' => $product->id,
                'condition' => InventoryCondition::New->value,
                'entered_quantity' => $quantity,
                'source_location_id' => $source?->id,
                'destination_location_id' => $destination?->id,
                'notes' => null,
            ]],
        ];
    }

    private function draft(
        User $actor,
        CatalogProduct $product,
        InventoryLocation $location,
        string $key
    ): InventoryMovement {
        return app(InventoryMovementCreator::class)->create(
            new InventoryMovementDraftData(
                type: InventoryMovementType::Receipt,
                effectiveAt: now(),
                reason: 'Movimiento visible en índice',
                idempotencyKey: $key,
                lines: [new InventoryMovementLineData(
                    catalogProductId: $product->id,
                    condition: InventoryCondition::New,
                    enteredQuantity: '1',
                    enteredUnitCode: $product->base_unit_code,
                    destinationLocationId: $location->id
                )]
            ),
            $actor
        );
    }
}
