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
use App\Enums\InventoryNegativeOverrideStatus;
use App\Enums\InventoryNegativeRequestStatus;
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
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

class InventoryNegativeAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_negative_authorization_role_matrix_is_explicit(): void
    {
        $organization = $this->organization();

        foreach ([UserRole::Admin, UserRole::Operator] as $role) {
            $user = $this->user($organization, $role);

            $this->assertTrue($role->canRequestInventoryNegative());
            $this->assertTrue(
                Gate::forUser($user)->allows('request-inventory-negative')
            );
        }

        $viewer = $this->user($organization, UserRole::Viewer);
        $admin = $this->user($organization, UserRole::Admin);
        $operator = $this->user($organization, UserRole::Operator);

        $this->assertFalse(UserRole::Viewer->canRequestInventoryNegative());
        $this->assertFalse(
            Gate::forUser($viewer)->allows('request-inventory-negative')
        );
        $this->assertTrue(UserRole::Admin->canOverrideInventoryNegative());
        $this->assertTrue(
            Gate::forUser($admin)->allows('override-inventory-negative')
        );
        $this->assertFalse(
            UserRole::Operator->canOverrideInventoryNegative()
        );
        $this->assertFalse(
            Gate::forUser($operator)->allows('override-inventory-negative')
        );
    }

    public function test_request_is_idempotent_and_snapshots_all_sources(): void
    {
        $organization = $this->organization();
        $operator = $this->user($organization, UserRole::Operator);
        $product = $this->product('Cable fraccionable', 'NEG-SNAPSHOT', 'm', 3);
        [$firstLocation, $secondLocation] = $this->locations($organization);

        $firstBalance = $this->balance(
            $organization,
            $product,
            $firstLocation,
            '5.000000'
        );
        $secondBalance = $this->balance(
            $organization,
            $product,
            $secondLocation,
            '10.000000'
        );
        $movement = $this->issue(
            $operator,
            $product,
            [
                [$firstLocation, '6.500'],
                [$secondLocation, '2.000'],
            ],
            'negative:snapshot:1'
        );
        $manager = app(InventoryNegativeRequestManager::class);

        $first = $manager->request(
            $movement,
            '  Venta urgente autorizable  ',
            $operator
        );
        $second = $manager->request(
            $movement,
            'Venta urgente autorizable',
            $operator
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(
            InventoryNegativeRequestStatus::Pending,
            $first->status
        );
        $this->assertSame($operator->id, $first->requested_by_user_id);
        $this->assertSame('Venta urgente autorizable', $first->reason);
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            $first->movement_fingerprint
        );
        $this->assertCount(2, $first->lines);

        $negative = $first->lines
            ->firstWhere('inventory_location_id', $firstLocation->id);
        $sufficient = $first->lines
            ->firstWhere('inventory_location_id', $secondLocation->id);

        $this->assertSame('5.000000', $negative->current_quantity);
        $this->assertSame('6.500000', $negative->requested_quantity);
        $this->assertSame('-1.500000', $negative->projected_quantity);
        $this->assertSame('1.500000', $negative->incremental_deficit);
        $this->assertSame($firstBalance->version, $negative->balance_version);
        $this->assertTrue($negative->creates_negative);
        $this->assertSame('8.000000', $sufficient->projected_quantity);
        $this->assertSame($secondBalance->version, $sufficient->balance_version);
        $this->assertFalse($sufficient->creates_negative);
        $this->assertDatabaseCount('inventory_negative_requests', 1);
        $this->assertDatabaseCount('inventory_negative_request_lines', 2);
    }

    public function test_invalid_or_unnecessary_requests_are_rejected(): void
    {
        $organization = $this->organization();
        $operator = $this->user($organization, UserRole::Operator);
        $otherOperator = $this->user($organization, UserRole::Operator);
        $viewer = $this->user($organization, UserRole::Viewer);
        $product = $this->product('Producto seguro', 'NEG-SAFE');
        $location = $this->locations($organization)[0];
        $this->balance($organization, $product, $location, '10.000000');
        $movement = $this->issue(
            $operator,
            $product,
            [[$location, '2']],
            'negative:safe:1'
        );
        $manager = app(InventoryNegativeRequestManager::class);

        $this->assertDomainFailure(
            fn () => $manager->request($movement, 'No hace falta', $operator)
        );
        $this->assertDomainFailure(
            fn () => $manager->request($movement, '', $operator)
        );

        $this->balance(
            $organization,
            $product,
            $location,
            '-1.000000',
            update: true
        );

        $this->assertDomainFailure(
            fn () => $manager->request($movement, 'Ajena', $otherOperator)
        );
        $this->assertDomainFailure(
            fn () => $manager->request($movement, 'Consulta', $viewer)
        );
        $this->assertDatabaseCount('inventory_negative_requests', 0);
    }

    public function test_admin_issues_once_but_ordinary_confirmation_still_fails(): void
    {
        $organization = $this->organization();
        $operator = $this->user($organization, UserRole::Operator);
        $admin = $this->user($organization, UserRole::Admin);
        $product = $this->product('Artículo escaso', 'NEG-ISSUE');
        $location = $this->locations($organization)[0];
        $balance = $this->balance(
            $organization,
            $product,
            $location,
            '1.000000'
        );
        $movement = $this->issue(
            $operator,
            $product,
            [[$location, '3']],
            'negative:issue:1'
        );
        $request = app(InventoryNegativeRequestManager::class)
            ->request($movement, 'Cliente en mostrador', $operator);
        $issuer = app(InventoryNegativeOverrideIssuer::class);

        $first = $issuer->issue($request, $admin);
        $second = $issuer->issue($request->id, $admin);

        $this->assertFalse($first->invalidated);
        $this->assertNotNull($first->override);
        $this->assertSame($first->override->id, $second->override->id);
        $this->assertSame(
            InventoryNegativeOverrideStatus::Active,
            $first->override->status
        );
        $this->assertSame($operator->id, $first->override->authorized_user_id);
        $this->assertSame($admin->id, $first->override->granted_by_user_id);
        $this->assertSame(
            InventoryNegativeRequestStatus::Approved,
            $first->request->status
        );
        $this->assertDatabaseCount('inventory_negative_overrides', 1);

        $this->assertDomainFailure(
            fn () => app(InventoryMovementConfirmer::class)
                ->confirm($movement, $operator)
        );
        $this->assertSame(
            InventoryMovementStatus::Draft,
            $movement->refresh()->status
        );
        $this->assertSame('1.000000', $balance->refresh()->quantity);
        $this->assertSame(
            InventoryNegativeOverrideStatus::Active,
            $first->override->refresh()->status
        );
    }

    public function test_only_same_organization_admin_may_issue(): void
    {
        $organization = $this->organization();
        $operator = $this->user($organization, UserRole::Operator);
        $product = $this->product('Artículo controlado', 'NEG-ROLE');
        $location = $this->locations($organization)[0];
        $movement = $this->issue(
            $operator,
            $product,
            [[$location, '1']],
            'negative:role:1'
        );
        $request = app(InventoryNegativeRequestManager::class)
            ->request($movement, 'Requiere permiso', $operator);
        $foreign = $this->newOrganization('Tenant Override ajeno');
        $foreignAdmin = $this->user($foreign, UserRole::Admin);
        $issuer = app(InventoryNegativeOverrideIssuer::class);

        $this->assertDomainFailure(
            fn () => $issuer->issue($request, $operator)
        );
        $this->assertDomainFailure(
            fn () => $issuer->issue($request, $foreignAdmin)
        );
        $this->assertSame(
            InventoryNegativeRequestStatus::Pending,
            $request->refresh()->status
        );
        $this->assertDatabaseCount('inventory_negative_overrides', 0);
    }

    public function test_changed_movement_or_balance_invalidates_request(): void
    {
        $organization = $this->organization();
        $operator = $this->user($organization, UserRole::Operator);
        $admin = $this->user($organization, UserRole::Admin);
        $product = $this->product('Artículo mutable', 'NEG-INVALIDATE');
        [$firstLocation, $secondLocation] = $this->locations($organization);
        $balance = $this->balance(
            $organization,
            $product,
            $firstLocation,
            '1.000000'
        );
        $firstMovement = $this->issue(
            $operator,
            $product,
            [[$firstLocation, '2']],
            'negative:changed-line:1'
        );
        $manager = app(InventoryNegativeRequestManager::class);
        $issuer = app(InventoryNegativeOverrideIssuer::class);
        $lineRequest = $manager->request(
            $firstMovement,
            'Antes de editar',
            $operator
        );

        $firstMovement->lines->sole()->forceFill([
            'source_location_id' => $secondLocation->id,
        ])->save();

        $lineResult = $issuer->issue($lineRequest, $admin);
        $this->assertTrue($lineResult->invalidated);
        $this->assertNull($lineResult->override);
        $this->assertSame(
            InventoryNegativeRequestStatus::Invalidated,
            $lineResult->request->status
        );

        $secondMovement = $this->issue(
            $operator,
            $product,
            [[$firstLocation, '2']],
            'negative:changed-balance:1'
        );
        $balanceRequest = $manager->request(
            $secondMovement,
            'Antes del saldo',
            $operator
        );
        $balance->forceFill([
            'quantity' => '2.000000',
            'version' => $balance->version + 1,
        ])->save();

        $balanceResult = $issuer->issue($balanceRequest, $admin);
        $this->assertTrue($balanceResult->invalidated);
        $this->assertNull($balanceResult->override);
        $this->assertSame(
            InventoryNegativeRequestStatus::Invalidated,
            $balanceResult->request->status
        );
        $this->assertDatabaseCount('inventory_negative_overrides', 0);
    }

    public function test_admin_can_reject_and_revoke_with_attribution(): void
    {
        $organization = $this->organization();
        $operator = $this->user($organization, UserRole::Operator);
        $admin = $this->user($organization, UserRole::Admin);
        $product = $this->product('Artículo administrado', 'NEG-LIFECYCLE');
        $location = $this->locations($organization)[0];
        $manager = app(InventoryNegativeRequestManager::class);
        $issuer = app(InventoryNegativeOverrideIssuer::class);

        $rejectedRequest = $manager->request(
            $this->issue(
                $operator,
                $product,
                [[$location, '1']],
                'negative:reject:1'
            ),
            'Primera solicitud',
            $operator
        );
        $rejected = $issuer->reject(
            $rejectedRequest,
            '  No se justifica  ',
            $admin
        );

        $this->assertSame(
            InventoryNegativeRequestStatus::Rejected,
            $rejected->status
        );
        $this->assertSame($admin->id, $rejected->rejected_by_user_id);
        $this->assertSame('No se justifica', $rejected->rejection_reason);

        $approvedRequest = $manager->request(
            $this->issue(
                $operator,
                $product,
                [[$location, '1']],
                'negative:revoke:1'
            ),
            'Segunda solicitud',
            $operator
        );
        $override = $issuer->issue($approvedRequest, $admin)->override;
        $revoked = $issuer->revoke(
            $override,
            '  Decisión retirada  ',
            $admin
        );

        $this->assertSame(
            InventoryNegativeOverrideStatus::Revoked,
            $revoked->status
        );
        $this->assertSame($admin->id, $revoked->revoked_by_user_id);
        $this->assertSame('Decisión retirada', $revoked->revocation_reason);
    }

    public function test_database_rejects_authorization_tampering(): void
    {
        $organization = $this->organization();
        $operator = $this->user($organization, UserRole::Operator);
        $admin = $this->user($organization, UserRole::Admin);
        $product = $this->product('Artículo inmutable', 'NEG-DB');
        $location = $this->locations($organization)[0];
        $request = app(InventoryNegativeRequestManager::class)->request(
            $this->issue(
                $operator,
                $product,
                [[$location, '1']],
                'negative:database:1'
            ),
            'Defensa de base',
            $operator
        );
        $override = app(InventoryNegativeOverrideIssuer::class)
            ->issue($request, $admin)
            ->override;
        $line = $request->lines->sole();

        $this->assertDatabaseRejected(
            fn () => DB::table('inventory_negative_requests')
                ->where('id', $request->id)
                ->update(['reason' => 'Manipulado'])
        );
        $this->assertDatabaseRejected(
            fn () => DB::table('inventory_negative_request_lines')
                ->where('id', $line->id)
                ->update(['requested_quantity' => '999.000000'])
        );
        $this->assertDatabaseRejected(
            fn () => DB::table('inventory_negative_overrides')
                ->where('id', $override->id)
                ->update(['authorized_user_id' => $admin->id])
        );
        $this->assertDatabaseRejected(
            fn () => DB::table('inventory_negative_overrides')
                ->where('id', $override->id)
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
                [
                    'role' => $role->value,
                    'active' => true,
                ]
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
        string $sku,
        string $baseUnit = 'unit',
        int $scale = 0
    ): CatalogProduct {
        $category = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()->firstOrCreate(
                ['slug' => 'negative-authorization'],
                ['name' => 'Negative Authorization', 'active' => true]
            )
        );

        return CatalogProduct::withoutEvents(
            fn () => CatalogProduct::query()->create([
                'product_category_id' => $category->id,
                'sku' => $sku,
                'name' => $name,
                'base_unit_code' => $baseUnit,
                'quantity_scale' => $scale,
                'active' => true,
            ])->refresh()
        );
    }

    private function balance(
        Organization $organization,
        CatalogProduct $product,
        InventoryLocation $location,
        string $quantity,
        bool $update = false
    ): InventoryBalance {
        if ($update) {
            $balance = InventoryBalance::query()
                ->where('organization_id', $organization->id)
                ->where('catalog_product_id', $product->id)
                ->where('inventory_location_id', $location->id)
                ->where('condition', InventoryCondition::New->value)
                ->firstOrFail();
            $balance->forceFill([
                'quantity' => $quantity,
                'version' => $balance->version + 1,
            ])->save();

            return $balance->refresh();
        }

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
                reason: 'Salida de prueba',
                idempotencyKey: $idempotencyKey,
                lines: array_map(
                    fn (array $source) => new InventoryMovementLineData(
                        catalogProductId: $product->id,
                        condition: InventoryCondition::New,
                        enteredQuantity: $source[1],
                        enteredUnitCode: $product->base_unit_code,
                        sourceLocationId: $source[0]->id
                    ),
                    $sources
                )
            ),
            $actor
        );
    }

    private function assertDomainFailure(callable $callback): void
    {
        try {
            $callback();
        } catch (DomainException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('La operación de autorización debió ser rechazada.');
    }

    private function assertDatabaseRejected(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('La base debió rechazar la manipulación.');
    }
}
