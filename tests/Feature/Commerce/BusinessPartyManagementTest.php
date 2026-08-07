<?php

namespace Tests\Feature\Commerce;

use App\Domain\Commerce\BusinessPartyIdentityManager;
use App\Domain\Commerce\CustomerManager;
use App\Domain\Commerce\SupplierManager;
use App\Domain\Service\ServiceOrderIntakeData;
use App\Domain\Service\ServiceOrderIntakeManager;
use App\Enums\InventoryLocationType;
use App\Enums\ServiceAssetType;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\BusinessParty;
use App\Models\Customer;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Supplier;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class BusinessPartyManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_permissions_routes_and_person_scaffold_are_explicit(): void
    {
        $organization = Organization::query()->firstOrFail();
        $admin = $this->user($organization, UserRole::Admin);
        $operator = $this->user($organization, UserRole::Operator);
        $viewer = $this->user($organization, UserRole::Viewer);

        $this->assertTrue(
            Gate::forUser($admin)->allows('view-business-parties')
        );
        $this->assertTrue(
            Gate::forUser($operator)->allows('manage-business-parties')
        );
        $this->assertTrue(
            Gate::forUser($viewer)->allows('view-business-parties')
        );
        $this->assertFalse(
            Gate::forUser($viewer)->allows('manage-business-parties')
        );

        foreach (
            ['index', 'show', 'create', 'store', 'edit', 'update']
            as $name
        ) {
            $this->assertTrue(
                Route::has('business-parties.'.$name)
            );
        }

        $this->assertFalse(
            Route::has('business-parties.destroy')
        );
        $this->assertFalse(Route::has('people.index'));
        $this->assertTrue(Schema::hasTable('people'));
    }

    public function test_operator_creates_normalized_searchable_identity_with_audit_and_navigation(): void
    {
        $organization = Organization::query()->firstOrFail();
        $operator = $this->user(
            $organization,
            UserRole::Operator
        );

        $response = $this->actingAs($operator)
            ->post(route('business-parties.store'), [
                'party_type' => 'person',
                'name' => '  María   López  ',
                'tax_id' => '27-33333333-3',
                'email' => 'MARIA@EXAMPLE.TEST',
                'phone' => '351 555-4000',
                'website' => 'maria.test',
            ])
            ->assertSessionHasNoErrors();

        $party = BusinessParty::query()->sole();

        $response->assertRedirect(
            route('business-parties.show', $party)
        );

        $this->assertSame('María López', $party->name);
        $this->assertSame(
            'marialopez',
            $party->normalized_name
        );
        $this->assertSame(
            '27333333333',
            $party->normalized_tax_id
        );
        $this->assertSame(
            'maria@example.test',
            $party->email
        );
        $this->assertSame(
            'https://maria.test',
            $party->website
        );

        $this->actingAs($operator)
            ->get(route('business-parties.index', [
                'search' => '27333333333',
            ]))
            ->assertOk()
            ->assertSee('María López')
            ->assertSee('Personas e identidades')
            ->assertSee(
                route('business-parties.create'),
                false
            );

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => BusinessParty::class,
            'auditable_id' => $party->id,
            'event' => 'created',
            'user_id' => $operator->id,
        ]);
    }

    public function test_duplicate_identity_evidence_fails_closed(): void
    {
        $organization = Organization::query()->firstOrFail();
        $operator = $this->user(
            $organization,
            UserRole::Operator
        );

        BusinessParty::withoutEvents(
            fn () => BusinessParty::query()->create([
                'organization_id' => $organization->id,
                'party_type' => 'person',
                'name' => 'Persona Base',
                'tax_id' => '20-11111111-1',
                'email' => 'base@example.test',
            ])
        );

        $this->actingAs($operator)
            ->post(route('business-parties.store'), [
                'party_type' => 'person',
                'name' => 'Otra Persona',
                'tax_id' => '20111111111',
                'email' => 'otra@example.test',
            ])
            ->assertSessionHasErrors('name');

        $this->actingAs($operator)
            ->post(route('business-parties.store'), [
                'party_type' => 'person',
                'name' => 'Otra Persona',
                'tax_id' => '20-22222222-2',
                'email' => 'BASE@EXAMPLE.TEST',
            ])
            ->assertSessionHasErrors('name');

        $this->actingAs($operator)
            ->post(route('business-parties.store'), [
                'party_type' => 'person',
                'name' => 'persona-base',
            ])
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('business_parties', 1);
    }

    public function test_customer_and_supplier_managers_share_central_identity_rules(): void
    {
        $organization = Organization::query()->firstOrFail();
        $operator = $this->user(
            $organization,
            UserRole::Operator
        );

        $this->actingAs($operator);

        $supplier = app(SupplierManager::class)->create([
            'party_type' => 'organization',
            'name' => 'Empresa Integral',
            'tax_id' => '30-55555555-5',
            'email' => 'integral@example.test',
            'phone' => null,
            'website' => null,
            'notes' => 'Proveedor primero.',
            'active' => true,
        ]);

        $customer = app(CustomerManager::class)->create([
            'party_type' => 'organization',
            'name' => 'Empresa Integral',
            'tax_id' => '30555555555',
            'email' => 'INTEGRAL@EXAMPLE.TEST',
            'phone' => '351 555-5000',
            'website' => null,
            'notes' => 'También cliente.',
            'active' => true,
        ]);

        $this->assertDatabaseCount('business_parties', 1);
        $this->assertDatabaseCount('suppliers', 1);
        $this->assertDatabaseCount('customers', 1);
        $this->assertSame(
            $supplier->business_party_id,
            $customer->business_party_id
        );
        $this->assertSame(
            '351 555-5000',
            $customer->fresh()->party->phone
        );
    }

    public function test_operator_updates_shared_identity_without_changing_roles(): void
    {
        $organization = Organization::query()->firstOrFail();
        $operator = $this->user(
            $organization,
            UserRole::Operator
        );
        $party = $this->partyWithoutAudit(
            $organization,
            'Identidad Compartida'
        );

        $customer = Customer::withoutEvents(
            fn () => Customer::query()->create([
                'organization_id' => $organization->id,
                'business_party_id' => $party->id,
                'active' => true,
            ])
        );
        $supplier = Supplier::withoutEvents(
            fn () => Supplier::query()->create([
                'organization_id' => $organization->id,
                'business_party_id' => $party->id,
                'active' => false,
            ])
        );

        $this->actingAs($operator)
            ->put(
                route('business-parties.update', $party),
                [
                    'party_type' => 'person',
                    'name' => 'Identidad Actualizada',
                    'tax_id' => '20-99999999-9',
                    'email' => 'actualizada@example.test',
                    'phone' => '351 555-9999',
                    'website' => 'actualizada.test',
                ]
            )
            ->assertRedirect(
                route('business-parties.show', $party)
            );

        $this->assertSame(
            'Identidad Actualizada',
            $customer->fresh()->party->name
        );
        $this->assertSame(
            'Identidad Actualizada',
            $supplier->fresh()->party->name
        );
        $this->assertTrue($customer->fresh()->active);
        $this->assertFalse($supplier->fresh()->active);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => BusinessParty::class,
            'auditable_id' => $party->id,
            'event' => 'updated',
        ]);
    }

    public function test_viewer_is_read_only(): void
    {
        $organization = Organization::query()->firstOrFail();
        $viewer = $this->user(
            $organization,
            UserRole::Viewer
        );
        $party = $this->partyWithoutAudit(
            $organization,
            'Identidad Consulta'
        );

        $this->actingAs($viewer)
            ->get(route('business-parties.index'))
            ->assertOk()
            ->assertSee('Identidad Consulta')
            ->assertDontSee(
                route('business-parties.create'),
                false
            );

        $this->actingAs($viewer)
            ->get(route('business-parties.show', $party))
            ->assertOk()
            ->assertDontSee(
                route('business-parties.edit', $party),
                false
            );

        $this->actingAs($viewer)
            ->get(route('business-parties.create'))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->post(route('business-parties.store'), [
                'party_type' => 'person',
                'name' => 'No permitido',
            ])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->get(route('business-parties.edit', $party))
            ->assertForbidden();
    }

    public function test_tenant_boundary_fails_closed_in_http_and_domain(): void
    {
        $organizationA = Organization::query()->firstOrFail();
        $organizationB = Organization::query()->create([
            'name' => 'Otra Org '.Str::uuid(),
            'slug' => 'otra-'.Str::lower(Str::random(8)),
            'active' => true,
        ]);
        $operator = $this->user(
            $organizationA,
            UserRole::Operator
        );
        $foreign = $this->partyWithoutAudit(
            $organizationB,
            'Identidad Ajena'
        );

        $this->actingAs($operator)
            ->get(route('business-parties.index'))
            ->assertOk()
            ->assertDontSee('Identidad Ajena');

        $this->actingAs($operator)
            ->get(route('business-parties.show', $foreign))
            ->assertNotFound();

        $this->actingAs($operator)
            ->get(route('business-parties.edit', $foreign))
            ->assertNotFound();

        $this->expectException(DomainException::class);

        app(BusinessPartyIdentityManager::class)->update(
            $foreign,
            [
                'party_type' => 'person',
                'name' => 'No debe cambiar',
                'tax_id' => null,
                'email' => null,
                'phone' => null,
                'website' => null,
            ]
        );
    }

    public function test_expedient_exposes_roles_and_real_service_activity(): void
    {
        $organization = Organization::query()->firstOrFail();
        $operator = $this->user(
            $organization,
            UserRole::Operator
        );
        $party = $this->partyWithoutAudit(
            $organization,
            'Identidad Expediente'
        );

        Customer::withoutEvents(
            fn () => Customer::query()->create([
                'organization_id' => $organization->id,
                'business_party_id' => $party->id,
                'active' => true,
            ])
        );
        Supplier::withoutEvents(
            fn () => Supplier::query()->create([
                'organization_id' => $organization->id,
                'business_party_id' => $party->id,
                'active' => true,
            ])
        );

        $location = InventoryLocation::withoutEvents(
            fn () => InventoryLocation::query()->create([
                'organization_id' => $organization->id,
                'name' => 'Recepción Identidad '.Str::uuid(),
                'type' => InventoryLocationType::Receiving,
                'active' => true,
            ])
        );

        $this->actingAs($operator);

        $order = app(ServiceOrderIntakeManager::class)->create(
            new ServiceOrderIntakeData(
                assetType: ServiceAssetType::MobilePhone,
                brandName: 'Marca Identidad',
                modelName: 'Modelo Identidad',
                identifiers: [],
                intakeLocationId: $location->id,
                customerReportedIssue: 'No enciende',
                idempotencyKey: 'business-party-expedient:'.Str::uuid(),
                customerBusinessPartyId: $party->id,
                ownerBusinessPartyId: $party->id,
                contactAvailable: true,
                contactReference: '351 555-6000'
            ),
            $operator
        );

        $this->actingAs($operator)
            ->get(route('business-parties.show', $party))
            ->assertOk()
            ->assertSee('Cliente · activo')
            ->assertSee('Proveedor · activo')
            ->assertSee('Reparaciones como cliente')
            ->assertSee('Órdenes como propietario')
            ->assertSee('Orden #'.$order->order_number)
            ->assertSee(
                route('service-orders.show', $order),
                false
            );
    }

    private function partyWithoutAudit(
        Organization $organization,
        string $name
    ): BusinessParty {
        return BusinessParty::withoutEvents(
            fn () => BusinessParty::query()->create([
                'organization_id' => $organization->id,
                'party_type' => 'person',
                'name' => $name,
                'email' => Str::lower(Str::random(10))
                    .'@identity.test',
            ])
        );
    }

    private function user(
        Organization $organization,
        UserRole $role
    ): User {
        $token = Str::lower(Str::random(14));

        $user = User::factory()->create([
            'name' => $role->label().' '.$token,
            'email' => $token.'@identity.test',
            'password' => Hash::make('password'),
        ]);

        $user->forceFill([
            'role' => $role,
            'current_organization_id' => $organization->id,
            'email_verified_at' => now(),
        ])->saveQuietly();

        OrganizationMembership::query()->updateOrCreate(
            [
                'organization_id' => $organization->id,
                'user_id' => $user->id,
            ],
            [
                'role' => $role->value,
                'active' => true,
            ]
        );

        return $user->refresh();
    }
}
