<?php

namespace Tests\Feature\Catalog;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\CatalogProduct;
use App\Models\Entity;
use App\Models\Identifier;
use App\Models\Manufacturer;
use App\Models\ProductCategory;
use App\Models\User;
use Database\Seeders\KnowledgeFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CatalogProductManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(KnowledgeFoundationSeeder::class);
    }

    public function test_verified_users_read_catalog_and_only_managers_see_actions(): void
    {
        [$category, $brand, $manufacturer] = $this->catalogFoundation();

        CatalogProduct::query()->create([
            'product_category_id' => $category->id,
            'brand_id' => $brand->id,
            'manufacturer_id' => $manufacturer->id,
            'sku' => 'TEST-001',
            'name' => 'Producto de lectura',
            'active' => true,
        ]);

        $viewer = $this->user(UserRole::Viewer);
        $admin = $this->user(UserRole::Admin);

        $viewerResponse = $this
            ->actingAs($viewer)
            ->get(route('products.index'))
            ->assertOk()
            ->assertSee('Producto de lectura')
            ->assertSee('Consulta')
            ->assertDontSee(route('products.create'), false)
            ->assertDontSee('Editar');

        $viewerResponse->assertSee('Ficha');

        $this->actingAs($admin)
            ->get(route('products.index'))
            ->assertOk()
            ->assertSee(route('products.create'), false)
            ->assertSee('Editar');
    }

    public function test_manager_creates_searchable_product_and_knowledge_atomically(): void
    {
        [$category, $brand, $manufacturer] = $this->catalogFoundation();
        AuditLog::query()->delete();

        $admin = $this->user(UserRole::Admin);

        $response = $this
            ->actingAs($admin)
            ->post(route('products.store'), [
                'product_category_id' => $category->id,
                'brand_id' => $brand->id,
                'manufacturer_id' => $manufacturer->id,
                'sku' => ' akb-75095308 ',
                'name' => '  Control remoto LG AKB75095308  ',
                'description' => 'Control remoto original.',
                'active' => '1',
            ]);

        $product = CatalogProduct::query()->firstOrFail();
        $product->load([
            'knowledgeEntity.entityType',
            'knowledgeIdentifier.identifierType',
        ]);

        $response->assertRedirect(route(
            'entities.show',
            $product->knowledgeEntity->uuid
        ));

        $this->assertSame('AKB-75095308', $product->sku);
        $this->assertSame('akb75095308', $product->normalized_sku);
        $this->assertSame(
            'Control remoto LG AKB75095308',
            $product->name
        );
        $this->assertSame(
            'catalog-product',
            $product->knowledgeEntity->entityType->slug
        );
        $this->assertSame(
            'main-code',
            $product->knowledgeIdentifier->identifierType->slug
        );
        $this->assertSame(
            'AKB-75095308',
            $product->knowledgeIdentifier->value
        );

        $this->actingAs($admin)
            ->get(route('products.index', ['search' => '75095308']))
            ->assertOk()
            ->assertSee('Control remoto LG AKB75095308');

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => CatalogProduct::class,
            'auditable_id' => $product->id,
            'event' => 'created',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Entity::class,
            'auditable_id' => $product->knowledge_entity_id,
            'event' => 'created',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Identifier::class,
            'auditable_id' => $product->knowledge_identifier_id,
            'event' => 'created',
        ]);
    }

    public function test_equivalent_sku_duplicate_is_rejected_without_audit(): void
    {
        [$category, $brand, $manufacturer] = $this->catalogFoundation();
        $admin = $this->user(UserRole::Admin);

        $this->actingAs($admin)->post(route('products.store'), [
            'product_category_id' => $category->id,
            'brand_id' => $brand->id,
            'manufacturer_id' => $manufacturer->id,
            'sku' => 'AKB75095308',
            'name' => 'Control remoto LG',
            'active' => '1',
        ])->assertRedirect();

        AuditLog::query()->delete();

        $this->actingAs($admin)
            ->from(route('products.create'))
            ->post(route('products.store'), [
                'product_category_id' => $category->id,
                'brand_id' => $brand->id,
                'manufacturer_id' => $manufacturer->id,
                'sku' => 'akb-750 95308',
                'name' => 'Otro nombre',
                'active' => '1',
            ])
            ->assertRedirect(route('products.create'))
            ->assertSessionHasErrors('sku');

        $this->assertSame(1, CatalogProduct::query()->count());
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_probable_duplicate_name_is_rejected(): void
    {
        [$category, $brand, $manufacturer] = $this->catalogFoundation();
        $admin = $this->user(UserRole::Admin);

        $this->actingAs($admin)->post(route('products.store'), [
            'product_category_id' => $category->id,
            'brand_id' => $brand->id,
            'manufacturer_id' => $manufacturer->id,
            'sku' => 'SKU-001',
            'name' => 'Control remoto voz IA',
            'active' => '1',
        ])->assertRedirect();

        $this->actingAs($admin)
            ->from(route('products.create'))
            ->post(route('products.store'), [
                'product_category_id' => $category->id,
                'brand_id' => $brand->id,
                'manufacturer_id' => $manufacturer->id,
                'sku' => 'SKU-002',
                'name' => 'control-remoto voz IA',
                'active' => '1',
            ])
            ->assertRedirect(route('products.create'))
            ->assertSessionHasErrors('name');

        $this->assertSame(1, CatalogProduct::query()->count());
    }

    public function test_manager_updates_and_toggles_product_with_knowledge_and_audit(): void
    {
        [$category, $brand, $manufacturer] = $this->catalogFoundation();
        $admin = $this->user(UserRole::Admin);

        $this->actingAs($admin)->post(route('products.store'), [
            'product_category_id' => $category->id,
            'brand_id' => $brand->id,
            'manufacturer_id' => $manufacturer->id,
            'sku' => 'SKU-OLD',
            'name' => 'Nombre anterior',
            'active' => '1',
        ])->assertRedirect();

        $product = CatalogProduct::query()->firstOrFail();
        AuditLog::query()->delete();

        $this->actingAs($admin)
            ->put(route('products.update', $product), [
                'product_category_id' => $category->id,
                'brand_id' => $brand->id,
                'manufacturer_id' => $manufacturer->id,
                'sku' => 'SKU-NEW',
                'name' => 'Nombre actualizado',
                'description' => 'Descripción actualizada.',
                'active' => '1',
            ])
            ->assertRedirect();

        $product->refresh()->load([
            'knowledgeEntity',
            'knowledgeIdentifier',
        ]);

        $this->assertSame('SKU-NEW', $product->sku);
        $this->assertSame(
            'Nombre actualizado',
            $product->knowledgeEntity->name
        );
        $this->assertSame(
            'SKU-NEW',
            $product->knowledgeIdentifier->value
        );

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => CatalogProduct::class,
            'auditable_id' => $product->id,
            'event' => 'updated',
        ]);

        $this->actingAs($admin)
            ->patch(route('products.toggle-active', $product))
            ->assertRedirect(route('products.index'));

        $product->refresh()->load('knowledgeEntity');

        $this->assertFalse($product->active);
        $this->assertFalse($product->knowledgeEntity->active);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => CatalogProduct::class,
            'auditable_id' => $product->id,
            'event' => 'deactivated',
        ]);
    }

    public function test_viewer_cannot_mutate_products(): void
    {
        [$category, $brand, $manufacturer] = $this->catalogFoundation();
        $viewer = $this->user(UserRole::Viewer);

        $this->actingAs($viewer)
            ->get(route('products.create'))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->post(route('products.store'), [
                'product_category_id' => $category->id,
                'brand_id' => $brand->id,
                'manufacturer_id' => $manufacturer->id,
                'sku' => 'BLOCKED',
                'name' => 'Producto bloqueado',
                'active' => '1',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('catalog_products', [
            'sku' => 'BLOCKED',
        ]);
    }

    public function test_routes_are_apb_and_destroy_is_not_exposed(): void
    {
        $this->assertTrue(Route::has('products.index'));
        $this->assertTrue(Route::has('products.show'));
        $this->assertTrue(Route::has('products.create'));
        $this->assertTrue(Route::has('products.store'));
        $this->assertTrue(Route::has('products.edit'));
        $this->assertTrue(Route::has('products.update'));
        $this->assertTrue(Route::has('products.toggle-active'));
        $this->assertFalse(Route::has('products.destroy'));
    }

    /**
     * @return array{ProductCategory, Brand, Manufacturer}
     */
    private function catalogFoundation(): array
    {
        $category = ProductCategory::query()->create([
            'name' => 'Controles remotos',
            'description' => 'Controles originales y alternativos.',
            'active' => true,
        ]);

        $brand = Brand::query()->create([
            'name' => 'LG',
            'active' => true,
        ]);

        $manufacturer = Manufacturer::query()->create([
            'name' => 'LG Electronics',
            'active' => true,
        ]);

        return [$category, $brand, $manufacturer];
    }

    private function user(UserRole $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
