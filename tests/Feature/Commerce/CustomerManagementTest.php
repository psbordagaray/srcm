<?php

namespace Tests\Feature\Commerce;

use App\Domain\Commerce\CustomerManager;
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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_permissions_routes_and_physical_delete_are_explicit(): void
    {
        $organization = Organization::query()->firstOrFail();
        $admin = $this->user($organization, UserRole::Admin);
        $operator = $this->user($organization, UserRole::Operator);
        $viewer = $this->user($organization, UserRole::Viewer);

        $this->assertTrue(Schema::hasColumns('customers', [
            'organization_id','business_party_id','notes','active',
        ]));
        $this->assertTrue(Gate::forUser($admin)->allows('view-customers'));
        $this->assertTrue(Gate::forUser($operator)->allows('manage-customers'));
        $this->assertTrue(Gate::forUser($viewer)->allows('view-customers'));
        $this->assertFalse(Gate::forUser($viewer)->allows('manage-customers'));
        foreach (['index','show','create','store','edit','update','toggle-active'] as $name) {
            $this->assertTrue(Route::has('customers.'.$name));
        }
        $this->assertFalse(Route::has('customers.destroy'));

        $customer = $this->customerWithoutAudit($organization,'No borrar');
        $this->expectException(DomainException::class);
        $customer->delete();
    }

    public function test_operator_creates_normalized_searchable_customer_with_audit_and_navigation(): void
    {
        $organization = Organization::query()->firstOrFail();
        $operator = $this->user($organization, UserRole::Operator);

        $response = $this->actingAs($operator)->post(route('customers.store'), [
            'party_type'=>'person','name'=>'  Ana   Pérez  ','tax_id'=>'27-12345678-1',
            'email'=>'ANA@EXAMPLE.TEST','phone'=>'351 555-1000','website'=>'ana.test',
            'notes'=>'Cliente de mostrador.','active'=>'1',
        ])->assertSessionHasNoErrors();

        $customer = Customer::query()->with('party')->sole();
        $response->assertRedirect(route('customers.show',$customer));
        $this->assertSame('Ana Pérez',$customer->party->name);
        $this->assertSame('anaperez',$customer->party->normalized_name);
        $this->assertSame('27123456781',$customer->party->normalized_tax_id);
        $this->assertSame('ana@example.test',$customer->party->email);
        $this->assertSame('https://ana.test',$customer->party->website);

        $this->actingAs($operator)->get(route('customers.index',['search'=>'27123456781']))
            ->assertOk()->assertSee('Ana Pérez')->assertSee(route('customers.create'),false);

        $this->assertDatabaseHas('audit_logs',['auditable_type'=>BusinessParty::class,'auditable_id'=>$customer->party->id,'event'=>'created','user_id'=>$operator->id]);
        $this->assertDatabaseHas('audit_logs',['auditable_type'=>Customer::class,'auditable_id'=>$customer->id,'event'=>'created','user_id'=>$operator->id]);
    }

    public function test_existing_supplier_identity_is_adopted_as_customer_without_duplication(): void
    {
        $organization = Organization::query()->firstOrFail();
        $operator = $this->user($organization, UserRole::Operator);
        $party = BusinessParty::withoutEvents(fn () => BusinessParty::query()->create([
            'organization_id'=>$organization->id,'party_type'=>'organization','name'=>'Empresa Dual',
            'tax_id'=>'30-11111111-1','email'=>'dual@example.test',
        ]));
        $supplier = Supplier::withoutEvents(fn () => Supplier::query()->create([
            'organization_id'=>$organization->id,'business_party_id'=>$party->id,'active'=>true,
        ]));

        $this->actingAs($operator)->post(route('customers.store'), [
            'party_type'=>'organization','name'=>'Empresa Dual','tax_id'=>'30111111111',
            'email'=>'DUAL@EXAMPLE.TEST','phone'=>'351 444-2000','active'=>'1',
        ])->assertSessionHasNoErrors();

        $customer = Customer::query()->sole();
        $this->assertSame($party->id,$customer->business_party_id);
        $this->assertSame($party->id,$supplier->fresh()->business_party_id);
        $this->assertDatabaseCount('business_parties',1);
        $this->actingAs($operator)->get(route('customers.show',$customer))
            ->assertOk()->assertSee('También proveedor')->assertSee('351 444-2000');
    }

    public function test_conflicting_identity_and_probable_name_duplicate_fail_closed(): void
    {
        $organization = Organization::query()->firstOrFail();
        $operator = $this->user($organization, UserRole::Operator);
        BusinessParty::withoutEvents(fn () => BusinessParty::query()->create([
            'organization_id'=>$organization->id,'party_type'=>'person','name'=>'Persona Uno',
            'tax_id'=>'20-11111111-1','email'=>'uno@example.test',
        ]));
        BusinessParty::withoutEvents(fn () => BusinessParty::query()->create([
            'organization_id'=>$organization->id,'party_type'=>'person','name'=>'Persona Dos',
            'tax_id'=>'20-22222222-2','email'=>'dos@example.test',
        ]));

        $this->actingAs($operator)->post(route('customers.store'), [
            'party_type'=>'person','name'=>'Persona Uno','tax_id'=>'20111111111',
            'email'=>'dos@example.test','active'=>'1',
        ])->assertSessionHasErrors('name');
        $this->assertDatabaseCount('customers',0);

        BusinessParty::withoutEvents(fn () => BusinessParty::query()->create([
            'organization_id'=>$organization->id,'party_type'=>'organization','name'=>'Cliente Central',
        ]));
        $this->actingAs($operator)->post(route('customers.store'), [
            'party_type'=>'organization','name'=>'cliente-central','active'=>'1',
        ])->assertSessionHasErrors('name');
        $this->assertDatabaseCount('customers',0);
    }

    public function test_operator_updates_shared_identity_and_toggles_only_customer_role(): void
    {
        $organization = Organization::query()->firstOrFail();
        $operator = $this->user($organization, UserRole::Operator);
        $customer = $this->customerWithoutAudit($organization,'Identidad Compartida');
        $supplier = Supplier::withoutEvents(fn () => Supplier::query()->create([
            'organization_id'=>$organization->id,'business_party_id'=>$customer->business_party_id,'active'=>true,
        ]));

        $this->actingAs($operator)->put(route('customers.update',$customer), [
            'party_type'=>'person','name'=>'Identidad Actualizada','tax_id'=>'20-99999999-9',
            'email'=>'actualizada@example.test','phone'=>'351 555-9999','notes'=>'Preferente','active'=>'1',
        ])->assertRedirect(route('customers.show',$customer));

        $customer->refresh()->load('party');
        $this->assertSame('Identidad Actualizada',$customer->party->name);
        $this->assertSame('Identidad Actualizada',$supplier->fresh()->party->name);
        $this->actingAs($operator)->patch(route('customers.toggle-active',$customer))
            ->assertRedirect(route('customers.index'));
        $this->assertFalse($customer->fresh()->active);
        $this->assertTrue($supplier->fresh()->active);
        $this->assertDatabaseHas('audit_logs',['auditable_type'=>Customer::class,'auditable_id'=>$customer->id,'event'=>'deactivated']);
    }

    public function test_viewer_is_read_only(): void
    {
        $organization = Organization::query()->firstOrFail();
        $viewer = $this->user($organization, UserRole::Viewer);
        $customer = $this->customerWithoutAudit($organization,'Cliente Consulta');
        $this->actingAs($viewer)->get(route('customers.index'))->assertOk()->assertSee('Cliente Consulta')->assertDontSee(route('customers.create'),false);
        $this->actingAs($viewer)->get(route('customers.show',$customer))->assertOk()->assertDontSee(route('customers.edit',$customer),false);
        $this->actingAs($viewer)->get(route('customers.create'))->assertForbidden();
        $this->actingAs($viewer)->post(route('customers.store'),['party_type'=>'person','name'=>'No','active'=>'1'])->assertForbidden();
        $this->actingAs($viewer)->get(route('customers.edit',$customer))->assertForbidden();
        $this->actingAs($viewer)->patch(route('customers.toggle-active',$customer))->assertForbidden();
    }

    public function test_tenant_boundary_fails_closed_in_http_domain_and_database(): void
    {
        $organizationA = Organization::query()->firstOrFail();
        $organizationB = Organization::query()->create(['name'=>'Otra Org '.Str::uuid(),'slug'=>'otra-'.Str::lower(Str::random(8)),'active'=>true]);
        $operator = $this->user($organizationA, UserRole::Operator);
        $foreign = $this->customerWithoutAudit($organizationB,'Cliente Ajeno');

        $this->actingAs($operator)->get(route('customers.index'))->assertOk()->assertDontSee('Cliente Ajeno');
        $this->actingAs($operator)->get(route('customers.show',$foreign))->assertNotFound();
        $this->actingAs($operator)->get(route('customers.edit',$foreign))->assertNotFound();

        $this->expectException(QueryException::class);
        Customer::withoutEvents(fn () => Customer::query()->create([
            'organization_id'=>$organizationA->id,
            'business_party_id'=>$foreign->business_party_id,
            'active'=>true,
        ]));
    }

    public function test_expedient_exposes_real_service_activity_and_identity_role_counts(): void
    {
        $organization = Organization::query()->firstOrFail();
        $operator = $this->user($organization, UserRole::Operator);
        $customer = $this->customerWithoutAudit($organization,'Cliente Expediente');
        $location = InventoryLocation::withoutEvents(fn () => InventoryLocation::query()->create([
            'organization_id'=>$organization->id,'name'=>'Recepción Cliente '.Str::uuid(),
            'type'=>InventoryLocationType::Receiving,'active'=>true,
        ]));

        $this->actingAs($operator);
        $order = app(ServiceOrderIntakeManager::class)->create(new ServiceOrderIntakeData(
            assetType: ServiceAssetType::MobilePhone,
            brandName: 'Marca Test',
            modelName: 'Modelo Test',
            identifiers: [],
            intakeLocationId: $location->id,
            customerReportedIssue: 'No enciende',
            idempotencyKey: 'customer-expedient:'.Str::uuid(),
            customerBusinessPartyId: $customer->business_party_id,
            ownerBusinessPartyId: $customer->business_party_id,
            contactAvailable: true,
            contactReference: '351 555-3000'
        ), $operator);

        $this->actingAs($operator)->get(route('customers.show',$customer))
            ->assertOk()->assertSee('Reparaciones vinculadas')
            ->assertSee('Equipos / órdenes como propietario')
            ->assertSee('Orden #'.$order->order_number)
            ->assertSee(route('service-orders.show',$order),false);
    }

    private function customerWithoutAudit(Organization $organization, string $name): Customer
    {
        $party = BusinessParty::withoutEvents(fn () => BusinessParty::query()->create([
            'organization_id'=>$organization->id,'party_type'=>'person','name'=>$name,
            'email'=>Str::lower(Str::random(10)).'@customer.test',
        ]));
        return Customer::withoutEvents(fn () => Customer::query()->create([
            'organization_id'=>$organization->id,'business_party_id'=>$party->id,'active'=>true,
        ]))->load('party');
    }

    private function user(Organization $organization, UserRole $role): User
    {
        $token = Str::lower(Str::random(14));
        $user = User::factory()->create(['name'=>$role->label().' '.$token,'email'=>$token.'@customer.test','password'=>Hash::make('password')]);
        $user->forceFill(['role'=>$role,'current_organization_id'=>$organization->id,'email_verified_at'=>now()])->saveQuietly();
        OrganizationMembership::query()->updateOrCreate(
            ['organization_id'=>$organization->id,'user_id'=>$user->id],
            ['role'=>$role->value,'active'=>true]
        );
        return $user->refresh();
    }
}
