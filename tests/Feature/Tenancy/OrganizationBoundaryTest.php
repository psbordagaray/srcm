<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\UserRole;
use App\Http\Middleware\RequireOrganization;
use App\Models\AuditLog;
use App\Models\BusinessParty;
use App\Models\CatalogProduct;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductCategory;
use App\Models\Supplier;
use App\Models\SupplierOffer;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class OrganizationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_organization_and_membership_are_bootstrapped(): void
    {
        $organization = Organization::query()->sole();

        $this->assertSame('SULU TV', $organization->name);
        $this->assertSame('sulu-tv', $organization->slug);
        $this->assertTrue($organization->active);

        $user = $this->user(UserRole::Admin);

        $this->assertSame(
            $organization->id,
            $user->fresh()->current_organization_id
        );

        $this->assertDatabaseHas(
            'organization_memberships',
            [
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'role' => UserRole::Admin->value,
                'active' => true,
            ]
        );

        $this->assertTrue(
            Gate::forUser($user)
                ->allows('manage-organization')
        );
    }

    public function test_user_force_delete_is_rejected(): void
    {
        $user = $this->user(UserRole::Admin);
        $user->delete();

        $deactivatedUser = User::withTrashed()
            ->findOrFail($user->id);

        $rejected = false;

        try {
            $deactivatedUser->forceDelete();
        } catch (LogicException) {
            $rejected = true;
        }

        $this->assertTrue(
            $rejected,
            'La eliminación física del usuario debió rechazarse.'
        );
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
        ]);
        $this->assertDatabaseHas(
            'organization_memberships',
            [
                'user_id' => $user->id,
                'active' => false,
            ]
        );
    }

    public function test_members_read_and_only_admin_edits_organization(): void
    {
        $admin = $this->user(UserRole::Admin);
        $operator = $this->user(UserRole::Operator);
        $organization = $this->defaultOrganization();

        $this->actingAs($operator)
            ->get(route('organization.show'))
            ->assertOk()
            ->assertSee($organization->name);

        $this->actingAs($operator)
            ->get(route('organization.edit'))
            ->assertForbidden();

        $this->actingAs($admin)
            ->put(route('organization.update'), [
                'name' => 'SULU TV Central',
                'tax_id' => '30-12345678-9',
                'email' => 'ADMIN@SULUTV.COM.AR',
                'phone' => '+54 11 4000-0000',
                'website' => 'sulu.example.test',
            ])
            ->assertRedirect(route('organization.show'));

        $organization->refresh();

        $this->assertSame(
            'SULU TV Central',
            $organization->name
        );
        $this->assertSame(
            '30123456789',
            $organization->normalized_tax_id
        );
        $this->assertSame(
            'admin@sulutv.com.ar',
            $organization->email
        );
        $this->assertSame(
            'https://sulu.example.test',
            $organization->website
        );

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'auditable_type' => Organization::class,
            'auditable_id' => (string) $organization->id,
            'event' => 'updated',
        ]);
    }

    public function test_user_switches_only_to_an_active_membership(): void
    {
        $user = $this->user(UserRole::Admin);
        $default = $this->defaultOrganization();
        $second = $this->organization('Segunda Empresa');

        $this->membership(
            $user,
            $second,
            UserRole::Viewer
        );

        $this->actingAs($user)
            ->post(
                route('organizations.activate', $second)
            )
            ->assertRedirect(route('dashboard'));

        $this->assertSame(
            $second->id,
            $user->fresh()->current_organization_id
        );

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $second->id,
            'user_id' => $user->id,
            'auditable_type' => Organization::class,
            'auditable_id' => (string) $second->id,
            'event' => 'organization_switched',
        ]);

        $this->assertFalse(
            Gate::forUser($user)
                ->allows('manage-commerce')
        );

        $third = $this->organization('Empresa Ajena');

        $this->actingAs($user)
            ->post(
                route('organizations.activate', $third)
            )
            ->assertForbidden();

        $this->assertSame(
            $second->id,
            $user->fresh()->current_organization_id
        );

        $this->assertNotSame($default->id, $second->id);
    }

    public function test_private_commerce_and_audit_are_isolated_by_organization(): void
    {
        $user = $this->user(UserRole::Admin);
        $first = $this->defaultOrganization();
        $second = $this->organization('Sucursal Independiente');

        $this->membership(
            $user,
            $second,
            UserRole::Admin
        );

        $product = $this->product();

        [$firstSupplier, $firstOffer] = $this->commerce(
            $first,
            $product,
            'Proveedor Primera',
            'FIRST-001'
        );

        [$secondSupplier, $secondOffer] = $this->commerce(
            $second,
            $product,
            'Proveedor Segunda',
            'SECOND-001'
        );

        $firstAudit = $this->audit(
            $first,
            '11111111-1111-4111-8111-111111111111'
        );

        $secondAudit = $this->audit(
            $second,
            '22222222-2222-4222-8222-222222222222'
        );

        $this->actingAs($user)
            ->get(route('suppliers.index'))
            ->assertOk()
            ->assertSee($firstSupplier->party->name)
            ->assertDontSee($secondSupplier->party->name);

        $this->actingAs($user)
            ->get(route('supplier-offers.index'))
            ->assertOk()
            ->assertSee($firstOffer->supplier_code)
            ->assertDontSee($secondOffer->supplier_code);

        $this->actingAs($user)
            ->get(route('suppliers.show', $secondSupplier))
            ->assertNotFound();

        $this->actingAs($user)
            ->get(route('suppliers.edit', $secondSupplier))
            ->assertNotFound();

        $this->actingAs($user)
            ->put(route('suppliers.update', $secondSupplier), [
                'party_type' =>
                    BusinessParty::TYPE_ORGANIZATION,
                'name' => 'Intento cruzado',
                'tax_id' => null,
                'email' => null,
                'phone' => null,
                'website' => null,
                'notes' => null,
                'active' => '1',
            ])
            ->assertNotFound();

        $this->actingAs($user)
            ->patch(
                route(
                    'suppliers.toggle-active',
                    $secondSupplier
                )
            )
            ->assertNotFound();

        $this->actingAs($user)
            ->get(route('supplier-offers.show', $secondOffer))
            ->assertNotFound();

        $this->actingAs($user)
            ->get(route('supplier-offers.edit', $secondOffer))
            ->assertNotFound();

        $this->actingAs($user)
            ->put(
                route(
                    'supplier-offers.update',
                    $secondOffer
                ),
                [
                    'supplier_id' => $secondSupplier->id,
                    'catalog_product_id' => $product->id,
                    'supplier_code' => 'CROSS-UPDATE',
                    'published_description' => null,
                    'cost_amount' => null,
                    'currency' => null,
                    'availability_status' => 'available',
                    'source_url' => null,
                    'checked_at' => now()->toDateString(),
                    'commercial_terms' => null,
                    'active' => '1',
                ]
            )
            ->assertNotFound();

        $this->actingAs($user)
            ->patch(
                route(
                    'supplier-offers.toggle-active',
                    $secondOffer
                )
            )
            ->assertNotFound();

        $this->actingAs($user)
            ->post(route('supplier-offers.store'), [
                'supplier_id' => $secondSupplier->id,
                'catalog_product_id' => $product->id,
                'supplier_code' => 'CROSS-CREATE',
                'availability_status' => 'available',
                'checked_at' => now()->toDateString(),
                'active' => '1',
            ])
            ->assertSessionHasErrors('supplier_id');

        $this->assertDatabaseMissing('supplier_offers', [
            'supplier_code' => 'CROSS-CREATE',
        ]);

        $this->actingAs($user)
            ->get(route('audit-logs.index'))
            ->assertOk()
            ->assertSee($firstAudit->request_id)
            ->assertDontSee($secondAudit->request_id);

        $this->actingAs($user)
            ->get(route('audit-logs.show', $secondAudit))
            ->assertNotFound();

        $this->actingAs($user)
            ->post(
                route('organizations.activate', $second)
            )
            ->assertRedirect(route('dashboard'));

        $this->actingAs($user)
            ->get(route('suppliers.index'))
            ->assertOk()
            ->assertSee($secondSupplier->party->name)
            ->assertDontSee($firstSupplier->party->name);
    }

    public function test_same_counterparty_identity_can_exist_in_different_organizations(): void
    {
        $first = $this->defaultOrganization();
        $second = $this->organization('Otro Comercio');

        foreach ([$first, $second] as $organization) {
            BusinessParty::withoutEvents(
                fn () => BusinessParty::query()->create([
                    'organization_id' => $organization->id,
                    'party_type' =>
                        BusinessParty::TYPE_ORGANIZATION,
                    'name' => 'Distribuidor Compartido',
                    'tax_id' => '30-44444444-4',
                    'email' => strtolower(
                        $organization->slug
                    ).'@example.test',
                ])
            );
        }

        $this->assertDatabaseCount(
            'business_parties',
            2
        );

        $this->assertSame(
            2,
            BusinessParty::query()
                ->where(
                    'normalized_tax_id',
                    '30444444444'
                )
                ->count()
        );
    }

    public function test_server_assigns_current_organization_and_ignores_tenant_input(): void
    {
        $admin = $this->user(UserRole::Admin);
        $current = $this->defaultOrganization();
        $other = $this->organization('Destino Malicioso');

        $this->membership(
            $admin,
            $other,
            UserRole::Admin
        );

        $this->actingAs($admin)
            ->post(route('suppliers.store'), [
                'organization_id' => $other->id,
                'party_type' =>
                    BusinessParty::TYPE_ORGANIZATION,
                'name' => 'Proveedor Seguro',
                'tax_id' => '30-55555555-5',
                'email' => 'seguro@example.test',
                'phone' => null,
                'website' => null,
                'notes' => 'Debe pertenecer al tenant activo.',
                'active' => '1',
            ])
            ->assertRedirect();

        $supplier = Supplier::query()
            ->whereHas(
                'party',
                fn ($query) => $query->where(
                    'name',
                    'Proveedor Seguro'
                )
            )
            ->firstOrFail();

        $this->assertSame(
            $current->id,
            $supplier->organization_id
        );
        $this->assertSame(
            $current->id,
            $supplier->party->organization_id
        );

        $product = $this->product();

        $this->actingAs($admin)
            ->post(route('supplier-offers.store'), [
                'organization_id' => $other->id,
                'supplier_id' => $supplier->id,
                'catalog_product_id' => $product->id,
                'supplier_code' => 'SAFE-001',
                'published_description' => null,
                'cost_amount' => '100.00',
                'currency' => 'ARS',
                'availability_status' => 'available',
                'source_url' => null,
                'checked_at' => now()->toDateString(),
                'commercial_terms' => null,
                'active' => '1',
            ])
            ->assertRedirect();

        $offer = SupplierOffer::query()
            ->where('supplier_code', 'SAFE-001')
            ->firstOrFail();

        $this->assertSame(
            $current->id,
            $offer->organization_id
        );
    }

    public function test_database_fails_closed_on_tenant_and_audit_tampering(): void
    {
        $first = $this->defaultOrganization();
        $second = $this->organization('Tenant de ataque');
        $product = $this->product();

        [$supplier] = $this->commerce(
            $first,
            $product,
            'Proveedor protegido',
            'PROTECTED-001'
        );

        $crossParty = BusinessParty::withoutEvents(
            fn () => BusinessParty::query()->create([
                'organization_id' => $first->id,
                'party_type' =>
                    BusinessParty::TYPE_ORGANIZATION,
                'name' => 'Identidad protegida',
                'email' => 'protected@example.test',
            ])
        );

        $this->assertQueryRejected(
            fn () => DB::table('business_parties')->insert([
                'party_type' =>
                    BusinessParty::TYPE_ORGANIZATION,
                'name' => 'Sin tenant',
                'normalized_name' => 'sintenant',
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'BusinessParty debe exigir organization_id.'
        );

        $this->assertQueryRejected(
            fn () => DB::table('suppliers')->insert([
                'organization_id' => $second->id,
                'business_party_id' => $crossParty->id,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'Supplier debe rechazar una identidad de otro tenant.'
        );

        $this->assertQueryRejected(
            fn () => DB::table('supplier_offers')->insert([
                'organization_id' => $second->id,
                'supplier_id' => $supplier->id,
                'catalog_product_id' => $product->id,
                'normalized_supplier_code' => 'ATTACK001',
                'availability_status' => 'available',
                'checked_at' => now()->toDateString(),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'SupplierOffer debe rechazar un proveedor de otro tenant.'
        );

        $audit = $this->audit(
            $first,
            '33333333-3333-4333-8333-333333333333'
        );

        $this->assertQueryRejected(
            fn () => DB::table('audit_logs')
                ->where('id', $audit->id)
                ->update(['event' => 'tampered']),
            'La base debe impedir modificar auditoría.'
        );

        $this->assertQueryRejected(
            fn () => DB::table('audit_logs')
                ->where('id', $audit->id)
                ->delete(),
            'La base debe impedir eliminar auditoría.'
        );
    }

    public function test_shared_catalog_survives_without_private_membership(): void
    {
        $user = $this->user(UserRole::Viewer);

        OrganizationMembership::query()
            ->where('user_id', $user->id)
            ->delete();

        $user->forceFill([
            'current_organization_id' => null,
        ])->saveQuietly();

        app(CurrentOrganization::class)->forget($user);

        $this->actingAs($user)
            ->get(route('products.index'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('suppliers.index'))
            ->assertForbidden();
    }

    public function test_tenant_routes_are_apb_and_no_destroy_exists(): void
    {
        foreach ([
            'dashboard',
            'organization.show',
            'organization.edit',
            'organization.update',
            'organizations.activate',
            'suppliers.index',
            'supplier-offers.index',
            'audit-logs.index',
        ] as $routeName) {
            $route = app('router')
                ->getRoutes()
                ->getByName($routeName);

            $this->assertNotNull($route);
            $this->assertContains(
                RequireOrganization::class,
                $route->gatherMiddleware()
            );
        }

        $catalogRoute = app('router')
            ->getRoutes()
            ->getByName('products.index');

        $this->assertNotNull($catalogRoute);
        $this->assertNotContains(
            RequireOrganization::class,
            $catalogRoute->gatherMiddleware()
        );

        $this->assertFalse(
            Route::has('organization.destroy')
        );
        $this->assertFalse(
            Route::has('organizations.destroy')
        );
    }

    private function defaultOrganization(): Organization
    {
        return Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();
    }

    private function organization(
        string $name
    ): Organization {
        return Organization::withoutEvents(
            fn () => Organization::query()->create([
                'name' => $name,
                'slug' => Str::slug($name),
                'active' => true,
            ])
        );
    }

    private function membership(
        User $user,
        Organization $organization,
        UserRole $role
    ): OrganizationMembership {
        return OrganizationMembership::withoutEvents(
            fn () => OrganizationMembership::query()
                ->updateOrCreate(
                    [
                        'organization_id' =>
                            $organization->id,
                        'user_id' => $user->id,
                    ],
                    [
                        'role' => $role->value,
                        'active' => true,
                    ]
                )
        );
    }

    private function user(UserRole $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }

    private function product(): CatalogProduct
    {
        $category = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()
                ->firstOrCreate(
                    ['slug' => 'tenant-test'],
                    [
                        'name' => 'Tenant Test',
                        'active' => true,
                    ]
                )
        );

        $existing = CatalogProduct::query()
            ->where('normalized_sku', 'tenant001')
            ->first();

        if ($existing) {
            return $existing;
        }

        return CatalogProduct::withoutEvents(
            fn () => CatalogProduct::query()->create([
                'product_category_id' => $category->id,
                'sku' => 'TENANT-001',
                'name' => 'Producto Compartido',
                'active' => true,
            ])
        );
    }

    /**
     * @return array{Supplier, SupplierOffer}
     */
    private function commerce(
        Organization $organization,
        CatalogProduct $product,
        string $supplierName,
        string $supplierCode
    ): array {
        $party = BusinessParty::withoutEvents(
            fn () => BusinessParty::query()->create([
                'organization_id' => $organization->id,
                'party_type' =>
                    BusinessParty::TYPE_ORGANIZATION,
                'name' => $supplierName,
                'email' => Str::slug($supplierName).
                    '@example.test',
            ])
        );

        $supplier = Supplier::withoutEvents(
            fn () => Supplier::query()->create([
                'organization_id' => $organization->id,
                'business_party_id' => $party->id,
                'active' => true,
            ])
        )->load('party');

        $offer = SupplierOffer::withoutEvents(
            fn () => SupplierOffer::query()->create([
                'organization_id' => $organization->id,
                'supplier_id' => $supplier->id,
                'catalog_product_id' => $product->id,
                'supplier_code' => $supplierCode,
                'availability_status' => 'available',
                'checked_at' => now()->toDateString(),
                'active' => true,
            ])
        );

        return [$supplier, $offer];
    }

    private function audit(
        Organization $organization,
        string $requestId
    ): AuditLog {
        return AuditLog::query()->create([
            'organization_id' => $organization->id,
            'request_id' => $requestId,
            'user_id' => null,
            'actor_name' => 'Sistema de tenant',
            'actor_email' => null,
            'actor_role' => UserRole::Admin->value,
            'event' => 'updated',
            'auditable_type' => Supplier::class,
            'auditable_id' => '1',
            'old_values' => ['active' => false],
            'new_values' => ['active' => true],
            'ip_address' => '203.0.113.30',
            'user_agent' => 'SRCM Tenancy Test',
            'route_name' => 'suppliers.update',
            'http_method' => 'PATCH',
            'url_path' => '/suppliers/1',
            'created_at' => now(),
        ]);
    }

    private function assertQueryRejected(
        callable $operation,
        string $message
    ): void {
        $rejected = false;

        try {
            $operation();
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, $message);
    }
}
