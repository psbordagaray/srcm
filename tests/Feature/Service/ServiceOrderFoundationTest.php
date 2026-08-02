<?php

namespace Tests\Feature\Service;

use App\Domain\Service\ServiceAssetIdentifierData;
use App\Domain\Service\ServiceOrderIntakeData;
use App\Domain\Service\ServiceOrderIntakeManager;
use App\Enums\InventoryLocationType;
use App\Enums\ServiceAssetType;
use App\Enums\ServiceCustodyEventType;
use App\Enums\ServiceIdentifierType;
use App\Enums\ServiceOrderStatus;
use App\Enums\UserRole;
use App\Models\BusinessParty;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ServiceAsset;
use App\Models\ServiceOrder;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ServiceOrderFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_schema_and_service_role_matrix_are_explicit(): void
    {
        $this->assertTrue(Schema::hasColumns('service_assets', [
            'organization_id',
            'public_id',
            'asset_type',
            'brand_name',
            'model_name',
        ]));
        $this->assertTrue(Schema::hasColumns(
            'service_asset_identifiers',
            [
                'organization_id',
                'service_asset_id',
                'identifier_type',
                'value',
                'normalized_value',
            ]
        ));
        $this->assertTrue(Schema::hasColumns('service_orders', [
            'organization_id',
            'public_id',
            'order_number',
            'service_asset_id',
            'customer_business_party_id',
            'owner_business_party_id',
            'intake_location_id',
            'status',
            'idempotency_key',
        ]));
        $this->assertTrue(Schema::hasTable('service_order_intakes'));
        $this->assertTrue(
            Schema::hasTable('service_order_status_histories')
        );
        $this->assertTrue(Schema::hasTable('service_custody_events'));

        $this->assertTrue(UserRole::Admin->canViewServiceOrders());
        $this->assertTrue(UserRole::Operator->canViewServiceOrders());
        $this->assertTrue(UserRole::Viewer->canViewServiceOrders());
        $this->assertTrue(UserRole::Admin->canCreateServiceOrders());
        $this->assertTrue(UserRole::Operator->canCreateServiceOrders());
        $this->assertFalse(UserRole::Viewer->canCreateServiceOrders());
        $this->assertTrue(UserRole::Admin->canCancelServiceOrders());
        $this->assertFalse(UserRole::Operator->canCancelServiceOrders());
    }

    public function test_intake_is_atomic_and_preserves_declared_and_observed_facts(): void
    {
        $organization = $this->organization();
        $actor = $this->user($organization, UserRole::Operator);
        $customer = $this->party($organization, 'Cliente Motorola');
        $location = $this->location($organization);

        $order = app(ServiceOrderIntakeManager::class)->create(
            new ServiceOrderIntakeData(
                assetType: ServiceAssetType::MobilePhone,
                brandName: '  Motorola ',
                modelName: ' E22i ',
                identifiers: [
                    new ServiceAssetIdentifierData(
                        ServiceIdentifierType::Imei,
                        '35 812345 678901 2'
                    ),
                ],
                intakeLocationId: $location->id,
                customerReportedIssue: ' Pantalla rota; declara módulo original. ',
                idempotencyKey: 'service:intake:moto-e22i:1',
                customerBusinessPartyId: $customer->id,
                color: 'Negro',
                intakeObservations: "TalkBack activo.\nMódulo sin adhesivo y no original.",
                receivedAccessories: 'Equipo sin cargador.',
                contactAvailable: false,
                metadata: ['source' => 'counter']
            ),
            $actor
        );

        $this->assertSame(1, (int) $order->order_number);
        $this->assertSame(ServiceOrderStatus::Received, $order->status);
        $this->assertSame($actor->id, $order->created_by_user_id);
        $this->assertSame($customer->id, $order->customer_business_party_id);
        $this->assertNull($order->owner_business_party_id);
        $this->assertSame($location->id, $order->intake_location_id);
        $this->assertNotNull($order->received_at);
        $this->assertArrayHasKey('_intake_fingerprint', $order->metadata);

        $asset = $order->asset;
        $identifier = $asset->identifiers->sole();
        $intake = $order->intake;
        $history = $order->statusHistory->sole();
        $custody = $order->custodyEvents->sole();

        $this->assertSame(ServiceAssetType::MobilePhone, $asset->asset_type);
        $this->assertSame('Motorola', $asset->brand_name);
        $this->assertSame('E22i', $asset->model_name);
        $this->assertSame('358123456789012', $identifier->normalized_value);
        $this->assertSame(
            'Pantalla rota; declara módulo original.',
            $intake->customer_reported_issue
        );
        $this->assertStringContainsString(
            'Módulo sin adhesivo y no original.',
            $intake->intake_observations
        );
        $this->assertFalse($intake->contact_available);
        $this->assertNull($intake->contact_reference);
        $this->assertSame('Cliente Motorola', $intake->owner_name_snapshot);
        $this->assertNull($history->from_status);
        $this->assertSame(ServiceOrderStatus::Received, $history->to_status);
        $this->assertSame(
            ServiceCustodyEventType::Received,
            $custody->event_type
        );
        $this->assertSame('Cliente Motorola', $custody->from_holder_name);
        $this->assertSame('SULU TV', $custody->to_holder_name);
        $this->assertSame($location->id, $custody->location_id);

        $this->assertDatabaseCount('service_assets', 1);
        $this->assertDatabaseCount('service_asset_identifiers', 1);
        $this->assertDatabaseCount('service_orders', 1);
        $this->assertDatabaseCount('service_order_intakes', 1);
        $this->assertDatabaseCount('service_order_status_histories', 1);
        $this->assertDatabaseCount('service_custody_events', 1);
    }

    public function test_idempotency_repeats_same_intake_and_rejects_conflict(): void
    {
        $organization = $this->organization();
        $actor = $this->user($organization, UserRole::Admin);
        $customer = $this->party($organization, 'Cliente idempotente');
        $location = $this->location($organization);
        $manager = app(ServiceOrderIntakeManager::class);
        $data = $this->data(
            location: $location,
            customer: $customer,
            key: 'service:intake:idempotent:1'
        );

        $first = $manager->create($data, $actor);
        $second = $manager->create($data, $actor);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('service_orders', 1);
        $this->assertDatabaseCount('service_assets', 1);

        $conflict = new ServiceOrderIntakeData(
            assetType: ServiceAssetType::Notebook,
            brandName: 'Lenovo',
            modelName: 'IdeaPad 3',
            identifiers: [
                new ServiceAssetIdentifierData(
                    ServiceIdentifierType::SerialNumber,
                    'LENOVO-IDEMP-1'
                ),
            ],
            intakeLocationId: $location->id,
            customerReportedIssue: 'Ahora declara una falla diferente.',
            idempotencyKey: 'service:intake:idempotent:1',
            customerBusinessPartyId: $customer->id
        );

        $this->assertDomainFailure(
            fn () => $manager->create($conflict, $actor)
        );
        $this->assertDatabaseCount('service_orders', 1);
    }

    public function test_identifier_reuses_asset_and_numbers_orders_per_organization(): void
    {
        $organization = $this->organization();
        $actor = $this->user($organization, UserRole::Admin);
        $firstCustomer = $this->party($organization, 'Primer cliente');
        $secondCustomer = $this->party($organization, 'Segundo cliente');
        $location = $this->location($organization);
        $manager = app(ServiceOrderIntakeManager::class);

        $first = $manager->create(
            new ServiceOrderIntakeData(
                assetType: ServiceAssetType::Notebook,
                brandName: 'Lenovo',
                modelName: 'IdeaPad 3',
                identifiers: [
                    new ServiceAssetIdentifierData(
                        ServiceIdentifierType::SerialNumber,
                        'PF-123 456'
                    ),
                ],
                intakeLocationId: $location->id,
                customerReportedIssue: 'Funciona lentamente.',
                idempotencyKey: 'service:intake:lenovo:1',
                customerBusinessPartyId: $firstCustomer->id
            ),
            $actor
        );

        $second = $manager->create(
            new ServiceOrderIntakeData(
                assetType: ServiceAssetType::Notebook,
                brandName: 'LENOVO',
                modelName: 'IdeaPad 3',
                identifiers: [
                    new ServiceAssetIdentifierData(
                        ServiceIdentifierType::SerialNumber,
                        'pf123456'
                    ),
                    new ServiceAssetIdentifierData(
                        ServiceIdentifierType::AssetTag,
                        'SULU-NB-0001'
                    ),
                ],
                intakeLocationId: $location->id,
                customerReportedIssue: 'Reingresa por teclado en corto.',
                idempotencyKey: 'service:intake:lenovo:2',
                customerBusinessPartyId: $secondCustomer->id
            ),
            $actor
        );

        $this->assertSame($first->service_asset_id, $second->service_asset_id);
        $this->assertSame(1, (int) $first->order_number);
        $this->assertSame(2, (int) $second->order_number);
        $this->assertDatabaseCount('service_assets', 1);
        $this->assertDatabaseCount('service_asset_identifiers', 2);
        $this->assertDatabaseCount('service_orders', 2);
        $this->assertCount(2, $second->intake->identifiers_snapshot);

        $otherOrganization = $this->newOrganization('Taller colega');
        $otherActor = $this->user($otherOrganization, UserRole::Admin);
        $otherCustomer = $this->party($otherOrganization, 'Cliente colega');
        $otherLocation = $this->newLocation($otherOrganization);
        $other = $manager->create(
            $this->data(
                location: $otherLocation,
                customer: $otherCustomer,
                key: 'service:intake:other:1',
                serial: 'OTHER-001'
            ),
            $otherActor
        );

        $this->assertSame(1, (int) $other->order_number);
        $this->assertDatabaseCount('service_assets', 2);
        $this->assertDatabaseCount('service_orders', 3);
    }

    public function test_identifier_rejects_conflicting_asset_identity(): void
    {
        $organization = $this->organization();
        $actor = $this->user($organization, UserRole::Admin);
        $customer = $this->party($organization, 'Cliente conflicto');
        $location = $this->location($organization);
        $manager = app(ServiceOrderIntakeManager::class);

        $manager->create(
            new ServiceOrderIntakeData(
                assetType: ServiceAssetType::MobilePhone,
                brandName: 'Motorola',
                modelName: 'E22i',
                identifiers: [
                    new ServiceAssetIdentifierData(
                        ServiceIdentifierType::Imei,
                        '351234567890123'
                    ),
                ],
                intakeLocationId: $location->id,
                customerReportedIssue: 'Pantalla rota.',
                idempotencyKey: 'service:intake:identity:1',
                customerBusinessPartyId: $customer->id
            ),
            $actor
        );

        $conflicting = new ServiceOrderIntakeData(
            assetType: ServiceAssetType::MobilePhone,
            brandName: 'Samsung',
            modelName: 'A14',
            identifiers: [
                new ServiceAssetIdentifierData(
                    ServiceIdentifierType::Imei,
                    '351234567890123'
                ),
            ],
            intakeLocationId: $location->id,
            customerReportedIssue: 'No enciende.',
            idempotencyKey: 'service:intake:identity:2',
            customerBusinessPartyId: $customer->id
        );

        $this->assertDomainFailure(
            fn () => $manager->create($conflicting, $actor)
        );
        $this->assertDatabaseCount('service_assets', 1);
        $this->assertDatabaseCount('service_orders', 1);
    }

    public function test_roles_and_active_membership_are_enforced(): void
    {
        $organization = $this->organization();
        $admin = $this->user($organization, UserRole::Admin);
        $operator = $this->user($organization, UserRole::Operator);
        $viewer = $this->user($organization, UserRole::Viewer);
        $customer = $this->party($organization, 'Cliente de roles');
        $location = $this->location($organization);
        $manager = app(ServiceOrderIntakeManager::class);

        $created = $manager->create(
            $this->data(
                location: $location,
                customer: $customer,
                key: 'service:intake:operator:1',
                serial: 'ROLE-OPERATOR'
            ),
            $operator
        );

        $this->assertSame($operator->id, $created->created_by_user_id);

        $this->assertDomainFailure(
            fn () => $manager->create(
                $this->data(
                    location: $location,
                    customer: $customer,
                    key: 'service:intake:viewer:1',
                    serial: 'ROLE-VIEWER'
                ),
                $viewer
            )
        );

        OrganizationMembership::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $admin->id)
            ->update(['active' => false]);

        $this->assertDomainFailure(
            fn () => $manager->create(
                $this->data(
                    location: $location,
                    customer: $customer,
                    key: 'service:intake:inactive:1',
                    serial: 'ROLE-INACTIVE'
                ),
                $admin
            )
        );

        $this->assertDatabaseCount('service_orders', 1);
    }

    public function test_cross_organization_relations_are_rejected_by_domain_and_database(): void
    {
        $first = $this->organization();
        $firstActor = $this->user($first, UserRole::Admin);
        $firstCustomer = $this->party($first, 'Cliente local');
        $firstLocation = $this->location($first);
        $second = $this->newOrganization('Organización hostil');
        $foreignCustomer = $this->party($second, 'Cliente ajeno');
        $foreignLocation = $this->newLocation($second);
        $manager = app(ServiceOrderIntakeManager::class);

        $this->assertDomainFailure(
            fn () => $manager->create(
                $this->data(
                    location: $firstLocation,
                    customer: $foreignCustomer,
                    key: 'service:intake:foreign-customer:1'
                ),
                $firstActor
            )
        );

        $this->assertDomainFailure(
            fn () => $manager->create(
                $this->data(
                    location: $foreignLocation,
                    customer: $firstCustomer,
                    key: 'service:intake:foreign-location:1'
                ),
                $firstActor
            )
        );

        $valid = $manager->create(
            $this->data(
                location: $firstLocation,
                customer: $firstCustomer,
                key: 'service:intake:valid:1'
            ),
            $firstActor
        );

        $this->assertQueryRejected(
            fn () => DB::table('service_orders')->insert([
                'organization_id' => $second->id,
                'public_id' => (string) Str::uuid(),
                'order_number' => 1,
                'service_asset_id' => $valid->service_asset_id,
                'customer_business_party_id' => null,
                'owner_business_party_id' => null,
                'intake_location_id' => $foreignLocation->id,
                'status' => ServiceOrderStatus::Received->value,
                'created_by_user_id' => $firstActor->id,
                'received_at' => now(),
                'promised_at' => null,
                'idempotency_key' => 'hostile-direct-insert',
                'metadata' => '{}',
                'created_at' => now(),
                'updated_at' => now(),
            ])
        );

        $this->assertDatabaseCount('service_orders', 1);
    }

    public function test_historical_intake_identity_and_custody_are_database_immutable(): void
    {
        $organization = $this->organization();
        $actor = $this->user($organization, UserRole::Admin);
        $customer = $this->party($organization, 'Cliente histórico');
        $order = app(ServiceOrderIntakeManager::class)->create(
            $this->data(
                location: $this->location($organization),
                customer: $customer,
                key: 'service:intake:immutable:1'
            ),
            $actor
        );

        $identifierId = $order->asset->identifiers->sole()->id;
        $intakeId = $order->intake->id;
        $historyId = $order->statusHistory->sole()->id;
        $custodyId = $order->custodyEvents->sole()->id;

        $this->assertQueryRejected(
            fn () => DB::table('service_asset_identifiers')
                ->where('id', $identifierId)
                ->update(['value' => 'ALTERED'])
        );
        $this->assertQueryRejected(
            fn () => DB::table('service_order_intakes')
                ->where('id', $intakeId)
                ->update(['customer_reported_issue' => 'Alterada'])
        );
        $this->assertQueryRejected(
            fn () => DB::table('service_order_status_histories')
                ->where('id', $historyId)
                ->delete()
        );
        $this->assertQueryRejected(
            fn () => DB::table('service_custody_events')
                ->where('id', $custodyId)
                ->update(['from_holder_name' => 'Alterado'])
        );
        $this->assertQueryRejected(
            fn () => DB::table('service_orders')
                ->where('id', $order->id)
                ->update(['idempotency_key' => 'altered'])
        );
        $this->assertQueryRejected(
            fn () => DB::table('service_orders')
                ->where('id', $order->id)
                ->update(['status' => ServiceOrderStatus::Diagnosing->value])
        );
        $this->assertQueryRejected(
            fn () => DB::table('service_orders')
                ->where('id', $order->id)
                ->update(['metadata' => '{"altered":true}'])
        );
        $this->assertQueryRejected(
            fn () => DB::table('service_orders')
                ->where('id', $order->id)
                ->delete()
        );

        $this->assertDatabaseHas('service_order_intakes', [
            'id' => $intakeId,
            'customer_reported_issue' => 'Funciona lentamente.',
        ]);
        $this->assertDatabaseCount('service_orders', 1);
    }

    public function test_invalid_contact_imei_or_customer_rolls_back_completely(): void
    {
        $organization = $this->organization();
        $actor = $this->user($organization, UserRole::Admin);
        $location = $this->location($organization);
        $manager = app(ServiceOrderIntakeManager::class);

        $invalidImei = new ServiceOrderIntakeData(
            assetType: ServiceAssetType::MobilePhone,
            brandName: 'Motorola',
            modelName: 'E22i',
            identifiers: [
                new ServiceAssetIdentifierData(
                    ServiceIdentifierType::Imei,
                    '12345'
                ),
            ],
            intakeLocationId: $location->id,
            customerReportedIssue: 'Pantalla rota.',
            idempotencyKey: 'service:intake:invalid-imei:1',
            customerName: 'Cliente sin ficha'
        );

        $this->assertDomainFailure(
            fn () => $manager->create($invalidImei, $actor)
        );

        $missingContact = new ServiceOrderIntakeData(
            assetType: ServiceAssetType::Notebook,
            brandName: 'Lenovo',
            modelName: 'IdeaPad 3',
            identifiers: [],
            intakeLocationId: $location->id,
            customerReportedIssue: 'Funciona lentamente.',
            idempotencyKey: 'service:intake:missing-contact:1',
            customerName: 'Cliente sin teléfono',
            contactAvailable: true
        );

        $this->assertDomainFailure(
            fn () => $manager->create($missingContact, $actor)
        );

        $missingCustomer = new ServiceOrderIntakeData(
            assetType: ServiceAssetType::Notebook,
            brandName: 'Lenovo',
            modelName: 'IdeaPad 3',
            identifiers: [],
            intakeLocationId: $location->id,
            customerReportedIssue: 'Funciona lentamente.',
            idempotencyKey: 'service:intake:missing-customer:1'
        );

        $this->assertDomainFailure(
            fn () => $manager->create($missingCustomer, $actor)
        );

        $this->assertDatabaseCount('service_assets', 0);
        $this->assertDatabaseCount('service_orders', 0);
        $this->assertDatabaseCount('service_order_intakes', 0);
        $this->assertDatabaseCount('service_custody_events', 0);
    }

    private function data(
        InventoryLocation $location,
        BusinessParty $customer,
        string $key,
        string $serial = 'LENOVO-FOUNDATION-001'
    ): ServiceOrderIntakeData {
        return new ServiceOrderIntakeData(
            assetType: ServiceAssetType::Notebook,
            brandName: 'Lenovo',
            modelName: 'IdeaPad 3',
            identifiers: [
                new ServiceAssetIdentifierData(
                    ServiceIdentifierType::SerialNumber,
                    $serial
                ),
            ],
            intakeLocationId: $location->id,
            customerReportedIssue: 'Funciona lentamente.',
            idempotencyKey: $key,
            customerBusinessPartyId: $customer->id,
            intakeObservations: 'Disco mecánico con posible degradación.',
            receivedAccessories: 'Notebook y cargador.',
            contactAvailable: true,
            contactReference: '+54 9 3447 000000'
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
            )
            ->firstOrFail();
    }

    private function newLocation(
        Organization $organization
    ): InventoryLocation {
        return InventoryLocation::query()->create([
            'organization_id' => $organization->id,
            'parent_id' => null,
            'name' => 'Recepción técnica',
            'type' => InventoryLocationType::Receiving,
            'active' => true,
        ]);
    }

    private function assertDomainFailure(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Se esperaba una excepción de dominio.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }
    }

    private function assertQueryRejected(callable $callback): void
    {
        try {
            $callback();
            $this->fail('La base de datos aceptó una operación inválida.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
