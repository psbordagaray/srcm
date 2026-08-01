<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\InventoryMovementConfirmer;
use App\Domain\Inventory\InventoryMovementCreator;
use App\Domain\Inventory\InventoryMovementDraftData;
use App\Domain\Inventory\InventoryMovementLineData;
use App\Domain\Inventory\InventoryNegativeOverrideIssuer;
use App\Domain\Inventory\InventoryNegativeRequestManager;
use App\Enums\InventoryCondition;
use App\Enums\InventoryMovementStatus;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryNegativeIncidentStatus;
use App\Enums\InventoryNegativeOverrideStatus;
use App\Enums\InventoryNegativeRequestStatus;
use App\Enums\UserRole;
use App\Models\CatalogProduct;
use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryNegativeIncident;
use App\Models\InventoryNegativeOverride;
use App\Models\InventoryNegativeRequest;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductCategory;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

class InventoryNegativeConfirmationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_override_is_consumed_atomically_and_idempotently(): void
    {
        $organization = $this->organization();
        $operator = $this->user($organization, UserRole::Operator);
        $admin = $this->user($organization, UserRole::Admin);
        $product = $this->product('Artículo excepcional', 'NEG-CONFIRM');
        $location = $this->locations($organization)[0];
        $balance = $this->balance(
            $organization,
            $product,
            $location,
            '1.000000'
        );
        [$movement, $request, $override] = $this->authorizedIssue(
            $operator,
            $admin,
            $product,
            [[$location, '3']],
            'negative:confirm:1'
        );
        $confirmer = app(InventoryMovementConfirmer::class);

        $first = $confirmer->confirmWithNegativeOverride(
            $movement,
            $override,
            $operator
        );
        $second = $confirmer->confirmWithNegativeOverride(
            $movement->id,
            $override->id,
            $operator
        );

        $this->assertFalse($first->invalidated);
        $this->assertSame($first->incident->id, $second->incident->id);
        $this->assertSame(
            InventoryMovementStatus::Confirmed,
            $first->movement->status
        );
        $this->assertSame($operator->id, $first->movement->confirmed_by_user_id);
        $this->assertSame(
            InventoryNegativeOverrideStatus::Consumed,
            $first->override->status
        );
        $this->assertSame(
            InventoryNegativeRequestStatus::Fulfilled,
            $first->request->status
        );
        $this->assertSame(
            InventoryNegativeIncidentStatus::Open,
            $first->incident->status
        );
        $this->assertSame('-2.000000', $balance->refresh()->quantity);

        $line = $first->incident->lines->sole();
        $this->assertSame('1.000000', $line->previous_quantity);
        $this->assertSame('3.000000', $line->outgoing_quantity);
        $this->assertSame('0.000000', $line->incoming_quantity);
        $this->assertSame('-3.000000', $line->net_quantity);
        $this->assertSame('-2.000000', $line->resulting_quantity);
        $this->assertSame('0.000000', $line->previous_deficit);
        $this->assertSame('2.000000', $line->resulting_deficit);
        $this->assertSame('2.000000', $line->incremental_deficit);
        $this->assertSame('2.000000', $line->pending_deficit);
        $this->assertSame($operator->id, $first->incident
            ->statusHistory->sole()->changed_by_user_id);
        $this->assertDatabaseCount('inventory_negative_incidents', 1);
        $this->assertDatabaseCount('inventory_negative_incident_lines', 1);
        $this->assertDatabaseCount(
            'inventory_negative_incident_status_histories',
            1
        );
    }

    public function test_only_authorized_active_user_can_consume_override(): void
    {
        $organization = $this->organization();
        $operator = $this->user($organization, UserRole::Operator);
        $other = $this->user($organization, UserRole::Operator);
        $admin = $this->user($organization, UserRole::Admin);
        $foreign = $this->newOrganization('Tenant consumo ajeno');
        $foreignOperator = $this->user($foreign, UserRole::Operator);
        $product = $this->product('Artículo reservado', 'NEG-ACTOR');
        $location = $this->locations($organization)[0];
        $balance = $this->balance(
            $organization,
            $product,
            $location,
            '0.000000'
        );
        [$movement, $request, $override] = $this->authorizedIssue(
            $operator,
            $admin,
            $product,
            [[$location, '1']],
            'negative:actor:1'
        );
        $confirmer = app(InventoryMovementConfirmer::class);

        $this->assertDomainFailure(
            fn () => $confirmer->confirmWithNegativeOverride(
                $movement,
                $override,
                $other
            )
        );
        $this->assertDomainFailure(
            fn () => $confirmer->confirmWithNegativeOverride(
                $movement,
                $override,
                $foreignOperator
            )
        );

        $this->assertSame('0.000000', $balance->refresh()->quantity);
        $this->assertSame(
            InventoryMovementStatus::Draft,
            $movement->refresh()->status
        );
        $this->assertSame(
            InventoryNegativeRequestStatus::Approved,
            $request->refresh()->status
        );
        $this->assertSame(
            InventoryNegativeOverrideStatus::Active,
            $override->refresh()->status
        );
        $this->assertDatabaseCount('inventory_negative_incidents', 0);
    }

    public function test_balance_change_invalidates_request_and_override(): void
    {
        $organization = $this->organization();
        $operator = $this->user($organization, UserRole::Operator);
        $admin = $this->user($organization, UserRole::Admin);
        $product = $this->product('Artículo cambiante', 'NEG-STALE');
        $location = $this->locations($organization)[0];
        $balance = $this->balance(
            $organization,
            $product,
            $location,
            '1.000000'
        );
        [$movement, $request, $override] = $this->authorizedIssue(
            $operator,
            $admin,
            $product,
            [[$location, '2']],
            'negative:stale:1'
        );

        $balance->forceFill([
            'quantity' => '2.000000',
            'version' => $balance->version + 1,
        ])->save();

        $result = app(InventoryMovementConfirmer::class)
            ->confirmWithNegativeOverride(
                $movement,
                $override,
                $operator
            );

        $this->assertTrue($result->invalidated);
        $this->assertNull($result->incident);
        $this->assertSame(
            InventoryNegativeRequestStatus::Invalidated,
            $request->refresh()->status
        );
        $this->assertSame(
            InventoryNegativeOverrideStatus::Invalidated,
            $override->refresh()->status
        );
        $this->assertSame(
            InventoryMovementStatus::Draft,
            $movement->refresh()->status
        );
        $this->assertSame('2.000000', $balance->refresh()->quantity);
        $this->assertDatabaseCount('inventory_negative_incidents', 0);
    }

    public function test_net_effect_is_snapshotted_and_incident_is_precise(): void
    {
        $organization = $this->organization();
        $operator = $this->user($organization, UserRole::Operator);
        $admin = $this->user($organization, UserRole::Admin);
        $product = $this->product('Artículo transferido', 'NEG-NET');
        [$firstLocation, $secondLocation] = $this->locations($organization);
        $firstBalance = $this->balance(
            $organization,
            $product,
            $firstLocation,
            '0.000000'
        );
        $secondBalance = $this->balance(
            $organization,
            $product,
            $secondLocation,
            '0.000000'
        );
        $movement = app(InventoryMovementCreator::class)->create(
            new InventoryMovementDraftData(
                type: InventoryMovementType::Transfer,
                effectiveAt: now(),
                reason: 'Transferencia cruzada',
                idempotencyKey: 'negative:net:1',
                lines: [
                    new InventoryMovementLineData(
                        catalogProductId: $product->id,
                        condition: InventoryCondition::New,
                        enteredQuantity: '5',
                        enteredUnitCode: 'unit',
                        sourceLocationId: $firstLocation->id,
                        destinationLocationId: $secondLocation->id
                    ),
                    new InventoryMovementLineData(
                        catalogProductId: $product->id,
                        condition: InventoryCondition::New,
                        enteredQuantity: '4',
                        enteredUnitCode: 'unit',
                        sourceLocationId: $secondLocation->id,
                        destinationLocationId: $firstLocation->id
                    ),
                ]
            ),
            $operator
        );
        $request = app(InventoryNegativeRequestManager::class)->request(
            $movement,
            'Efecto neto controlado',
            $operator
        );
        $override = app(InventoryNegativeOverrideIssuer::class)
            ->issue($request, $admin)
            ->override;

        $result = app(InventoryMovementConfirmer::class)
            ->confirmWithNegativeOverride(
                $movement,
                $override,
                $operator
            );

        $this->assertSame('-1.000000', $firstBalance->refresh()->quantity);
        $this->assertSame('1.000000', $secondBalance->refresh()->quantity);
        $this->assertCount(2, $request->refresh()->lines);
        $this->assertCount(1, $result->incident->lines);

        $line = $result->incident->lines->sole();
        $this->assertSame($firstLocation->id, $line->inventory_location_id);
        $this->assertSame('5.000000', $line->outgoing_quantity);
        $this->assertSame('4.000000', $line->incoming_quantity);
        $this->assertSame('-1.000000', $line->net_quantity);
        $this->assertSame('1.000000', $line->incremental_deficit);
    }

    public function test_failure_after_projection_rolls_everything_back(): void
    {
        $organization = $this->organization();
        $operator = $this->user($organization, UserRole::Operator);
        $admin = $this->user($organization, UserRole::Admin);
        $product = $this->product('Artículo transaccional', 'NEG-ROLLBACK');
        $location = $this->locations($organization)[0];
        $balance = $this->balance(
            $organization,
            $product,
            $location,
            '0.000000'
        );
        $movement = $this->issue(
            $operator,
            $product,
            [[$location, '1']],
            'negative:rollback:1'
        );
        $manager = app(InventoryNegativeRequestManager::class);
        $issuer = app(InventoryNegativeOverrideIssuer::class);
        $request = $manager->request(
            $movement,
            'Autorización transaccional',
            $operator
        );
        $override = $issuer->issue($request, $admin)->override;

        InventoryNegativeIncident::query()->create([
            'organization_id' => $organization->id,
            'inventory_movement_id' => $movement->id,
            'inventory_negative_request_id' => $request->id,
            'inventory_negative_override_id' => $override->id,
            'requested_by_user_id' => $operator->id,
            'granted_by_user_id' => $admin->id,
            'status' => InventoryNegativeIncidentStatus::Open,
            'reason' => $request->reason,
            'opened_at' => now(),
        ]);

        $this->assertDomainFailure(
            fn () => app(InventoryMovementConfirmer::class)
                ->confirmWithNegativeOverride(
                    $movement,
                    $override,
                    $operator
                )
        );

        $this->assertSame('0.000000', $balance->refresh()->quantity);
        $this->assertSame(1, $balance->version);
        $this->assertSame(
            InventoryMovementStatus::Draft,
            $movement->refresh()->status
        );
        $this->assertSame(
            InventoryNegativeOverrideStatus::Active,
            $override->refresh()->status
        );
        $this->assertSame(
            InventoryNegativeRequestStatus::Approved,
            $request->refresh()->status
        );
        $this->assertDatabaseCount('inventory_negative_incidents', 1);
    }

    public function test_incident_records_are_database_immutable(): void
    {
        $organization = $this->organization();
        $operator = $this->user($organization, UserRole::Operator);
        $admin = $this->user($organization, UserRole::Admin);
        $product = $this->product('Artículo auditable', 'NEG-IMMUTABLE');
        $location = $this->locations($organization)[0];
        [$movement, , $override] = $this->authorizedIssue(
            $operator,
            $admin,
            $product,
            [[$location, '1']],
            'negative:immutable:1'
        );
        $incident = app(InventoryMovementConfirmer::class)
            ->confirmWithNegativeOverride(
                $movement,
                $override,
                $operator
            )->incident;

        $this->assertDatabaseRejected(
            fn () => DB::table('inventory_negative_incidents')
                ->where('id', $incident->id)
                ->update(['reason' => 'Manipulado'])
        );
        $this->assertDatabaseRejected(
            fn () => DB::table('inventory_negative_incident_lines')
                ->where('inventory_negative_incident_id', $incident->id)
                ->update(['pending_deficit' => '0.000000'])
        );
        $this->assertDatabaseRejected(
            fn () => DB::table(
                'inventory_negative_incident_status_histories'
            )->where('inventory_negative_incident_id', $incident->id)
                ->delete()
        );
        $this->assertDatabaseRejected(
            fn () => DB::table('inventory_negative_incident_lines')
                ->insert([
                    'organization_id' => $organization->id,
                    'inventory_negative_incident_id' => $incident->id,
                    'sequence' => 2,
                    'catalog_product_id' => $product->id,
                    'inventory_location_id' => $location->id,
                    'condition' => InventoryCondition::Damaged->value,
                    'previous_quantity' => '0.000000',
                    'outgoing_quantity' => '1.000000',
                    'incoming_quantity' => '0.000000',
                    'net_quantity' => '-1.000000',
                    'resulting_quantity' => '-1.000000',
                    'previous_deficit' => '0.000000',
                    'resulting_deficit' => '1.000000',
                    'incremental_deficit' => '1.000000',
                    'pending_deficit' => '1.000000',
                    'base_unit_code' => 'unit',
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
        );
        $this->assertDatabaseRejected(
            fn () => DB::table(
                'inventory_negative_incident_status_histories'
            )->insert([
                'organization_id' => $organization->id,
                'inventory_negative_incident_id' => $incident->id,
                'from_status' => InventoryNegativeIncidentStatus::Open->value,
                'to_status' =>
                    InventoryNegativeIncidentStatus::UnderReview->value,
                'changed_by_user_id' => $admin->id,
                'reason' => 'Historia inyectada',
                'changed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ])
        );
        $this->assertDatabaseRejected(
            fn () => DB::table('inventory_negative_incidents')
                ->where('id', $incident->id)
                ->delete()
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

    /** @return array{InventoryLocation, InventoryLocation} */
    private function locations(Organization $organization): array
    {
        $locations = InventoryLocation::query()
            ->where('organization_id', $organization->id)
            ->orderBy('id')
            ->limit(2)
            ->get();

        return [$locations[0], $locations[1]];
    }

    private function product(
        string $name,
        string $sku
    ): CatalogProduct {
        $category = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()->firstOrCreate(
                ['slug' => 'negative-confirmation'],
                ['name' => 'Negative Confirmation', 'active' => true]
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

    /**
     * @param list<array{InventoryLocation, string}> $sources
     */
    private function issue(
        User $actor,
        CatalogProduct $product,
        array $sources,
        string $idempotencyKey
    ): InventoryMovement {
        return app(InventoryMovementCreator::class)->create(
            new InventoryMovementDraftData(
                type: InventoryMovementType::Issue,
                effectiveAt: now(),
                reason: 'Salida negativa de prueba',
                idempotencyKey: $idempotencyKey,
                lines: array_map(
                    fn (array $source) => new InventoryMovementLineData(
                        catalogProductId: $product->id,
                        condition: InventoryCondition::New,
                        enteredQuantity: $source[1],
                        enteredUnitCode: 'unit',
                        sourceLocationId: $source[0]->id
                    ),
                    $sources
                )
            ),
            $actor
        );
    }

    /**
     * @param list<array{InventoryLocation, string}> $sources
     * @return array{
     *     InventoryMovement,
     *     InventoryNegativeRequest,
     *     InventoryNegativeOverride
     * }
     */
    private function authorizedIssue(
        User $operator,
        User $admin,
        CatalogProduct $product,
        array $sources,
        string $idempotencyKey
    ): array {
        $movement = $this->issue(
            $operator,
            $product,
            $sources,
            $idempotencyKey
        );
        $request = app(InventoryNegativeRequestManager::class)->request(
            $movement,
            'Autorización excepcional de prueba',
            $operator
        );
        $override = app(InventoryNegativeOverrideIssuer::class)
            ->issue($request, $admin)
            ->override;

        return [$movement, $request, $override];
    }

    private function assertDomainFailure(callable $callback): void
    {
        try {
            $callback();
        } catch (DomainException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('La confirmación negativa debió ser rechazada.');
    }

    private function assertDatabaseRejected(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('La base debió rechazar la manipulación de incidencia.');
    }
}
