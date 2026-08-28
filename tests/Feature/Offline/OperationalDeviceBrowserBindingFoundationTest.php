<?php

namespace Tests\Feature\Offline;

use App\Domain\Device\OperationalDeviceBrowserBindingManager;
use App\Domain\Device\OperationalDeviceBrowserBindingResolver;
use App\Domain\Device\OperationalDeviceRegistry;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\OperationalDeviceCapability;
use App\Enums\UserRole;
use App\Models\OperationalDeviceBrowserBinding;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class OperationalDeviceBrowserBindingFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_issues_hash_only_revocable_expiring_binding_and_rotation_is_audited(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse('2026-08-28 18:00:00')
        );

        $organization = $this->organization('Binding');
        $admin = $this->actor(
            $organization,
            UserRole::Admin
        );
        $this->actingAs($admin);

        $device = app(OperationalDeviceRegistry::class)
            ->register(
                $admin,
                'POS principal',
                [
                    OperationalDeviceCapability::RestrictedOfflineReplay,
                ]
            );

        $manager = app(
            OperationalDeviceBrowserBindingManager::class
        );

        $first = $manager->issue($admin, $device);

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/D',
            $first->token
        );
        $this->assertTrue(
            Str::isUuid($first->binding->public_id)
        );
        $this->assertSame(
            hash('sha256', $first->token),
            $first->binding->token_hash
        );
        $this->assertNotSame(
            $first->token,
            $first->binding->token_hash
        );
        $this->assertSame(
            90,
            (int) $first->binding->issued_at->diffInDays(
                $first->binding->expires_at
            )
        );

        $this->assertFalse(
            Schema::hasColumn(
                'operational_device_browser_bindings',
                'token'
            )
        );
        $this->assertFalse(
            Schema::hasColumn(
                'operational_device_browser_bindings',
                'credential'
            )
        );

        $second = $manager->issue($admin, $device);

        $this->assertNotSame(
            $first->token,
            $second->token
        );
        $this->assertNotSame(
            $first->binding->public_id,
            $second->binding->public_id
        );
        $this->assertNotNull(
            $first->binding->fresh()->revoked_at
        );
        $this->assertNull(
            $second->binding->fresh()->revoked_at
        );

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'auditable_type' =>
                OperationalDeviceBrowserBinding::class,
            'auditable_id' => (string) $second->binding->id,
            'event' => 'issued',
            'user_id' => $admin->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'auditable_type' =>
                OperationalDeviceBrowserBinding::class,
            'auditable_id' => (string) $first->binding->id,
            'event' => 'revoked',
            'user_id' => $admin->id,
        ]);

        CarbonImmutable::setTestNow();
    }

    public function test_non_admin_foreign_tenant_and_inactive_device_fail_closed(): void
    {
        $organization = $this->organization('Segura');
        $admin = $this->actor(
            $organization,
            UserRole::Admin
        );
        $operator = $this->actor(
            $organization,
            UserRole::Operator
        );

        $this->actingAs($admin);

        $device = app(OperationalDeviceRegistry::class)
            ->register(
                $admin,
                'POS segura',
                []
            );

        $manager = app(
            OperationalDeviceBrowserBindingManager::class
        );

        $this->actingAs($operator);

        $this->assertDomainRejected(
            fn () => $manager->issue(
                $operator,
                $device
            ),
            'Sólo un administrador'
        );

        $other = $this->organization('Ajena');
        $otherAdmin = $this->actor(
            $other,
            UserRole::Admin
        );
        $this->actingAs($otherAdmin);

        $this->assertDomainRejected(
            fn () => $manager->issue(
                $otherAdmin,
                $device
            ),
            'no pertenece a la organización activa'
        );

        $this->actingAs($admin);
        app(OperationalDeviceRegistry::class)
            ->deactivate($admin, $device);

        $this->assertDomainRejected(
            fn () => $manager->issue(
                $admin,
                $device->fresh()
            ),
            'no está habilitado'
        );

        $this->assertDatabaseCount(
            'operational_device_browser_bindings',
            0
        );
    }

    public function test_resolver_is_fail_closed_for_revoked_expired_inactive_and_foreign_current_organization(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse('2026-08-28 18:00:00')
        );

        $organization = $this->organization('Resolve A');
        $admin = $this->actor(
            $organization,
            UserRole::Admin
        );
        $this->actingAs($admin);

        $device = app(OperationalDeviceRegistry::class)
            ->register(
                $admin,
                'POS resolver',
                [
                    OperationalDeviceCapability::RestrictedOfflineReplay,
                ]
            );

        $manager = app(
            OperationalDeviceBrowserBindingManager::class
        );
        $resolver = app(
            OperationalDeviceBrowserBindingResolver::class
        );

        $issue = $manager->issue($admin, $device);

        $resolved = $resolver->resolveToken(
            $admin,
            $issue->token
        );

        $this->assertNotNull($resolved);
        $this->assertSame(
            $device->id,
            $resolved->device->id
        );

        $manager->revoke(
            $admin,
            $issue->binding
        );

        $this->assertNull(
            $resolver->resolveToken(
                $admin,
                $issue->token
            )
        );

        $replacement = $manager->issue(
            $admin,
            $device
        );

        DB::table('operational_device_browser_bindings')
            ->where('id', $replacement->binding->id)
            ->update([
                'expires_at' =>
                    CarbonImmutable::now()->subSecond(),
            ]);

        $this->assertNull(
            $resolver->resolveToken(
                $admin,
                $replacement->token
            )
        );

        $active = $manager->issue(
            $admin,
            $device
        );

        $other = $this->organization('Resolve B');
        OrganizationMembership::query()->create([
            'organization_id' => $other->id,
            'user_id' => $admin->id,
            'role' => UserRole::Admin,
            'active' => true,
        ]);

        app(CurrentOrganization::class)->switchTo(
            $admin,
            $other
        );

        $this->assertNull(
            $resolver->resolveToken(
                $admin->fresh(),
                $active->token
            )
        );

        app(CurrentOrganization::class)->switchTo(
            $admin->fresh(),
            $organization
        );

        $this->actingAs($admin->fresh());
        app(OperationalDeviceRegistry::class)
            ->deactivate(
                $admin->fresh(),
                $device->fresh()
            );

        $this->assertNull(
            $resolver->resolveToken(
                $admin->fresh(),
                $active->token
            )
        );

        CarbonImmutable::setTestNow();
    }

    public function test_http_binding_cookie_is_http_only_strict_and_runtime_exposes_no_secret_or_mutation_authority(): void
    {
        $organization = $this->organization('HTTP');
        $admin = $this->actor(
            $organization,
            UserRole::Admin
        );
        $this->actingAs($admin);

        $device = app(OperationalDeviceRegistry::class)
            ->register(
                $admin,
                'POS HTTP',
                [
                    OperationalDeviceCapability::RestrictedOfflineReplay,
                ]
            );

        $issue = app(
            OperationalDeviceBrowserBindingManager::class
        )->issue($admin, $device);

        $runtime = $this
            ->withCookie(
                OperationalDeviceBrowserBindingManager::COOKIE_NAME,
                $issue->token
            )
            ->get(
                route('operational-runtime.device.show')
            );

        $runtime
            ->assertOk()
            ->assertJsonPath('runtime_version', 1)
            ->assertJsonPath('bound', true)
            ->assertJsonPath(
                'device.public_id',
                $device->public_id
            )
            ->assertJsonPath(
                'policy.offline_final_sale_allowed',
                false
            )
            ->assertJsonPath(
                'policy.offline_payment_finalization_allowed',
                false
            )
            ->assertJsonPath(
                'policy.offline_fiscal_authorization_allowed',
                false
            )
            ->assertJsonPath(
                'policy.silent_price_or_stock_conflict_merge_allowed',
                false
            );

        $content = $runtime->getContent();

        $this->assertStringNotContainsString(
            'token_hash',
            $content
        );
        $this->assertStringNotContainsString(
            '"token"',
            $content
        );
        $this->assertSame(
            'no-store, private',
            $runtime->headers->get('Cache-Control')
        );

        $response = $this->post(
            route(
                'operational-device-browser-bindings.store',
                $device->public_id
            )
        );

        $response->assertRedirect();

        $cookie = collect(
            $response->headers->getCookies()
        )->first(
            fn ($cookie): bool =>
                $cookie->getName()
                    === OperationalDeviceBrowserBindingManager::COOKIE_NAME
        );

        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame(
            'strict',
            strtolower((string) $cookie->getSameSite())
        );

        $this->assertSame(
            2,
            OperationalDeviceBrowserBinding::query()->count()
        );
        $this->assertNotNull(
            $issue->binding->fresh()->revoked_at
        );
    }

    public function test_runtime_endpoint_requires_session_tenant_and_public_device_id_is_not_a_credential(): void
    {
        $this
            ->getJson(
                route('operational-runtime.device.show')
            )
            ->assertRedirect(route('login'));

        $organization = $this->organization('No claim');
        $admin = $this->actor(
            $organization,
            UserRole::Admin
        );
        $this->actingAs($admin);

        $device = app(OperationalDeviceRegistry::class)
            ->register(
                $admin,
                'POS pública',
                []
            );

        $runtime = $this
            ->withCookie(
                OperationalDeviceBrowserBindingManager::COOKIE_NAME,
                $device->public_id
            )
            ->get(
                route('operational-runtime.device.show')
            );

        $runtime
            ->assertOk()
            ->assertJsonPath('bound', false)
            ->assertJsonPath('device', null);

        $this->assertDatabaseCount(
            'operational_device_browser_bindings',
            0
        );
    }

    public function test_binding_identity_fields_are_immutable_and_rows_are_not_physically_deleted(): void
    {
        $organization = $this->organization('Immutable');
        $admin = $this->actor(
            $organization,
            UserRole::Admin
        );
        $this->actingAs($admin);

        $device = app(OperationalDeviceRegistry::class)
            ->register(
                $admin,
                'POS inmutable',
                []
            );

        $issue = app(
            OperationalDeviceBrowserBindingManager::class
        )->issue($admin, $device);

        $binding = $issue->binding->fresh();
        $binding->token_hash = str_repeat('a', 64);

        try {
            $binding->save();
            $this->fail(
                'El token hash del binding no debe poder reescribirse.'
            );
        } catch (LogicException $exception) {
            $this->assertStringContainsString(
                'inmutables',
                $exception->getMessage()
            );
        }

        try {
            $issue->binding->fresh()->delete();
            $this->fail(
                'El binding no debe poder eliminarse físicamente.'
            );
        } catch (LogicException $exception) {
            $this->assertStringContainsString(
                'no pueden eliminarse físicamente',
                $exception->getMessage()
            );
        }
    }

    private function organization(string $name): Organization
    {
        return Organization::withoutEvents(
            fn () => Organization::query()->create([
                'name' => $name,
                'slug' => str($name)->slug()->toString(),
                'active' => true,
            ])
        );
    }

    private function actor(
        Organization $organization,
        UserRole $role
    ): User {
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

        return $user;
    }

    private function assertDomainRejected(
        callable $operation,
        string $expectedMessage
    ): void {
        try {
            $operation();
        } catch (DomainException $exception) {
            $this->assertStringContainsString(
                $expectedMessage,
                $exception->getMessage()
            );

            return;
        }

        $this->fail('La operación de dominio debía ser rechazada.');
    }
}
