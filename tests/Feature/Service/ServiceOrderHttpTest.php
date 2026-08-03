<?php

namespace Tests\Feature\Service;

use App\Enums\InventoryLocationType;
use App\Enums\ServiceAssetType;
use App\Enums\ServiceIdentifierType;
use App\Enums\ServiceOrderStatus;
use App\Enums\UserRole;
use App\Http\Middleware\RequireOrganization;
use App\Models\BusinessParty;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ServiceOrder;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ServiceOrderHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_members_may_view_and_only_operational_roles_may_receive(): void
    {
        $organization = $this->organization();
        $admin = $this->user($organization, UserRole::Admin);
        $operator = $this->user($organization, UserRole::Operator);
        $viewer = $this->user($organization, UserRole::Viewer);

        foreach ([$admin, $operator, $viewer] as $user) {
            $this->actingAs($user)
                ->get(route('service-orders.index'))
                ->assertOk()
                ->assertSee('Órdenes de servicio');
        }

        foreach ([$admin, $operator] as $user) {
            $this->actingAs($user)
                ->get(route('service-orders.create'))
                ->assertOk()
                ->assertSee('Recibir un equipo');
        }

        $this->actingAs($viewer)
            ->get(route('service-orders.create'))
            ->assertForbidden();

        $index = app('router')->getRoutes()->getByName(
            'service-orders.index'
        );
        $store = app('router')->getRoutes()->getByName(
            'service-orders.store'
        );
        $show = app('router')->getRoutes()->getByName(
            'service-orders.show'
        );

        $this->assertSame(['GET', 'HEAD'], $index->methods());
        $this->assertSame(['POST'], $store->methods());
        $this->assertSame(['GET', 'HEAD'], $show->methods());
        $this->assertContains(
            RequireOrganization::class,
            $store->gatherMiddleware()
        );
        $this->assertContains(
            'can:create-service-orders',
            $store->gatherMiddleware()
        );
        $this->assertContains(
            'can:view-service-orders',
            $show->gatherMiddleware()
        );
    }

    public function test_operator_receives_equipment_and_opens_immutable_record(): void
    {
        $organization = $this->organization();
        $operator = $this->user($organization, UserRole::Operator);
        $customer = $this->party($organization, 'Cliente Motorola Web');
        $location = $this->location($organization);

        $response = $this->actingAs($operator)->post(
            route('service-orders.store'),
            $this->payload($location, [
                'customer_business_party_id' => $customer->id,
                'customer_name' => null,
                'contact_available' => '1',
                'contact_reference' => '+54 9 3447 123456',
            ])
        );

        $order = ServiceOrder::query()->sole();

        $response
            ->assertRedirect(route('service-orders.show', $order))
            ->assertSessionHas(
                'success',
                'Orden #1 recibida y custodia registrada.'
            );

        $this->assertSame(ServiceOrderStatus::Received, $order->status);
        $this->assertSame($operator->id, $order->created_by_user_id);
        $this->assertSame($customer->id, $order->customer_business_party_id);
        $this->assertSame($location->id, $order->intake_location_id);
        $this->assertSame(
            'service-orders-ui',
            $order->metadata['source']
        );
        $this->assertSame(
            '358123456789012',
            $order->asset->identifiers->sole()->normalized_value
        );
        $this->assertSame(
            'Pantalla rota; declara módulo original.',
            $order->intake->customer_reported_issue
        );
        $this->assertSame(
            'Módulo sin adhesivo; pantalla no original.',
            $order->intake->intake_observations
        );
        $this->assertTrue($order->intake->contact_available);
        $this->assertDatabaseCount('service_order_status_histories', 1);
        $this->assertDatabaseCount('service_custody_events', 1);

        $this->actingAs($operator)
            ->get(route('service-orders.show', $order))
            ->assertOk()
            ->assertSee('Orden #1')
            ->assertSee('Motorola E22i')
            ->assertSee('35 812345 678901 2')
            ->assertSee('Cliente Motorola Web')
            ->assertSee('Fotografía de ingreso')
            ->assertSee('Cadena de custodia');
    }

    public function test_index_finds_order_by_imei_client_order_and_issue(): void
    {
        $organization = $this->organization();
        $operator = $this->user($organization, UserRole::Operator);
        $location = $this->location($organization);

        $this->actingAs($operator)->post(
            route('service-orders.store'),
            $this->payload($location, [
                'customer_name' => 'Alejandra Búsqueda',
            ])
        );

        foreach ([
            '358123456789012',
            '35 812345 678901 2',
            'Alejandra Búsqueda',
            '1',
            'pantalla rota',
            'Motorola',
        ] as $search) {
            $this->actingAs($operator)
                ->get(route('service-orders.index', ['search' => $search]))
                ->assertOk()
                ->assertSee('Orden #1')
                ->assertSee('E22i');
        }

        $this->actingAs($operator)
            ->get(route('service-orders.index', [
                'asset_type' => ServiceAssetType::Notebook->value,
            ]))
            ->assertDontSee('Orden #1');

        $this->actingAs($operator)
            ->get(route('service-orders.index', [
                'asset_type' => ServiceAssetType::MobilePhone->value,
                'status' => ServiceOrderStatus::Received->value,
            ]))
            ->assertSee('Orden #1');
    }

    public function test_index_and_record_are_isolated_between_organizations(): void
    {
        $organization = $this->organization();
        $other = $this->newOrganization('Taller colega privado');
        $operator = $this->user($organization, UserRole::Operator);
        $foreignOperator = $this->user($other, UserRole::Operator);
        $foreignLocation = $this->newLocation($other, 'Recepción externa');

        $this->actingAs($foreignOperator)->post(
            route('service-orders.store'),
            $this->payload($foreignLocation, [
                'customer_name' => 'Cliente secreto del colega',
                'idempotency_key' => 'service-ui:'.Str::uuid(),
                'identifiers' => [[
                    'type' => ServiceIdentifierType::Imei->value,
                    'value' => '359999999999999',
                ]],
            ])
        );

        $foreignOrder = ServiceOrder::query()
            ->forOrganization($other->id)
            ->sole();

        $response = $this->actingAs($operator)
            ->get(route('service-orders.index', [
                'search' => '359999999999999',
            ]));

        $response
            ->assertOk()
            ->assertDontSee('Cliente secreto del colega')
            ->assertSee('Ninguna orden coincide con los filtros')
            ->assertViewHas(
                'orders',
                fn ($orders): bool => $orders->total() === 0
            );

        $this->actingAs($operator)
            ->get(route('service-orders.show', $foreignOrder))
            ->assertNotFound();
    }

    public function test_validation_rejects_bad_imei_foreign_party_and_location(): void
    {
        $organization = $this->organization();
        $other = $this->newOrganization('Organización ajena HTTP');
        $operator = $this->user($organization, UserRole::Operator);
        $location = $this->location($organization);
        $foreignParty = $this->party($other, 'Cliente ajeno');
        $foreignLocation = $this->newLocation($other, 'Recepción ajena');

        $this->actingAs($operator)
            ->post(route('service-orders.store'), $this->payload($location, [
                'identifiers' => [[
                    'type' => ServiceIdentifierType::Imei->value,
                    'value' => '12345',
                ]],
            ]))
            ->assertSessionHasErrors('identifiers.0.value');

        $this->actingAs($operator)
            ->post(route('service-orders.store'), $this->payload($location, [
                'customer_business_party_id' => $foreignParty->id,
                'customer_name' => null,
            ]))
            ->assertSessionHasErrors('customer_business_party_id');

        $this->actingAs($operator)
            ->post(route('service-orders.store'), $this->payload(
                $foreignLocation
            ))
            ->assertSessionHasErrors('intake_location_id');

        $this->assertDatabaseCount('service_orders', 0);
        $this->assertDatabaseCount('service_assets', 0);
    }

    public function test_contact_and_customer_rules_preserve_consistent_intake(): void
    {
        $organization = $this->organization();
        $operator = $this->user($organization, UserRole::Operator);
        $location = $this->location($organization);

        $this->actingAs($operator)
            ->post(route('service-orders.store'), $this->payload($location, [
                'customer_name' => null,
            ]))
            ->assertSessionHasErrors('customer_name');

        $this->actingAs($operator)
            ->post(route('service-orders.store'), $this->payload($location, [
                'contact_available' => '1',
                'contact_reference' => null,
            ]))
            ->assertSessionHasErrors('contact_reference');

        $this->actingAs($operator)
            ->post(route('service-orders.store'), $this->payload($location, [
                'contact_available' => '0',
                'contact_reference' => '3447 000000',
            ]))
            ->assertSessionHasErrors('contact_reference');

        $this->assertDatabaseCount('service_orders', 0);
    }

    /** @return array<string, mixed> */
    private function payload(
        InventoryLocation $location,
        array $overrides = []
    ): array {
        return array_replace([
            'asset_type' => ServiceAssetType::MobilePhone->value,
            'brand_name' => ' Motorola ',
            'model_name' => ' E22i ',
            'color' => 'Negro',
            'identifiers' => [[
                'type' => ServiceIdentifierType::Imei->value,
                'value' => '35 812345 678901 2',
            ]],
            'customer_business_party_id' => null,
            'customer_name' => 'Cliente declarado HTTP',
            'owner_business_party_id' => null,
            'owner_name' => null,
            'intake_location_id' => $location->id,
            'customer_reported_issue' =>
                ' Pantalla rota; declara módulo original. ',
            'intake_observations' =>
                'Módulo sin adhesivo; pantalla no original.',
            'received_accessories' => 'Equipo sin cargador.',
            'contact_available' => '0',
            'contact_reference' => null,
            'promised_at' => null,
            'idempotency_key' => 'service-ui:'.Str::uuid(),
        ], $overrides);
    }

    private function organization(): Organization
    {
        return Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();
    }

    private function newOrganization(string $name): Organization
    {
        return Organization::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'active' => true,
        ]);
    }

    private function user(
        Organization $organization,
        UserRole $role
    ): User {
        $user = User::factory()->create();
        $user->forceFill([
            'role' => $role,
            'current_organization_id' => $organization->id,
        ])->saveQuietly();

        OrganizationMembership::withoutEvents(
            fn () => OrganizationMembership::query()->updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'user_id' => $user->id,
                ],
                [
                    'role' => $role,
                    'active' => true,
                ]
            )
        );

        return $user;
    }

    private function party(
        Organization $organization,
        string $name
    ): BusinessParty {
        return BusinessParty::query()->create([
            'organization_id' => $organization->id,
            'party_type' => BusinessParty::TYPE_PERSON,
            'name' => $name,
        ]);
    }

    private function location(Organization $organization): InventoryLocation
    {
        return InventoryLocation::query()
            ->forOrganization($organization->id)
            ->where(
                'normalized_name',
                InventoryLocation::normalizeName('Recepción')
            )->firstOrFail();
    }

    private function newLocation(
        Organization $organization,
        string $name
    ): InventoryLocation {
        return InventoryLocation::query()->create([
            'organization_id' => $organization->id,
            'parent_id' => null,
            'name' => $name,
            'type' => InventoryLocationType::Receiving,
            'active' => true,
        ]);
    }
}
