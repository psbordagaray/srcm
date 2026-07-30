<?php

namespace Tests\Feature\Security;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Compatibility;
use App\Models\Entity;
use App\Models\EntityType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuditDisplayTimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.timezone' => 'UTC',
            'app.display_timezone' =>
                'America/Argentina/Buenos_Aires',
        ]);
    }

    public function test_audit_displays_utc_record_in_buenos_aires_time(): void
    {
        $admin = $this->admin();

        $this->audit([
            'created_at' => '2026-07-30 12:19:26',
        ]);

        $this->actingAs($admin)
            ->get(route('audit-logs.index'))
            ->assertOk()
            ->assertSee('30/07/2026 09:19:26')
            ->assertSee(
                'America/Argentina/Buenos_Aires'
            );
    }

    public function test_date_filter_uses_local_day_boundaries(): void
    {
        $admin = $this->admin();

        $previousLocalDay = $this->audit([
            'request_id' =>
                '11111111-1111-4111-8111-111111111111',
            'created_at' => '2026-07-30 02:30:00',
        ]);

        $selectedLocalDay = $this->audit([
            'request_id' =>
                '22222222-2222-4222-8222-222222222222',
            'created_at' => '2026-07-30 03:30:00',
        ]);

        $response = $this->actingAs($admin)->get(
            route('audit-logs.index', [
                'date_from' => '2026-07-30',
                'date_to' => '2026-07-30',
            ])
        );

        $response
            ->assertOk()
            ->assertSee($selectedLocalDay->request_id)
            ->assertDontSee(
                $previousLocalDay->request_id
            );
    }

    public function test_legacy_compatibility_is_explained_without_fake_event(): void
    {
        $admin = $this->admin();

        $entityType = EntityType::query()->create([
            'name' => 'Tipo de prueba',
            'slug' => 'audit-timezone-test',
            'description' => null,
            'active' => true,
        ]);

        $left = Entity::withoutEvents(
            fn () => Entity::query()->create([
                'uuid' => (string) Str::uuid(),
                'name' => 'Izquierda',
                'entity_type_id' => $entityType->id,
                'active' => true,
            ])
        );

        $right = Entity::withoutEvents(
            fn () => Entity::query()->create([
                'uuid' => (string) Str::uuid(),
                'name' => 'Derecha',
                'entity_type_id' => $entityType->id,
                'active' => true,
            ])
        );

        Compatibility::withoutEvents(
            fn () => Compatibility::query()->create([
                'left_entity_id' => $left->id,
                'right_entity_id' => $right->id,
                'relationship_type' =>
                    'compatible_with',
                'confidence' => 90,
                'source' => null,
                'evidence' => null,
                'active' => true,
            ])
        );

        $this->actingAs($admin)
            ->get(route('audit-logs.index'))
            ->assertOk()
            ->assertSee(
                'compatibilidad histórica'
            );

        $this->assertDatabaseCount('audit_logs', 0);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function audit(
        array $overrides = []
    ): AuditLog {
        return AuditLog::query()->create(
            array_merge([
                'request_id' => (string) Str::uuid(),
                'user_id' => null,
                'actor_name' => 'Sistema de prueba',
                'actor_email' => 'audit@example.test',
                'actor_role' =>
                    UserRole::Admin->value,
                'event' => 'created',
                'auditable_type' => Brand::class,
                'auditable_id' => '1',
                'old_values' => [],
                'new_values' => ['name' => 'Prueba'],
                'ip_address' => '127.0.0.1',
                'user_agent' => 'SRCM Test',
                'route_name' => 'brands.store',
                'http_method' => 'POST',
                'url_path' => '/brands',
                'created_at' =>
                    '2026-07-30 12:19:26',
            ], $overrides)
        );
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role' => UserRole::Admin,
            'email_verified_at' => now(),
        ]);
    }
}
