<?php

namespace Tests\Feature\Commerce;

use App\Domain\Commerce\SupplierManager;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\BusinessParty;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SupplierManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_users_read_suppliers_and_only_managers_see_actions(): void
    {
        $supplier = $this->supplierWithoutAudit();
        $viewer = $this->user(UserRole::Viewer);
        $admin = $this->user(UserRole::Admin);

        $viewerResponse = $this->actingAs($viewer)
            ->get(route('suppliers.index'))
            ->assertOk()
            ->assertSee($supplier->party->name)
            ->assertSee(route('suppliers.show', $supplier), false)
            ->assertDontSee(route('suppliers.create'), false)
            ->assertDontSee('Inactivar');

        $viewerResponse->assertSee('Consulta');

        $this->actingAs($viewer)
            ->get(route('suppliers.show', $supplier))
            ->assertOk()
            ->assertSee($supplier->party->name)
            ->assertDontSee(route('suppliers.edit', $supplier), false);

        $this->actingAs($admin)
            ->get(route('suppliers.index'))
            ->assertOk()
            ->assertSee(route('suppliers.create'), false)
            ->assertSee('Inactivar');
    }

    public function test_manager_creates_normalized_searchable_supplier_with_audit(): void
    {
        $admin = $this->user(UserRole::Admin);

        $response = $this->actingAs($admin)
            ->post(route('suppliers.store'), [
                'party_type' => 'organization',
                'name' => '  TP   Vision Argentina  ',
                'tax_id' => '30-12345678-9',
                'email' => 'VENTAS@TPVISION.TEST',
                'phone' => '+54 11 5555-5555',
                'website' => 'tpvision.test',
                'notes' => 'Distribuidor de electrónica.',
                'active' => '1',
            ])
            ->assertSessionHasNoErrors();

        $supplier = Supplier::query()
            ->with('party')
            ->sole();

        $response->assertRedirect(
            route('suppliers.show', $supplier)
        );

        $this->assertSame(
            'TP Vision Argentina',
            $supplier->party->name
        );
        $this->assertSame(
            'tpvisionargentina',
            $supplier->party->normalized_name
        );
        $this->assertSame(
            '30123456789',
            $supplier->party->normalized_tax_id
        );
        $this->assertSame(
            'ventas@tpvision.test',
            $supplier->party->email
        );
        $this->assertSame(
            'https://tpvision.test',
            $supplier->party->website
        );
        $this->assertTrue($supplier->active);

        $this->actingAs($admin)
            ->get(route('suppliers.index', [
                'search' => '30123456789',
            ]))
            ->assertOk()
            ->assertSee('TP Vision Argentina');

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => BusinessParty::class,
            'auditable_id' => $supplier->party->id,
            'event' => 'created',
            'user_id' => $admin->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Supplier::class,
            'auditable_id' => $supplier->id,
            'event' => 'created',
            'user_id' => $admin->id,
        ]);
    }

    public function test_existing_commercial_identity_is_adopted_without_duplication(): void
    {
        $admin = $this->user(UserRole::Admin);

        $party = BusinessParty::withoutEvents(
            fn () => BusinessParty::query()->create([
                'party_type' => 'person',
                'name' => 'Ana Pérez',
                'tax_id' => '27-12345678-1',
                'email' => 'ana@example.test',
            ])
        );

        $this->actingAs($admin)
            ->post(route('suppliers.store'), [
                'party_type' => 'person',
                'name' => 'Ana Pérez',
                'tax_id' => '27123456781',
                'email' => 'ANA@EXAMPLE.TEST',
                'phone' => '11 4444-4444',
                'website' => '',
                'notes' => 'También podrá ser cliente.',
                'active' => '1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('business_parties', 1);
        $this->assertDatabaseCount('suppliers', 1);

        $supplier = Supplier::query()
            ->with('party')
            ->sole();

        $this->assertSame($party->id, $supplier->business_party_id);
        $this->assertSame(
            '11 4444-4444',
            $supplier->party->phone
        );

        $this->assertDatabaseMissing('audit_logs', [
            'auditable_type' => BusinessParty::class,
            'auditable_id' => $party->id,
            'event' => 'created',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Supplier::class,
            'auditable_id' => $supplier->id,
            'event' => 'created',
        ]);
    }

    public function test_equivalent_supplier_duplicate_is_rejected_without_new_audit(): void
    {
        $admin = $this->user(UserRole::Admin);

        app(SupplierManager::class)->create([
            'party_type' => 'organization',
            'name' => 'Electrónica del Sur',
            'tax_id' => '30-11111111-1',
            'email' => 'ventas@sur.test',
            'phone' => null,
            'website' => null,
            'notes' => null,
            'active' => true,
        ]);

        $auditCount = AuditLog::query()->count();

        $this->actingAs($admin)
            ->post(route('suppliers.store'), [
                'party_type' => 'organization',
                'name' => 'electronica-del-sur',
                'tax_id' => '30111111111',
                'email' => 'ventas@sur.test',
                'active' => '1',
            ])
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('business_parties', 1);
        $this->assertDatabaseCount('suppliers', 1);
        $this->assertSame(
            $auditCount,
            AuditLog::query()->count()
        );
    }

    public function test_probable_name_duplicate_is_blocked_apb(): void
    {
        $admin = $this->user(UserRole::Admin);

        app(SupplierManager::class)->create([
            'party_type' => 'organization',
            'name' => 'Proveedor Central',
            'tax_id' => null,
            'email' => null,
            'phone' => null,
            'website' => null,
            'notes' => null,
            'active' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('suppliers.store'), [
                'party_type' => 'organization',
                'name' => 'proveedor-central',
                'tax_id' => '',
                'email' => '',
                'active' => '1',
            ])
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('business_parties', 1);
        $this->assertDatabaseCount('suppliers', 1);
    }

    public function test_manager_updates_and_toggles_supplier_with_audit(): void
    {
        $supplier = $this->supplierWithoutAudit();
        $admin = $this->user(UserRole::Admin);

        $this->actingAs($admin)
            ->put(route('suppliers.update', $supplier), [
                'party_type' => 'organization',
                'name' => 'Proveedor Actualizado',
                'tax_id' => '30-99999999-9',
                'email' => 'nuevo@example.test',
                'phone' => '+54 11 4000-0000',
                'website' => 'nuevo.example.test',
                'notes' => 'Condición revisada.',
                'active' => '1',
            ])
            ->assertRedirect(
                route('suppliers.show', $supplier)
            );

        $supplier->refresh()->load('party');

        $this->assertSame(
            'Proveedor Actualizado',
            $supplier->party->name
        );
        $this->assertSame(
            '30999999999',
            $supplier->party->normalized_tax_id
        );
        $this->assertSame(
            'Condición revisada.',
            $supplier->notes
        );

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => BusinessParty::class,
            'auditable_id' => $supplier->party->id,
            'event' => 'updated',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Supplier::class,
            'auditable_id' => $supplier->id,
            'event' => 'updated',
        ]);

        $this->actingAs($admin)
            ->patch(
                route(
                    'suppliers.toggle-active',
                    $supplier
                )
            )
            ->assertRedirect(route('suppliers.index'));

        $this->assertFalse($supplier->fresh()->active);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Supplier::class,
            'auditable_id' => $supplier->id,
            'event' => 'deactivated',
        ]);
    }

    public function test_viewer_cannot_mutate_suppliers(): void
    {
        $supplier = $this->supplierWithoutAudit();
        $viewer = $this->user(UserRole::Viewer);

        $this->actingAs($viewer)
            ->get(route('suppliers.create'))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->post(route('suppliers.store'), [
                'party_type' => 'organization',
                'name' => 'Bloqueado',
                'active' => '1',
            ])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->get(route('suppliers.edit', $supplier))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->put(route('suppliers.update', $supplier), [
                'party_type' => 'organization',
                'name' => 'Alterado',
                'active' => '1',
            ])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->patch(
                route(
                    'suppliers.toggle-active',
                    $supplier
                )
            )
            ->assertForbidden();

        $this->assertSame(
            'Proveedor Inicial',
            $supplier->fresh()->party->name
        );
    }

    public function test_routes_are_apb_and_destroy_is_not_exposed(): void
    {
        $this->assertTrue(Route::has('suppliers.index'));
        $this->assertTrue(Route::has('suppliers.show'));
        $this->assertTrue(Route::has('suppliers.create'));
        $this->assertTrue(Route::has('suppliers.store'));
        $this->assertTrue(Route::has('suppliers.edit'));
        $this->assertTrue(Route::has('suppliers.update'));
        $this->assertTrue(Route::has('suppliers.toggle-active'));
        $this->assertFalse(Route::has('suppliers.destroy'));
    }

    private function supplierWithoutAudit(): Supplier
    {
        $party = BusinessParty::withoutEvents(
            fn () => BusinessParty::query()->create([
                'party_type' => 'organization',
                'name' => 'Proveedor Inicial',
                'tax_id' => '30-22222222-2',
                'email' => 'inicial@example.test',
                'phone' => '11 4000-1111',
                'website' => 'https://example.test',
            ])
        );

        return Supplier::withoutEvents(
            fn () => Supplier::query()->create([
                'business_party_id' => $party->id,
                'notes' => 'Proveedor de prueba.',
                'active' => true,
            ])
        )->load('party');
    }

    private function user(UserRole $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
