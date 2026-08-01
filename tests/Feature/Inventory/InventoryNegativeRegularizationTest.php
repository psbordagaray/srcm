<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\InventoryMovementConfirmer;
use App\Domain\Inventory\InventoryMovementCreator;
use App\Domain\Inventory\InventoryMovementDraftData;
use App\Domain\Inventory\InventoryMovementLineData;
use App\Domain\Inventory\InventoryNegativeIncidentLifecycle;
use App\Domain\Inventory\InventoryNegativeOverrideIssuer;
use App\Domain\Inventory\InventoryNegativeRequestManager;
use App\Enums\InventoryCondition;
use App\Enums\InventoryMovementStatus;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryNegativeIncidentStatus;
use App\Enums\UserRole;
use App\Models\CatalogProduct;
use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryNegativeIncident;
use App\Models\InventoryNegativeRegularization;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductCategory;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

class InventoryNegativeRegularizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_regularization_is_fifo_partial_and_administrative(): void
    {
        $organization = $this->organization();
        $operator = $this->user($organization, UserRole::Operator);
        $admin = $this->user($organization, UserRole::Admin);
        $product = $this->product('FIFO negativo', 'NEG-FIFO');
        $location = $this->location($organization);
        $balance = $this->balance(
            $organization,
            $product,
            $location,
            '1'
        );
        $first = $this->incident(
            $operator,
            $admin,
            $product,
            $location,
            '3',
            'negative:fifo:1'
        );
        $second = $this->incident(
            $operator,
            $admin,
            $product,
            $location,
            '3',
            'negative:fifo:2'
        );
        $lifecycle = app(InventoryNegativeIncidentLifecycle::class);

        $reviewed = $lifecycle->markUnderReview(
            $first,
            'Control administrativo iniciado',
            $admin
        );
        $this->assertSame(
            InventoryNegativeIncidentStatus::UnderReview,
            $reviewed->status
        );
        $this->assertSame($admin->id, $reviewed->reviewed_by_user_id);

        $receipt = $this->receipt(
            $operator,
            $product,
            $location,
            '4',
            'negative:fifo:receipt:1'
        );
        app(InventoryMovementConfirmer::class)->confirm(
            $receipt,
            $operator
        );

        $this->assertSame('-1.000000', $balance->refresh()->quantity);
        $this->assertSame(
            '0.000000',
            $first->lines()->sole()->pending_deficit
        );
        $this->assertSame(
            '1.000000',
            $second->lines()->sole()->pending_deficit
        );
        $this->assertNotNull($first->refresh()->regularized_at);
        $this->assertNull($second->refresh()->regularized_at);
        $this->assertSame(
            ['2.000000', '2.000000'],
            DB::table('inventory_negative_regularizations')
                ->where('regularizing_movement_id', $receipt->id)
                ->orderBy('id')
                ->pluck('quantity')
                ->map(fn (mixed $quantity): string =>
                    number_format((float) $quantity, 6, '.', ''))
                ->all()
        );

        $this->assertDomainFailure(
            fn () => $lifecycle->resolve(
                $second,
                'Intento prematuro',
                $admin
            )
        );
        $this->assertDomainFailure(
            fn () => $lifecycle->resolve(
                $first,
                'Operador sin potestad',
                $operator
            )
        );

        $resolvedFirst = $lifecycle->resolve(
            $first,
            'Déficit físico compensado y caso revisado',
            $admin
        );
        $this->assertSame(
            InventoryNegativeIncidentStatus::Resolved,
            $resolvedFirst->status
        );
        $this->assertCount(3, $resolvedFirst->statusHistory);

        $lastReceipt = $this->receipt(
            $operator,
            $product,
            $location,
            '1',
            'negative:fifo:receipt:2'
        );
        app(InventoryMovementConfirmer::class)->confirm(
            $lastReceipt,
            $operator
        );
        $resolvedSecond = $lifecycle->resolve(
            $second,
            'Déficit restante compensado',
            $admin
        );

        $this->assertSame('0.000000', $balance->refresh()->quantity);
        $this->assertSame(
            InventoryNegativeIncidentStatus::Resolved,
            $resolvedSecond->status
        );
        $this->assertCount(2, $resolvedSecond->statusHistory);
    }

    public function test_regularization_respects_dimension_and_caps_overreceipt(): void
    {
        $organization = $this->organization();
        $operator = $this->user($organization, UserRole::Operator);
        $admin = $this->user($organization, UserRole::Admin);
        $product = $this->product('Dimensión negativa', 'NEG-DIM');
        $location = $this->location($organization);
        $balance = $this->balance(
            $organization,
            $product,
            $location,
            '0'
        );
        $incident = $this->incident(
            $operator,
            $admin,
            $product,
            $location,
            '2',
            'negative:dimension:1'
        );

        $usedReceipt = $this->receipt(
            $operator,
            $product,
            $location,
            '10',
            'negative:dimension:used',
            InventoryCondition::Used
        );
        app(InventoryMovementConfirmer::class)->confirm(
            $usedReceipt,
            $operator
        );

        $this->assertSame(
            '2.000000',
            $incident->lines()->sole()->pending_deficit
        );
        $this->assertDatabaseCount('inventory_negative_regularizations', 0);

        $receipt = $this->receipt(
            $operator,
            $product,
            $location,
            '10',
            'negative:dimension:new'
        );
        app(InventoryMovementConfirmer::class)->confirm(
            $receipt,
            $operator
        );

        $this->assertSame('8.000000', $balance->refresh()->quantity);
        $this->assertSame(
            '2.000000',
            number_format(
                (float) DB::table('inventory_negative_regularizations')
                    ->where('regularizing_movement_id', $receipt->id)
                    ->value('quantity'),
                6,
                '.',
                ''
            )
        );
    }

    public function test_fractional_regularization_is_exact(): void
    {
        $organization = $this->organization();
        $operator = $this->user($organization, UserRole::Operator);
        $admin = $this->user($organization, UserRole::Admin);
        $product = $this->product('Líquido negativo', 'NEG-FRACTION', 6);
        $location = $this->location($organization);
        $balance = $this->balance(
            $organization,
            $product,
            $location,
            '0'
        );
        $incident = $this->incident(
            $operator,
            $admin,
            $product,
            $location,
            '2.5',
            'negative:fraction:1'
        );
        $receipt = $this->receipt(
            $operator,
            $product,
            $location,
            '1.125',
            'negative:fraction:receipt'
        );

        app(InventoryMovementConfirmer::class)->confirm(
            $receipt,
            $operator
        );

        $this->assertSame('-1.375000', $balance->refresh()->quantity);
        $this->assertSame(
            '1.375000',
            $incident->lines()->sole()->pending_deficit
        );
        $this->assertSame(
            '1.125000',
            number_format(
                (float) DB::table('inventory_negative_regularizations')
                    ->value('quantity'),
                6,
                '.',
                ''
            )
        );
    }

    public function test_database_rejects_forged_regularization_and_history(): void
    {
        $organization = $this->organization();
        $operator = $this->user($organization, UserRole::Operator);
        $admin = $this->user($organization, UserRole::Admin);
        $product = $this->product('Defensa negativa', 'NEG-GUARD');
        $location = $this->location($organization);
        $this->balance($organization, $product, $location, '0');
        $incident = $this->incident(
            $operator,
            $admin,
            $product,
            $location,
            '2',
            'negative:guard:1'
        );
        $line = $incident->lines()->sole();
        $draftReceipt = $this->receipt(
            $operator,
            $product,
            $location,
            '1',
            'negative:guard:receipt'
        );

        $this->assertDatabaseRejected(
            fn () => DB::table(
                'inventory_negative_regularizations'
            )->insert([
                'organization_id' => $organization->id,
                'inventory_negative_incident_id' => $incident->id,
                'inventory_negative_incident_line_id' => $line->id,
                'regularizing_movement_id' => $draftReceipt->id,
                'applied_by_user_id' => $operator->id,
                'quantity' => '1.000000',
                'applied_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ])
        );
        $this->assertDatabaseRejected(
            fn () => DB::table('inventory_negative_incident_lines')
                ->where('id', $line->id)
                ->update(['pending_deficit' => '1.000000'])
        );

        app(InventoryMovementConfirmer::class)->confirm(
            $draftReceipt,
            $operator
        );
        $regularizationId = DB::table(
            'inventory_negative_regularizations'
        )->value('id');

        $this->assertDatabaseRejected(
            fn () => DB::table('inventory_negative_regularizations')
                ->where('id', $regularizationId)
                ->update(['quantity' => '0.500000'])
        );
        $this->assertDatabaseRejected(
            fn () => DB::table('inventory_negative_regularizations')
                ->where('id', $regularizationId)
                ->delete()
        );
        $this->assertDatabaseRejected(
            fn () => DB::table('inventory_negative_incidents')
                ->where('id', $incident->id)
                ->update([
                    'status' => InventoryNegativeIncidentStatus::Resolved->value,
                    'regularized_at' => now(),
                    'resolved_by_user_id' => $admin->id,
                    'resolved_at' => now(),
                    'resolution_reason' => 'Resolución forzada',
                ])
        );
    }

    public function test_regularization_failure_rolls_back_projection(): void
    {
        $organization = $this->organization();
        $operator = $this->user($organization, UserRole::Operator);
        $admin = $this->user($organization, UserRole::Admin);
        $product = $this->product('Rollback negativo', 'NEG-ROLLBACK');
        $location = $this->location($organization);
        $balance = $this->balance(
            $organization,
            $product,
            $location,
            '0'
        );
        $incident = $this->incident(
            $operator,
            $admin,
            $product,
            $location,
            '2',
            'negative:rollback:1'
        );
        $receipt = $this->receipt(
            $operator,
            $product,
            $location,
            '1',
            'negative:rollback:receipt'
        );

        InventoryNegativeRegularization::creating(
            function (): void {
                throw new DomainException(
                    'Fallo inducido después de proyectar.'
                );
            }
        );

        $this->assertDomainFailure(
            fn () => app(InventoryMovementConfirmer::class)->confirm(
                $receipt,
                $operator
            )
        );

        $this->assertSame('-2.000000', $balance->refresh()->quantity);
        $this->assertSame(
            InventoryMovementStatus::Draft,
            $receipt->refresh()->status
        );
        $this->assertSame(
            '2.000000',
            $incident->lines()->sole()->pending_deficit
        );
        $this->assertDatabaseCount('inventory_negative_regularizations', 0);
    }

    private function organization(): Organization
    {
        return Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();
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

    private function location(Organization $organization): InventoryLocation
    {
        return InventoryLocation::query()
            ->where('organization_id', $organization->id)
            ->orderBy('id')
            ->firstOrFail();
    }

    private function product(
        string $name,
        string $sku,
        int $scale = 0
    ): CatalogProduct {
        $category = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()->firstOrCreate(
                ['slug' => 'negative-regularization'],
                ['name' => 'Negative Regularization', 'active' => true]
            )
        );

        return CatalogProduct::withoutEvents(
            fn () => CatalogProduct::query()->create([
                'product_category_id' => $category->id,
                'sku' => $sku,
                'name' => $name,
                'base_unit_code' => $scale > 0 ? 'l' : 'unit',
                'quantity_scale' => $scale,
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

    private function incident(
        User $operator,
        User $admin,
        CatalogProduct $product,
        InventoryLocation $location,
        string $quantity,
        string $key
    ): InventoryNegativeIncident {
        $movement = $this->movement(
            $operator,
            InventoryMovementType::Issue,
            $product,
            $location,
            $quantity,
            $key,
            InventoryCondition::New
        );
        $request = app(InventoryNegativeRequestManager::class)->request(
            $movement,
            'Solicitud excepcional para regularización',
            $operator
        );
        $override = app(InventoryNegativeOverrideIssuer::class)
            ->issue($request, $admin)
            ->override;

        return app(InventoryMovementConfirmer::class)
            ->confirmWithNegativeOverride(
                $movement,
                $override,
                $operator
            )->incident;
    }

    private function receipt(
        User $operator,
        CatalogProduct $product,
        InventoryLocation $location,
        string $quantity,
        string $key,
        InventoryCondition $condition = InventoryCondition::New
    ): InventoryMovement {
        return $this->movement(
            $operator,
            InventoryMovementType::Receipt,
            $product,
            $location,
            $quantity,
            $key,
            $condition
        );
    }

    private function movement(
        User $actor,
        InventoryMovementType $type,
        CatalogProduct $product,
        InventoryLocation $location,
        string $quantity,
        string $key,
        InventoryCondition $condition
    ): InventoryMovement {
        return app(InventoryMovementCreator::class)->create(
            new InventoryMovementDraftData(
                type: $type,
                effectiveAt: now(),
                reason: 'Movimiento de prueba de regularización',
                idempotencyKey: $key,
                lines: [new InventoryMovementLineData(
                    catalogProductId: $product->id,
                    condition: $condition,
                    enteredQuantity: $quantity,
                    enteredUnitCode: $product->base_unit_code,
                    sourceLocationId: $type === InventoryMovementType::Issue
                        ? $location->id
                        : null,
                    destinationLocationId:
                        $type === InventoryMovementType::Receipt
                            ? $location->id
                            : null
                )]
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

        $this->fail('La operación de dominio debió ser rechazada.');
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
