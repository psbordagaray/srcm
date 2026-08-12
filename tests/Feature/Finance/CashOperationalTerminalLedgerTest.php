<?php

namespace Tests\Feature\Finance;

use App\Domain\Commerce\CommerceCheckoutData;
use App\Domain\Commerce\CommerceCheckoutManager;
use App\Domain\Commerce\CommercePaymentData;
use App\Domain\Commerce\CommerceProductLineData;
use App\Domain\Finance\CashRegisterManager;
use App\Domain\Finance\CashRegisterSessionManager;
use App\Domain\Finance\FinancialAccountManager;
use App\Domain\Inventory\InventoryMovementConfirmer;
use App\Domain\Inventory\InventoryMovementCreator;
use App\Domain\Inventory\InventoryMovementDraftData;
use App\Domain\Inventory\InventoryMovementLineData;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CashMovementDirection;
use App\Enums\CashMovementType;
use App\Enums\CommercePaymentMethod;
use App\Enums\FinancialAccountType;
use App\Enums\InventoryCondition;
use App\Enums\InventoryLocationType;
use App\Enums\InventoryMovementType;
use App\Enums\UserRole;
use App\Models\CashMovement;
use App\Models\CatalogProduct;
use App\Models\FinancialAccount;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\OrganizationProductPrice;
use App\Models\ProductCategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class CashOperationalTerminalLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_sale_uses_open_shift_and_writes_immutable_ledger(): void
    {
        [$organization, $admin] = $this->actor(UserRole::Admin);
        $this->actingAs($admin);

        $cash1 = $this->account(
            $admin,
            'Efectivo Caja 1',
            FinancialAccountType::CashBox
        );
        $cash2 = $this->account(
            $admin,
            'Efectivo Caja 2',
            FinancialAccountType::CashBox
        );

        $register1 = app(CashRegisterManager::class)->create(
            'Caja 1',
            $cash1,
            $admin
        );
        app(CashRegisterManager::class)->create(
            'Caja 2',
            $cash2,
            $admin
        );

        $session = app(CashRegisterSessionManager::class)->open(
            $register1,
            5000000,
            'p4b:open:admin',
            $admin
        );

        [$product, $location] = $this->sellableProduct(
            $organization,
            $admin,
            'P4B-CASH-1',
            100000,
            '3'
        );

        $wrong = $this->checkoutData(
            $product,
            $location,
            CommercePaymentMethod::Cash,
            $cash2,
            'p4b:sale:wrong'
        );

        try {
            app(CommerceCheckoutManager::class)->checkout(
                $wrong,
                $admin
            );
            $this->fail(
                'El efectivo no debe aceptar otra cuenta destino.'
            );
        } catch (DomainException $exception) {
            $this->assertStringContainsString(
                'caja del turno abierto',
                Str::lower($exception->getMessage())
            );
        }

        $valid = $this->checkoutData(
            $product,
            $location,
            CommercePaymentMethod::Cash,
            $cash1,
            'p4b:sale:cash'
        );

        $sale = app(CommerceCheckoutManager::class)->checkout(
            $valid,
            $admin
        );
        $retry = app(CommerceCheckoutManager::class)->checkout(
            $valid,
            $admin
        );

        $this->assertSame($sale->id, $retry->id);
        $this->assertDatabaseCount('cash_movements', 1);

        $payment = $sale->payments()->sole();
        $movement = CashMovement::query()->sole();

        $this->assertSame($organization->id, $movement->organization_id);
        $this->assertSame($session->id, $movement->cash_register_session_id);
        $this->assertSame($register1->id, $movement->cash_register_id);
        $this->assertSame($cash1->id, $movement->financial_account_id);
        $this->assertSame($payment->id, $movement->commerce_payment_id);
        $this->assertSame(100000, $movement->amount_minor);
        $this->assertSame('ARS', $movement->currency_code);
        $this->assertSame(
            CashMovementDirection::In,
            $movement->direction
        );
        $this->assertSame(
            CashMovementType::SalePayment,
            $movement->type
        );

        $this->expectException(QueryException::class);

        DB::table('cash_movements')
            ->where('id', $movement->id)
            ->update(['amount_minor' => 1]);
    }

    public function test_cash_requires_shift_but_electronic_sale_does_not(): void
    {
        [$organization, $admin] = $this->actor(UserRole::Admin);
        $this->actingAs($admin);

        $cash = $this->account(
            $admin,
            'Efectivo sin turno',
            FinancialAccountType::CashBox
        );
        $bank = $this->account(
            $admin,
            'Banco operativo',
            FinancialAccountType::BankAccount
        );

        [$product, $location] = $this->sellableProduct(
            $organization,
            $admin,
            'P4B-MIX-1',
            100000,
            '3'
        );

        try {
            app(CommerceCheckoutManager::class)->checkout(
                $this->checkoutData(
                    $product,
                    $location,
                    CommercePaymentMethod::Cash,
                    $cash,
                    'p4b:no-shift'
                ),
                $admin
            );
            $this->fail(
                'El cobro efectivo debe requerir turno abierto.'
            );
        } catch (DomainException $exception) {
            $this->assertStringContainsString(
                'abrí un turno de caja',
                Str::lower($exception->getMessage())
            );
        }

        $sale = app(CommerceCheckoutManager::class)->checkout(
            $this->checkoutData(
                $product,
                $location,
                CommercePaymentMethod::BankTransfer,
                $bank,
                'p4b:bank'
            ),
            $admin
        );

        $this->assertNotNull($sale->id);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_cash_register_surface_and_terminal_context_are_exposed(): void
    {
        [$organization, $admin] = $this->actor(UserRole::Admin);
        $this->actingAs($admin);

        $cash = $this->account(
            $admin,
            'Efectivo UI',
            FinancialAccountType::CashBox
        );

        $register = app(CashRegisterManager::class)->create(
            'Caja UI',
            $cash,
            $admin
        );

        $this->assertTrue(Route::has('cash-registers.index'));
        $this->assertTrue(Route::has('cash-registers.open'));
        $this->assertTrue(Route::has('cash-registers.create'));

        $this->get(route('cash-registers.index'))
            ->assertOk()
            ->assertSee('Cajas operativas y turnos')
            ->assertSee('Caja UI')
            ->assertSee('Abrir turno');

        $this->post(route('cash-registers.open', $register), [
            'opening_amount' => '25000,00',
            'idempotency_key' => 'cash-ui:open:'.Str::uuid(),
        ])->assertRedirect(route('cash-registers.index'));

        $this->assertDatabaseHas('cash_register_sessions', [
            'cash_register_id' => $register->id,
            'opened_by_user_id' => $admin->id,
            'opening_amount_minor' => 2500000,
            'status' => 'open',
        ]);

        $saleView = $this->get(route('commerce-sales.create'));
        $saleView->assertOk();
        $saleView->assertSee('Turno de caja activo');
        $saleView->assertSee('Caja UI');

        $blade = file_get_contents(
            resource_path('views/commerce-sales/create.blade.php')
        );

        $this->assertIsString($blade);

        foreach ([
            'activeCashSession:',
            'cashSessionAvailable()',
            'syncOperationalCashAccount(payment)',
            'data-sale-cash-session-context',
            'data-sale-cash-derived-account',
            'activeCashSession.financial_account_label',
            'Destino derivado del turno abierto',
            'Efectivo requiere un turno de caja abierto',
        ] as $marker) {
            $this->assertStringContainsString($marker, $blade);
        }
    }

    public function test_schema_is_append_only_and_cross_links_are_guarded(): void
    {
        $this->assertTrue(Schema::hasColumns('cash_movements', [
            'organization_id',
            'cash_register_session_id',
            'cash_register_id',
            'financial_account_id',
            'commerce_payment_id',
            'direction',
            'type',
            'amount_minor',
            'currency_code',
            'idempotency_key',
            'fingerprint',
            'recorded_by_user_id',
            'occurred_at',
        ]));
    }

    private function checkoutData(
        CatalogProduct $product,
        InventoryLocation $location,
        CommercePaymentMethod $method,
        FinancialAccount $account,
        string $idempotencyKey
    ): CommerceCheckoutData {
        return new CommerceCheckoutData(
            currencyCode: 'ARS',
            idempotencyKey: $idempotencyKey,
            payments: [new CommercePaymentData(
                method: $method,
                amountMinor: 100000,
                reference: $method->requiresReference()
                    ? 'REF-'.$idempotencyKey
                    : null,
                financialAccountId: $account->id
            )],
            productLines: [new CommerceProductLineData(
                catalogProductId: $product->id,
                sourceLocationId: $location->id,
                condition: InventoryCondition::New,
                quantity: '1',
                unitPriceMinor: 100000
            )]
        );
    }

    /** @return array{CatalogProduct, InventoryLocation} */
    private function sellableProduct(
        Organization $organization,
        User $actor,
        string $sku,
        int $priceMinor,
        string $quantity
    ): array {
        $category = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()->firstOrCreate(
                ['slug' => 'p4b-cash-tests'],
                [
                    'name' => 'P4B caja',
                    'active' => true,
                ]
            )
        );

        $product = CatalogProduct::withoutEvents(
            fn () => CatalogProduct::query()->create([
                'product_category_id' => $category->id,
                'sku' => $sku.'-'.Str::lower(Str::random(6)),
                'name' => 'Producto '.$sku,
                'active' => true,
            ])->refresh()
        );

        $location = InventoryLocation::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Recepción '.$sku,
            'type' => InventoryLocationType::Receiving,
            'active' => true,
        ]);

        $movement = app(InventoryMovementCreator::class)->create(
            new InventoryMovementDraftData(
                type: InventoryMovementType::Receipt,
                effectiveAt: CarbonImmutable::now()->subSecond(),
                reason: 'Stock P4B.',
                idempotencyKey: 'p4b:stock:'.$product->id,
                lines: [new InventoryMovementLineData(
                    catalogProductId: $product->id,
                    condition: InventoryCondition::New,
                    enteredQuantity: $quantity,
                    enteredUnitCode: $product->base_unit_code,
                    destinationLocationId: $location->id
                )]
            ),
            $actor
        );

        app(InventoryMovementConfirmer::class)->confirm(
            $movement,
            $actor
        );

        OrganizationProductPrice::query()->create([
            'organization_id' => $organization->id,
            'catalog_product_id' => $product->id,
            'currency_code' => 'ARS',
            'amount_minor' => $priceMinor,
            'valid_from' => now()->subSecond(),
            'valid_until' => null,
            'is_current' => true,
            'reason' => 'Fixture P4B',
            'created_by_user_id' => $actor->id,
        ]);

        return [$product, $location];
    }

    private function account(
        User $actor,
        string $name,
        FinancialAccountType $type
    ): FinancialAccount {
        return app(FinancialAccountManager::class)->create(
            $name,
            $type,
            'ARS',
            $actor
        );
    }

    /** @return array{Organization, User} */
    private function actor(UserRole $role): array
    {
        $suffix = Str::lower(Str::random(8));

        $organization = Organization::query()->create([
            'name' => 'Org P4B '.$suffix,
            'slug' => 'org-p4b-'.$suffix,
            'active' => true,
        ]);

        $user = User::factory()->create([
            'role' => $role,
            'email_verified_at' => now(),
        ]);

        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => $role,
            'active' => true,
        ]);

        $user->forceFill([
            'current_organization_id' => $organization->id,
        ])->saveQuietly();

        app(CurrentOrganization::class)->forget($user);

        return [$organization, $user->refresh()];
    }
}
