<?php

namespace Tests\Feature\Security;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\ProductCategory;
use App\Models\TechnicalModel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_creation_records_actor_request_and_new_values(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $response = $this
            ->actingAs($admin)
            ->withHeaders([
                'User-Agent' => 'SRCM Audit Test',
            ])
            ->withServerVariables([
                'REMOTE_ADDR' => '203.0.113.10',
            ])
            ->post(route('brands.store'), [
                'name' => 'Audit Brand',
                'website' => 'https://example.test',
                'description' => 'Created under audit.',
                'active' => true,
            ])
            ->assertRedirect(route('brands.index'));

        $requestId = $response->headers->get('X-Request-ID');

        $this->assertIsString($requestId);
        $this->assertTrue(Str::isUuid($requestId));

        $brand = Brand::query()
            ->where('name', 'Audit Brand')
            ->sole();

        $audit = AuditLog::query()->sole();

        $this->assertSame('created', $audit->event);
        $this->assertSame(Brand::class, $audit->auditable_type);
        $this->assertSame((string) $brand->id, $audit->auditable_id);
        $this->assertSame($admin->id, $audit->user_id);
        $this->assertSame($admin->name, $audit->actor_name);
        $this->assertSame($admin->email, $audit->actor_email);
        $this->assertSame('admin', $audit->actor_role);
        $this->assertSame($requestId, $audit->request_id);
        $this->assertSame('203.0.113.10', $audit->ip_address);
        $this->assertSame('SRCM Audit Test', $audit->user_agent);
        $this->assertSame('brands.store', $audit->route_name);
        $this->assertSame('POST', $audit->http_method);
        $this->assertSame('/brands', $audit->url_path);
        $this->assertNull($audit->old_values);
        $this->assertSame('Audit Brand', $audit->new_values['name']);
        $this->assertTrue($audit->new_values['active']);
    }

    public function test_catalog_update_records_only_changed_values(): void
    {
        $brand = Brand::withoutEvents(
            fn () => Brand::query()->create([
                'name' => 'Original Brand',
                'slug' => 'original-brand',
                'website' => 'https://old.test',
                'description' => 'Original description.',
                'active' => true,
            ])
        );

        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $this->actingAs($admin)
            ->patch(route('brands.update', $brand), [
                'name' => 'Updated Brand',
                'website' => 'https://new.test',
                'description' => 'Original description.',
                'active' => true,
            ])
            ->assertRedirect(route('brands.index'));

        $audit = AuditLog::query()->sole();

        $this->assertSame('updated', $audit->event);

        $this->assertSame(
            [
                'name' => 'Original Brand',
                'website' => 'https://old.test',
            ],
            $audit->old_values
        );

        $this->assertSame(
            [
                'name' => 'Updated Brand',
                'website' => 'https://new.test',
            ],
            $audit->new_values
        );
    }

    public function test_active_toggle_is_recorded_as_deactivation(): void
    {
        $brand = Brand::withoutEvents(
            fn () => Brand::query()->create([
                'name' => 'Toggle Brand',
                'slug' => 'toggle-brand',
                'active' => true,
            ])
        );

        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $this->actingAs($operator)
            ->patch(route('brands.toggle-active', $brand))
            ->assertRedirect(route('brands.index'));

        $audit = AuditLog::query()->sole();

        $this->assertSame('deactivated', $audit->event);
        $this->assertSame(['active' => true], $audit->old_values);
        $this->assertSame(['active' => false], $audit->new_values);
    }

    public function test_categories_and_technical_models_are_audited(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $this->actingAs($admin)
            ->post(route('product-categories.store'), [
                'name' => 'Audited Category',
                'active' => true,
            ])
            ->assertRedirect(route('product-categories.index'));

        $category = ProductCategory::query()
            ->where('name', 'Audited Category')
            ->sole();

        $brand = Brand::withoutEvents(
            fn () => Brand::query()->create([
                'name' => 'Technical Audit Brand',
                'slug' => 'technical-audit-brand',
                'active' => true,
            ])
        );

        $this->actingAs($admin)
            ->post(route('technical-models.store'), [
                'brand_id' => $brand->id,
                'product_category_id' => $category->id,
                'code' => 'AUDIT-100',
                'name' => 'Audited Model',
                'description' => null,
                'active' => true,
            ])
            ->assertRedirect(route('technical-models.index'));

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'created',
            'auditable_type' => ProductCategory::class,
            'auditable_id' => (string) $category->id,
        ]);

        $technicalModel = TechnicalModel::query()
            ->where('code', 'AUDIT-100')
            ->sole();

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'created',
            'auditable_type' => TechnicalModel::class,
            'auditable_id' => (string) $technicalModel->id,
        ]);
    }

    public function test_denied_viewer_action_creates_no_record_or_audit(): void
    {
        $viewer = User::factory()->create([
            'role' => UserRole::Viewer,
        ]);

        $this->actingAs($viewer)
            ->post(route('brands.store'), [
                'name' => 'Forbidden Brand',
                'active' => true,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('brands', [
            'name' => 'Forbidden Brand',
        ]);

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_non_http_model_change_is_recorded_as_system_activity(): void
    {
        $brand = Brand::query()->create([
            'name' => 'System Brand',
            'active' => true,
        ]);

        $audit = AuditLog::query()->sole();

        $this->assertSame((string) $brand->id, $audit->auditable_id);
        $this->assertNull($audit->user_id);
        $this->assertNull($audit->request_id);
        $this->assertNull($audit->ip_address);
        $this->assertNull($audit->route_name);
        $this->assertNull($audit->http_method);
        $this->assertNull($audit->url_path);
    }

    public function test_audit_records_cannot_be_updated(): void
    {
        $audit = AuditLog::query()->create([
            'event' => 'created',
            'auditable_type' => Brand::class,
            'auditable_id' => '1',
            'new_values' => ['name' => 'Original'],
        ]);

        $this->expectException(LogicException::class);

        $audit->update([
            'event' => 'tampered',
        ]);
    }

    public function test_audit_records_cannot_be_deleted(): void
    {
        $audit = AuditLog::query()->create([
            'event' => 'created',
            'auditable_type' => Brand::class,
            'auditable_id' => '1',
            'new_values' => ['name' => 'Original'],
        ]);

        $this->expectException(LogicException::class);

        $audit->delete();
    }
}
