<?php

namespace Tests\Feature\Security;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuditLogViewerTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_can_view_audit(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $viewer = User::factory()->create([
            'role' => UserRole::Viewer,
        ]);

        $this->assertTrue(
            Gate::forUser($admin)->allows('view-audit')
        );

        $this->assertFalse(
            Gate::forUser($operator)->allows('view-audit')
        );

        $this->assertFalse(
            Gate::forUser($viewer)->allows('view-audit')
        );
    }

    public function test_audit_routes_are_verified_admin_read_only_routes(): void
    {
        foreach (['audit-logs.index', 'audit-logs.show'] as $routeName) {
            $route = app('router')
                ->getRoutes()
                ->getByName($routeName);

            $this->assertNotNull($route);
            $this->assertContains('auth', $route->gatherMiddleware());
            $this->assertContains('verified', $route->gatherMiddleware());
            $this->assertContains(
                'can:view-audit',
                $route->gatherMiddleware()
            );

            $this->assertEqualsCanonicalizing(
                ['GET', 'HEAD'],
                $route->methods()
            );
        }

        $this->assertFalse(Route::has('audit-logs.create'));
        $this->assertFalse(Route::has('audit-logs.store'));
        $this->assertFalse(Route::has('audit-logs.edit'));
        $this->assertFalse(Route::has('audit-logs.update'));
        $this->assertFalse(Route::has('audit-logs.destroy'));
    }

    public function test_guest_and_unverified_admin_cannot_reach_audit(): void
    {
        $this->get(route('audit-logs.index'))
            ->assertRedirect(route('login'));

        $admin = User::factory()->unverified()->create([
            'role' => UserRole::Admin,
        ]);

        $this->actingAs($admin)
            ->get(route('audit-logs.index'))
            ->assertRedirect(route('verification.notice'));
    }

    public function test_operator_and_viewer_receive_forbidden(): void
    {
        foreach ([UserRole::Operator, UserRole::Viewer] as $role) {
            $user = User::factory()->create([
                'role' => $role,
            ]);

            $this->actingAs($user)
                ->get(route('audit-logs.index'))
                ->assertForbidden();
        }
    }

    public function test_admin_can_view_index_and_detail(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $audit = $this->createAudit([
            'actor_name' => $admin->name,
            'actor_email' => $admin->email,
            'actor_role' => UserRole::Admin->value,
            'user_id' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(route('audit-logs.index'))
            ->assertOk()
            ->assertSee('Auditoría del sistema')
            ->assertSee($audit->request_id);

        $this->actingAs($admin)
            ->get(route('audit-logs.show', $audit))
            ->assertOk()
            ->assertSee('Valores anteriores')
            ->assertSee('Valores posteriores')
            ->assertSee('Original')
            ->assertSee('Actualizado');
    }

    public function test_admin_can_filter_by_request_event_entity_user_and_dates(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $matching = $this->createAudit([
            'request_id' => '11111111-1111-4111-8111-111111111111',
            'user_id' => $admin->id,
            'actor_name' => $admin->name,
            'actor_email' => $admin->email,
            'actor_role' => UserRole::Admin->value,
            'event' => 'updated',
            'auditable_type' => Brand::class,
            'created_at' => '2026-07-20 12:00:00',
        ]);

        $this->createAudit([
            'request_id' => '22222222-2222-4222-8222-222222222222',
            'event' => 'created',
            'auditable_type' => ProductCategory::class,
            'created_at' => '2026-06-01 12:00:00',
        ]);

        $response = $this->actingAs($admin)->get(
            route('audit-logs.index', [
                'request_id' => '11111111',
                'event' => 'updated',
                'entity' => 'brand',
                'user_id' => $admin->id,
                'date_from' => '2026-07-01',
                'date_to' => '2026-07-31',
            ])
        );

        $response
            ->assertOk()
            ->assertSee($matching->request_id)
            ->assertDontSee('22222222-2222-4222-8222-222222222222');
    }

    public function test_audit_detail_escapes_stored_values(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $audit = $this->createAudit([
            'new_values' => [
                'description' => '<script>alert("xss")</script>',
            ],
        ]);

        $this->actingAs($admin)
            ->get(route('audit-logs.show', $audit))
            ->assertOk()
            ->assertDontSee(
                '<script>alert("xss")</script>',
                false
            )
            ->assertSee(
                '&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;',
                false
            );
    }

    public function test_audit_navigation_is_visible_only_to_admin(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('audit-logs.index'), false)
            ->assertSee('Auditoría');

        $this->actingAs($operator)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('audit-logs.index'), false);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createAudit(array $overrides = []): AuditLog
    {
        return AuditLog::query()->create(array_merge([
            'request_id' => (string) Str::uuid(),
            'user_id' => null,
            'actor_name' => 'Sistema de prueba',
            'actor_email' => 'audit@example.test',
            'actor_role' => UserRole::Admin->value,
            'event' => 'updated',
            'auditable_type' => Brand::class,
            'auditable_id' => '10',
            'old_values' => ['name' => 'Original'],
            'new_values' => ['name' => 'Actualizado'],
            'ip_address' => '203.0.113.20',
            'user_agent' => 'SRCM Viewer Test',
            'route_name' => 'brands.update',
            'http_method' => 'PATCH',
            'url_path' => '/brands/10',
            'created_at' => '2026-07-20 12:00:00',
        ], $overrides));
    }
}
