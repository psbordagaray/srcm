<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\InventoryMovementConfirmer;
use App\Domain\Inventory\InventoryMovementCreator;
use App\Domain\Inventory\InventoryMovementDraftData;
use App\Domain\Inventory\InventoryMovementLineData;
use App\Enums\InventoryCondition;
use App\Enums\InventoryMovementStatus;
use App\Enums\InventoryMovementType;
use App\Enums\UserRole;
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

class InventoryMovementCorrectionHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_only_active_admin_may_open_correction_form(): void
    {
        $organization = $this->organization();
        $admin = $this->user($organization, UserRole::Admin);
        $operator = $this->user($organization, UserRole::Operator);
        $viewer = $this->user($organization, UserRole::Viewer);
        $product = $this->product();
        $location = $this->location($organization);
        $original = $this->confirm(
            $admin,
            InventoryMovementType::Receipt,
            $product,
            destination: $location,
            quantity: '10'
        );

        $this->actingAs($admin)
            ->get(route(
                'inventory-movements.corrections.create',
                $original
            ))
            ->assertOk()
            ->assertSee('Corregir movimiento confirmado')
            ->assertSee('Original inmutable')
            ->assertSee('MOVEMENT-CORRECTION-HTTP')
            ->assertSee('10');

        foreach ([$operator, $viewer] as $user) {
            $this->actingAs($user)
                ->get(route(
                    'inventory-movements.corrections.create',
                    $original
                ))
                ->assertForbidden();
        }

        $createRoute = app('router')->getRoutes()->getByName(
            'inventory-movements.corrections.create'
        );
        $storeRoute = app('router')->getRoutes()->getByName(
            'inventory-movements.corrections.store'
        );

        $this->assertSame(['GET', 'HEAD'], $createRoute->methods());
        $this->assertSame(['POST'], $storeRoute->methods());
        $this->assertContains(
            'can:correct-inventory',
            $storeRoute->gatherMiddleware()
        );
    }

    public function test_admin_applies_atomic_idempotent_correction(): void
    {
        $organization = $this->organization();
        $admin = $this->user($organization, UserRole::Admin);
        $product = $this->product();
        $location = $this->location($organization);
        $original = $this->confirm(
            $admin,
            InventoryMovementType::Receipt,
            $product,
            destination: $location,
            quantity: '10'
        );
        $payload = $this->payload(
            InventoryMovementType::Receipt,
            $product,
            destination: $location,
            quantity: '8'
        );

        $this->actingAs($admin)
            ->post(route(
                'inventory-movements.corrections.store',
                $original
            ), $payload)
            ->assertRedirect(route('inventory-movements.index', [
                'search' => $original->public_id,
            ]))
            ->assertSessionHas('success');

        $original->refresh()->load(['reversal', 'replacement']);
        $reversal = $original->reversal;
        $replacement = $original->replacement;

        $this->assertNotNull($reversal);
        $this->assertNotNull($replacement);
        $this->assertSame(
            InventoryMovementStatus::Confirmed,
            $reversal->status
        );
        $this->assertSame(
            InventoryMovementStatus::Confirmed,
            $replacement->status
        );
        $this->assertSame(
            InventoryMovementType::Reversal,
            $reversal->type
        );
        $this->assertSame($original->id, $reversal->reverses_movement_id);
        $this->assertSame(
            $original->id,
            $replacement->replaces_movement_id
        );
        $this->assertSame(
            'La recepción correcta era de ocho unidades',
            $replacement->reason
        );
        $this->assertSame(
            '8.000000',
            $this->balance($organization, $product, $location)->quantity
        );
        $this->assertDatabaseCount('inventory_movements', 3);

        $version = $this->balance(
            $organization,
            $product,
            $location
        )->version;

        $this->actingAs($admin)
            ->post(route(
                'inventory-movements.corrections.store',
                $original
            ), $payload)
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseCount('inventory_movements', 3);
        $this->assertSame(
            $version,
            $this->balance(
                $organization,
                $product,
                $location
            )->version
        );

        $this->actingAs($admin)
            ->get(route('inventory-movements.index', [
                'search' => $original->public_id,
            ]))
            ->assertOk()
            ->assertSee('Movimiento corregido')
            ->assertSee('Reverso #')
            ->assertSee('Reemplazo #');
    }

    public function test_invalid_final_balance_rolls_back_http_correction(): void
    {
        $organization = $this->organization();
        $admin = $this->user($organization, UserRole::Admin);
        $product = $this->product();
        $location = $this->location($organization);

        $this->confirm(
            $admin,
            InventoryMovementType::Receipt,
            $product,
            destination: $location,
            quantity: '5'
        );
        $original = $this->confirm(
            $admin,
            InventoryMovementType::Issue,
            $product,
            source: $location,
            quantity: '4'
        );
        $payload = $this->payload(
            InventoryMovementType::Issue,
            $product,
            source: $location,
            quantity: '6'
        );

        $this->actingAs($admin)
            ->from(route(
                'inventory-movements.corrections.create',
                $original
            ))
            ->post(route(
                'inventory-movements.corrections.store',
                $original
            ), $payload)
            ->assertRedirect(route(
                'inventory-movements.corrections.create',
                $original
            ))
            ->assertSessionHas('error');

        $this->assertDatabaseCount('inventory_movements', 2);
        $this->assertFalse(
            InventoryMovement::query()
                ->where('reverses_movement_id', $original->id)
                ->exists()
        );
        $this->assertFalse(
            InventoryMovement::query()
                ->where('replaces_movement_id', $original->id)
                ->exists()
        );
        $this->assertSame(
            '1.000000',
            $this->balance(
                $organization,
                $product,
                $location
            )->quantity
        );
    }

    public function test_corrected_or_invalid_original_cannot_be_reopened(): void
    {
        $organization = $this->organization();
        $admin = $this->user($organization, UserRole::Admin);
        $product = $this->product();
        $location = $this->location($organization);
        $draft = $this->draft(
            $admin,
            InventoryMovementType::Receipt,
            $product,
            destination: $location,
            quantity: '1'
        );

        $this->actingAs($admin)
            ->get(route(
                'inventory-movements.corrections.create',
                $draft
            ))
            ->assertRedirect(route('inventory-movements.index', [
                'search' => $draft->public_id,
            ]))
            ->assertSessionHas('error');

        $original = app(InventoryMovementConfirmer::class)->confirm(
            $draft,
            $admin
        );
        $payload = $this->payload(
            InventoryMovementType::Receipt,
            $product,
            destination: $location,
            quantity: '2'
        );

        $this->actingAs($admin)
            ->post(route(
                'inventory-movements.corrections.store',
                $original
            ), $payload)
            ->assertSessionHas('success');

        $this->actingAs($admin)
            ->get(route(
                'inventory-movements.corrections.create',
                $original
            ))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_correction_route_binding_is_tenant_scoped(): void
    {
        $organization = $this->organization();
        $other = $this->newOrganization('Tenant ajeno de correcciones');
        $admin = $this->user($organization, UserRole::Admin);
        $foreignAdmin = $this->user($other, UserRole::Admin);
        $product = $this->product();
        $location = $this->location($organization);
        $original = $this->confirm(
            $admin,
            InventoryMovementType::Receipt,
            $product,
            destination: $location,
            quantity: '1'
        );

        $this->actingAs($foreignAdmin)
            ->get(route(
                'inventory-movements.corrections.create',
                $original
            ))
            ->assertNotFound();

        $this->actingAs($foreignAdmin)
            ->post(route(
                'inventory-movements.corrections.store',
                $original
            ), $this->payload(
                InventoryMovementType::Receipt,
                $product,
                destination: $location
            ))
            ->assertNotFound();

        $this->assertDatabaseCount('inventory_movements', 1);
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

    private function product(): CatalogProduct
    {
        $category = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()->firstOrCreate(
                ['slug' => 'inventory-correction-http'],
                [
                    'name' => 'Inventory Correction HTTP',
                    'active' => true,
                ]
            )
        );

        return CatalogProduct::withoutEvents(
            fn () => CatalogProduct::query()->create([
                'product_category_id' => $category->id,
                'sku' => 'MOVEMENT-CORRECTION-HTTP',
                'name' => 'Producto corregible por HTTP',
                'base_unit_code' => 'unit',
                'quantity_scale' => 0,
                'active' => true,
            ])->refresh()
        );
    }

    private function confirm(
        User $actor,
        InventoryMovementType $type,
        CatalogProduct $product,
        ?InventoryLocation $source = null,
        ?InventoryLocation $destination = null,
        string $quantity = '1'
    ): InventoryMovement {
        return app(InventoryMovementConfirmer::class)->confirm(
            $this->draft(
                $actor,
                $type,
                $product,
                $source,
                $destination,
                $quantity
            ),
            $actor
        );
    }

    private function draft(
        User $actor,
        InventoryMovementType $type,
        CatalogProduct $product,
        ?InventoryLocation $source = null,
        ?InventoryLocation $destination = null,
        string $quantity = '1'
    ): InventoryMovement {
        return app(InventoryMovementCreator::class)->create(
            new InventoryMovementDraftData(
                type: $type,
                effectiveAt: now(),
                reason: 'Movimiento original para corregir',
                idempotencyKey: 'correction-http:'.Str::uuid(),
                lines: [new InventoryMovementLineData(
                    catalogProductId: $product->id,
                    condition: InventoryCondition::New,
                    enteredQuantity: $quantity,
                    enteredUnitCode: $product->base_unit_code,
                    sourceLocationId: $source?->id,
                    destinationLocationId: $destination?->id
                )]
            ),
            $actor
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(
        InventoryMovementType $type,
        CatalogProduct $product,
        ?InventoryLocation $source = null,
        ?InventoryLocation $destination = null,
        string $quantity = '1'
    ): array {
        return [
            'type' => $type->value,
            'effective_at' => now()->format('Y-m-d\TH:i'),
            'reason' => 'La recepción correcta era de ocho unidades',
            'source_reference' => 'Corrección HTTP',
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

    private function balance(
        Organization $organization,
        CatalogProduct $product,
        InventoryLocation $location
    ): InventoryBalance {
        return InventoryBalance::query()
            ->where('organization_id', $organization->id)
            ->where('catalog_product_id', $product->id)
            ->where('inventory_location_id', $location->id)
            ->where('condition', InventoryCondition::New->value)
            ->sole();
    }
}
