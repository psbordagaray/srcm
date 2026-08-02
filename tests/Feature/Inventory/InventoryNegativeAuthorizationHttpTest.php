<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\InventoryMovementCreator;
use App\Domain\Inventory\InventoryMovementDraftData;
use App\Domain\Inventory\InventoryMovementLineData;
use App\Domain\Inventory\InventoryNegativeOverrideIssuer;
use App\Domain\Inventory\InventoryNegativeRequestManager;
use App\Enums\InventoryCondition;
use App\Enums\InventoryMovementStatus;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryNegativeOverrideStatus;
use App\Enums\InventoryNegativeRequestStatus;
use App\Enums\UserRole;
use App\Http\Middleware\RequireOrganization;
use App\Models\CatalogProduct;
use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryNegativeOverride;
use App\Models\InventoryNegativeRequest;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductCategory;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryNegativeAuthorizationHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_operational_roles_may_open_center_but_viewer_may_not(): void
    {
        $organization = $this->organization();
        $admin = $this->user($organization, UserRole::Admin);
        $operator = $this->user($organization, UserRole::Operator);
        $viewer = $this->user($organization, UserRole::Viewer);

        foreach ([$admin, $operator] as $user) {
            $this->actingAs($user)
                ->get(route('inventory-negative-authorizations.index'))
                ->assertOk()
                ->assertSee('Overrides');
        }

        $this->actingAs($viewer)
            ->get(route('inventory-negative-authorizations.index'))
            ->assertForbidden();

        $this->assertTrue(
            UserRole::Admin->canViewInventoryNegativeAuthorizations()
        );
        $this->assertTrue(
            UserRole::Operator->canViewInventoryNegativeAuthorizations()
        );
        $this->assertFalse(
            UserRole::Viewer->canViewInventoryNegativeAuthorizations()
        );

        $routes = app('router')->getRoutes();
        $this->assertSame(
            ['GET', 'HEAD'],
            $routes->getByName(
                'inventory-negative-authorizations.index'
            )->methods()
        );
        $this->assertSame(
            ['POST'],
            $routes->getByName(
                'inventory-negative-authorizations.store'
            )->methods()
        );
        $this->assertSame(
            ['PATCH'],
            $routes->getByName(
                'inventory-negative-authorizations.approve'
            )->methods()
        );
        $this->assertContains(
            RequireOrganization::class,
            $routes->getByName(
                'inventory-negative-authorizations.confirm'
            )->gatherMiddleware()
        );
    }

    public function test_operator_requests_once_after_ordinary_confirmation_fails(): void
    {
        $organization = $this->organization();
        $operator = $this->user($organization, UserRole::Operator);
        $product = $this->product(
            'Control con autorización HTTP',
            'NEG-HTTP-REQUEST'
        );
        $location = $this->location($organization);
        $this->balance($organization, $product, $location, '1');
        $movement = $this->issue(
            $operator,
            $product,
            $location,
            '3',
            'negative-http:request'
        );

        $this->actingAs($operator)
            ->patch(route('inventory-movements.confirm', $movement))
            ->assertSessionHas('error');

        $this->assertSame(
            InventoryMovementStatus::Draft,
            $movement->refresh()->status
        );

        $payload = [
            'reason' => '  Venta   excepcional autorizable por operación  ',
        ];

        $this->actingAs($operator)
            ->post(
                route('inventory-negative-authorizations.store', $movement),
                $payload
            )
            ->assertRedirect(
                route('inventory-negative-authorizations.index')
            )->assertSessionHas('success');

        $request = InventoryNegativeRequest::query()->sole();
        $this->assertSame(
            InventoryNegativeRequestStatus::Pending,
            $request->status
        );
        $this->assertSame(
            'Venta excepcional autorizable por operación',
            $request->reason
        );
        $this->assertSame($operator->id, $request->requested_by_user_id);
        $this->assertSame($movement->id, $request->inventory_movement_id);
        $this->assertSame('2.000000', $request->lines()->sole()->incremental_deficit);

        $this->actingAs($operator)
            ->post(
                route('inventory-negative-authorizations.store', $movement),
                $payload
            )->assertRedirect(
                route('inventory-negative-authorizations.index')
            );

        $this->assertDatabaseCount('inventory_negative_requests', 1);
        $this->assertDatabaseCount('inventory_negative_request_lines', 1);
    }

    public function test_admin_approves_and_only_requester_consumes_override(): void
    {
        $organization = $this->organization();
        $admin = $this->user($organization, UserRole::Admin);
        $operator = $this->user($organization, UserRole::Operator);
        $product = $this->product(
            'Producto de consumo HTTP',
            'NEG-HTTP-CONSUME'
        );
        $location = $this->location($organization);
        $this->balance($organization, $product, $location, '1');
        $movement = $this->issue(
            $operator,
            $product,
            $location,
            '3',
            'negative-http:consume'
        );
        $authorization = $this->request(
            $movement,
            $operator,
            'Venta excepcional aprobable por HTTP'
        );

        $this->actingAs($admin)
            ->patch(route(
                'inventory-negative-authorizations.approve',
                $authorization
            ))
            ->assertRedirect()
            ->assertSessionHas('success');

        $authorization->refresh();
        $override = InventoryNegativeOverride::query()->sole();
        $this->assertSame(
            InventoryNegativeRequestStatus::Approved,
            $authorization->status
        );
        $this->assertSame(
            InventoryNegativeOverrideStatus::Active,
            $override->status
        );
        $this->assertSame($operator->id, $override->authorized_user_id);
        $this->assertSame($admin->id, $override->granted_by_user_id);

        $this->actingAs($operator)
            ->patch(route(
                'inventory-negative-authorizations.confirm',
                [$movement, $override]
            ))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(
            InventoryMovementStatus::Confirmed,
            $movement->refresh()->status
        );
        $this->assertSame(
            InventoryNegativeRequestStatus::Fulfilled,
            $authorization->refresh()->status
        );
        $this->assertSame(
            InventoryNegativeOverrideStatus::Consumed,
            $override->refresh()->status
        );
        $this->assertSame(
            '-2.000000',
            InventoryBalance::query()
                ->where('organization_id', $organization->id)
                ->where('catalog_product_id', $product->id)
                ->where('inventory_location_id', $location->id)
                ->sole()
                ->quantity
        );
        $this->assertDatabaseCount('inventory_negative_incidents', 1);
    }

    public function test_admin_rejects_and_revokes_with_mandatory_reasons(): void
    {
        $organization = $this->organization();
        $admin = $this->user($organization, UserRole::Admin);
        $operator = $this->user($organization, UserRole::Operator);
        $location = $this->location($organization);
        $firstProduct = $this->product(
            'Producto rechazado HTTP',
            'NEG-HTTP-REJECT'
        );
        $this->balance($organization, $firstProduct, $location, '1');
        $rejected = $this->request(
            $this->issue(
                $operator,
                $firstProduct,
                $location,
                '2',
                'negative-http:reject'
            ),
            $operator,
            'Solicitud que será rechazada'
        );

        $this->actingAs($operator)
            ->patch(route(
                'inventory-negative-authorizations.reject',
                $rejected
            ), ['reason' => 'Intento sin facultades'])
            ->assertForbidden();

        $this->actingAs($admin)
            ->patch(route(
                'inventory-negative-authorizations.reject',
                $rejected
            ), ['reason' => '  Riesgo comercial   no justificado  '])
            ->assertSessionHas('success');

        $this->assertSame(
            InventoryNegativeRequestStatus::Rejected,
            $rejected->refresh()->status
        );
        $this->assertSame(
            'Riesgo comercial no justificado',
            $rejected->rejection_reason
        );

        $secondProduct = $this->product(
            'Producto revocado HTTP',
            'NEG-HTTP-REVOKE'
        );
        $this->balance($organization, $secondProduct, $location, '1');
        $revokedRequest = $this->request(
            $this->issue(
                $operator,
                $secondProduct,
                $location,
                '2',
                'negative-http:revoke'
            ),
            $operator,
            'Solicitud que tendrá revocación'
        );
        $override = app(InventoryNegativeOverrideIssuer::class)
            ->issue($revokedRequest, $admin)
            ->override;

        $this->actingAs($admin)
            ->patch(route(
                'inventory-negative-authorizations.revoke',
                $override
            ), ['reason' => '  Cambió la decisión   comercial  '])
            ->assertSessionHas('success');

        $this->assertSame(
            InventoryNegativeOverrideStatus::Revoked,
            $override->refresh()->status
        );
        $this->assertSame(
            InventoryNegativeRequestStatus::Invalidated,
            $revokedRequest->refresh()->status
        );
        $this->assertSame(
            'Cambió la decisión comercial',
            $override->revocation_reason
        );
    }

    public function test_changed_balance_invalidates_approval_through_http(): void
    {
        $organization = $this->organization();
        $admin = $this->user($organization, UserRole::Admin);
        $operator = $this->user($organization, UserRole::Operator);
        $product = $this->product(
            'Producto con snapshot vencido',
            'NEG-HTTP-STALE'
        );
        $location = $this->location($organization);
        $balance = $this->balance(
            $organization,
            $product,
            $location,
            '1'
        );
        $authorization = $this->request(
            $this->issue(
                $operator,
                $product,
                $location,
                '3',
                'negative-http:stale'
            ),
            $operator,
            'Snapshot que cambiará antes de aprobar'
        );

        $balance->forceFill([
            'quantity' => '2',
            'version' => $balance->version + 1,
        ])->save();

        $this->actingAs($admin)
            ->patch(route(
                'inventory-negative-authorizations.approve',
                $authorization
            ))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(
            InventoryNegativeRequestStatus::Invalidated,
            $authorization->refresh()->status
        );
        $this->assertStringContainsString(
            'cambiaron',
            $authorization->invalidation_reason
        );
        $this->assertDatabaseCount('inventory_negative_overrides', 0);
    }

    public function test_visibility_and_route_binding_are_tenant_scoped(): void
    {
        $organization = $this->organization();
        $otherOrganization = $this->newOrganization(
            'Organización ajena de Overrides'
        );
        $admin = $this->user($organization, UserRole::Admin);
        $operator = $this->user($organization, UserRole::Operator);
        $otherOperator = $this->user(
            $organization,
            UserRole::Operator
        );
        $foreignAdmin = $this->user(
            $otherOrganization,
            UserRole::Admin
        );
        $product = $this->product(
            'Producto privado de Override',
            'NEG-HTTP-PRIVATE'
        );
        $location = $this->location($organization);
        $this->balance($organization, $product, $location, '1');
        $movement = $this->issue(
            $operator,
            $product,
            $location,
            '3',
            'negative-http:private'
        );
        $authorization = $this->request(
            $movement,
            $operator,
            'Solicitud privada del primer operador'
        );

        $this->actingAs($otherOperator)
            ->get(route('inventory-negative-authorizations.index'))
            ->assertOk()
            ->assertDontSee('Solicitud privada del primer operador');

        $this->actingAs($admin)
            ->get(route('inventory-negative-authorizations.index'))
            ->assertOk()
            ->assertSee('Solicitud privada del primer operador')
            ->assertSee('Aprobar y emitir Override');

        $this->actingAs($foreignAdmin)
            ->patch(route(
                'inventory-negative-authorizations.approve',
                $authorization
            ))
            ->assertNotFound();

        $override = app(InventoryNegativeOverrideIssuer::class)
            ->issue($authorization, $admin)
            ->override;

        $this->actingAs($otherOperator)
            ->patch(route(
                'inventory-negative-authorizations.confirm',
                [$movement, $override]
            ))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(
            InventoryMovementStatus::Draft,
            $movement->refresh()->status
        );
        $this->assertSame(
            InventoryNegativeOverrideStatus::Active,
            $override->refresh()->status
        );
    }

    public function test_reasons_are_validated_before_domain_operations(): void
    {
        $organization = $this->organization();
        $admin = $this->user($organization, UserRole::Admin);
        $operator = $this->user($organization, UserRole::Operator);
        $product = $this->product(
            'Producto con motivo obligatorio',
            'NEG-HTTP-REASON'
        );
        $location = $this->location($organization);
        $this->balance($organization, $product, $location, '1');
        $movement = $this->issue(
            $operator,
            $product,
            $location,
            '2',
            'negative-http:reason'
        );

        $this->actingAs($operator)
            ->from(route('inventory-movements.index'))
            ->post(
                route('inventory-negative-authorizations.store', $movement),
                ['reason' => 'breve']
            )
            ->assertRedirect(route('inventory-movements.index'))
            ->assertSessionHasErrors('reason');

        $this->assertDatabaseCount('inventory_negative_requests', 0);

        $authorization = $this->request(
            $movement,
            $operator,
            'Motivo suficientemente detallado'
        );

        $this->actingAs($admin)
            ->from(route('inventory-negative-authorizations.index'))
            ->patch(route(
                'inventory-negative-authorizations.reject',
                $authorization
            ), ['reason' => 'breve'])
            ->assertRedirect(
                route('inventory-negative-authorizations.index')
            )->assertSessionHasErrors('reason');

        $this->assertSame(
            InventoryNegativeRequestStatus::Pending,
            $authorization->refresh()->status
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

    private function product(
        string $name,
        string $sku
    ): CatalogProduct {
        $category = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()->firstOrCreate(
                ['slug' => 'negative-authorization-http'],
                [
                    'name' => 'Negative Authorization HTTP',
                    'active' => true,
                ]
            )
        );

        return CatalogProduct::withoutEvents(
            fn () => CatalogProduct::query()->create([
                'product_category_id' => $category->id,
                'sku' => $sku,
                'name' => $name,
                'base_unit_code' => 'unit',
                'quantity_scale' => 0,
                'active' => true,
            ])->refresh()
        );
    }

    private function balance(
        Organization $organization,
        CatalogProduct $product,
        InventoryLocation $location,
        string $quantity
    ): InventoryBalance {
        return InventoryBalance::query()->create([
            'organization_id' => $organization->id,
            'catalog_product_id' => $product->id,
            'inventory_location_id' => $location->id,
            'condition' => InventoryCondition::New,
            'quantity' => $quantity,
            'base_unit_code' => $product->base_unit_code,
            'version' => 1,
        ]);
    }

    private function issue(
        User $actor,
        CatalogProduct $product,
        InventoryLocation $source,
        string $quantity,
        string $idempotencyKey
    ): InventoryMovement {
        return app(InventoryMovementCreator::class)->create(
            new InventoryMovementDraftData(
                type: InventoryMovementType::Issue,
                effectiveAt: now(),
                reason: 'Salida excepcional desde HTTP',
                idempotencyKey: $idempotencyKey,
                lines: [new InventoryMovementLineData(
                    catalogProductId: $product->id,
                    condition: InventoryCondition::New,
                    enteredQuantity: $quantity,
                    enteredUnitCode: $product->base_unit_code,
                    sourceLocationId: $source->id
                )]
            ),
            $actor
        );
    }

    private function request(
        InventoryMovement $movement,
        User $actor,
        string $reason
    ): InventoryNegativeRequest {
        return app(InventoryNegativeRequestManager::class)->request(
            $movement,
            $reason,
            $actor
        );
    }
}
