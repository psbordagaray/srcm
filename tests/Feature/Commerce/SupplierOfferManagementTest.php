<?php

namespace Tests\Feature\Commerce;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\BusinessParty;
use App\Models\CatalogProduct;
use App\Models\ProductCategory;
use App\Models\Supplier;
use App\Models\SupplierOffer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SupplierOfferManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_users_read_offers_and_only_managers_see_actions(): void
    {
        $offer = $this->offerWithoutAudit();
        $viewer = $this->user(UserRole::Viewer);
        $operator = $this->user(UserRole::Operator);

        $this->actingAs($viewer)
            ->get(route('supplier-offers.index'))
            ->assertOk()
            ->assertSee($offer->product->name)
            ->assertDontSee(route('supplier-offers.create'), false);

        $this->actingAs($viewer)
            ->get(route('supplier-offers.show', $offer))
            ->assertOk()
            ->assertDontSee(route('supplier-offers.edit', $offer), false);

        $this->actingAs($operator)
            ->get(route('supplier-offers.index'))
            ->assertOk()
            ->assertSee(route('supplier-offers.create'), false);
    }

    public function test_manager_creates_normalized_searchable_offer_with_audit(): void
    {
        [$supplier, $product] = $this->references();
        $manager = $this->user(UserRole::Operator);

        $response = $this->actingAs($manager)
            ->post(route('supplier-offers.store'), [
                'supplier_id' => $supplier->id,
                'catalog_product_id' => $product->id,
                'supplier_code' => ' prov - 001 ',
                'published_description' => 'Control publicado',
                'cost_amount' => '1234,50',
                'currency' => 'ars',
                'availability_status' => 'available',
                'source_url' => 'proveedor.example/oferta',
                'checked_at' => now()->toDateString(),
                'commercial_terms' => 'Pago contado.',
                'active' => '1',
            ]);

        $offer = SupplierOffer::query()->sole();

        $response->assertRedirect(route('supplier-offers.show', $offer));

        $this->assertSame('PROV - 001', $offer->supplier_code);
        $this->assertSame('PROV001', $offer->normalized_supplier_code);
        $this->assertSame('1234.50', $offer->cost_amount);
        $this->assertSame('ARS', $offer->currency);
        $this->assertSame(
            'https://proveedor.example/oferta',
            $offer->source_url
        );

        $this->actingAs($manager)
            ->get(route('supplier-offers.index', ['search' => 'prov001']))
            ->assertOk()
            ->assertSee($product->name);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => SupplierOffer::class,
            'auditable_id' => $offer->id,
            'event' => 'created',
            'user_id' => $manager->id,
        ]);
    }

    public function test_duplicate_and_cross_product_codes_are_blocked_apb(): void
    {
        $offer = $this->offerWithoutAudit();
        $otherProduct = $this->productWithoutAudit('OTRO-001', 'Otro producto');
        $manager = $this->user(UserRole::Operator);
        $before = AuditLog::query()->count();

        $this->actingAs($manager)
            ->post(route('supplier-offers.store'), [
                'supplier_id' => $offer->supplier_id,
                'catalog_product_id' => $offer->catalog_product_id,
                'supplier_code' => 'prov001',
                'availability_status' => 'available',
                'checked_at' => now()->toDateString(),
                'active' => '1',
            ])
            ->assertSessionHasErrors('supplier_code');

        $this->actingAs($manager)
            ->post(route('supplier-offers.store'), [
                'supplier_id' => $offer->supplier_id,
                'catalog_product_id' => $otherProduct->id,
                'supplier_code' => 'PROV-001',
                'availability_status' => 'unknown',
                'checked_at' => now()->toDateString(),
                'active' => '1',
            ])
            ->assertSessionHasErrors('supplier_code');

        $this->assertDatabaseCount('supplier_offers', 1);
        $this->assertSame($before, AuditLog::query()->count());
    }

    public function test_same_product_accepts_different_suppliers(): void
    {
        $offer = $this->offerWithoutAudit();
        $otherSupplier = $this->supplierWithoutAudit(
            'Proveedor Alternativo',
            '30-44444444-4'
        );
        $manager = $this->user(UserRole::Operator);

        $this->actingAs($manager)
            ->post(route('supplier-offers.store'), [
                'supplier_id' => $otherSupplier->id,
                'catalog_product_id' => $offer->catalog_product_id,
                'supplier_code' => 'PROV-001',
                'availability_status' => 'limited',
                'checked_at' => now()->toDateString(),
                'active' => '1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('supplier_offers', 2);
    }

    public function test_cost_and_freshness_rules_are_apb(): void
    {
        [$supplier, $product] = $this->references();
        $manager = $this->user(UserRole::Operator);

        $this->actingAs($manager)
            ->post(route('supplier-offers.store'), [
                'supplier_id' => $supplier->id,
                'catalog_product_id' => $product->id,
                'cost_amount' => '100.00',
                'availability_status' => 'available',
                'checked_at' => now()->addDay()->toDateString(),
                'active' => '1',
            ])
            ->assertSessionHasErrors(['currency', 'checked_at']);

        $this->assertDatabaseCount('supplier_offers', 0);
    }

    public function test_manager_updates_and_toggles_with_audit(): void
    {
        $offer = $this->offerWithoutAudit();
        $manager = $this->user(UserRole::Admin);

        $this->actingAs($manager)
            ->put(route('supplier-offers.update', $offer), [
                'supplier_id' => $offer->supplier_id,
                'catalog_product_id' => $offer->catalog_product_id,
                'supplier_code' => 'PROV-002',
                'published_description' => 'Actualizada',
                'cost_amount' => '2000',
                'currency' => 'usd',
                'availability_status' => 'limited',
                'checked_at' => now()->toDateString(),
                'commercial_terms' => 'Transferencia.',
                'active' => '1',
            ])
            ->assertRedirect(route('supplier-offers.show', $offer));

        $offer->refresh();

        $this->assertSame('PROV-002', $offer->supplier_code);
        $this->assertSame('USD', $offer->currency);

        $this->actingAs($manager)
            ->patch(route('supplier-offers.toggle-active', $offer))
            ->assertSessionHasNoErrors();

        $this->assertFalse($offer->fresh()->active);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => SupplierOffer::class,
            'auditable_id' => $offer->id,
            'event' => 'updated',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => SupplierOffer::class,
            'auditable_id' => $offer->id,
            'event' => 'deactivated',
        ]);
    }

    public function test_viewer_cannot_mutate_and_destroy_is_not_exposed(): void
    {
        $offer = $this->offerWithoutAudit();
        $viewer = $this->user(UserRole::Viewer);

        $this->actingAs($viewer)
            ->get(route('supplier-offers.create'))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->get(route('supplier-offers.edit', $offer))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->patch(route('supplier-offers.toggle-active', $offer))
            ->assertForbidden();

        $this->assertFalse(Route::has('supplier-offers.destroy'));
    }

    private function offerWithoutAudit(): SupplierOffer
    {
        [$supplier, $product] = $this->references();

        return SupplierOffer::withoutEvents(
            fn () => SupplierOffer::query()->create([
                'supplier_id' => $supplier->id,
                'catalog_product_id' => $product->id,
                'supplier_code' => 'PROV-001',
                'published_description' => 'Oferta inicial',
                'cost_amount' => '1000.00',
                'currency' => 'ARS',
                'availability_status' => 'available',
                'source_url' => 'https://example.test/oferta',
                'checked_at' => now()->toDateString(),
                'commercial_terms' => 'Contado.',
                'active' => true,
            ])
        )->load(['supplier.party', 'product']);
    }

    private function references(): array
    {
        return [
            $this->supplierWithoutAudit(
                'Proveedor Inicial',
                '30-22222222-2'
            ),
            $this->productWithoutAudit(
                'PROD-001',
                'Producto Inicial'
            ),
        ];
    }

    private function supplierWithoutAudit(
        string $name,
        string $taxId
    ): Supplier {
        $party = BusinessParty::withoutEvents(
            fn () => BusinessParty::query()->create([
                'party_type' => 'organization',
                'name' => $name,
                'tax_id' => $taxId,
                'email' => strtolower(str_replace(' ', '.', $name))
                    .'@example.test',
            ])
        );

        return Supplier::withoutEvents(
            fn () => Supplier::query()->create([
                'business_party_id' => $party->id,
                'active' => true,
            ])
        )->load('party');
    }

    private function productWithoutAudit(
        string $sku,
        string $name
    ): CatalogProduct {
        $category = ProductCategory::withoutEvents(
            fn () => ProductCategory::query()->firstOrCreate(
                ['slug' => 'controles'],
                ['name' => 'Controles', 'active' => true]
            )
        );

        return CatalogProduct::withoutEvents(
            fn () => CatalogProduct::query()->create([
                'product_category_id' => $category->id,
                'sku' => $sku,
                'name' => $name,
                'active' => true,
            ])
        );
    }

    private function user(UserRole $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
