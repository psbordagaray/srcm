<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\UserRole;
use App\Http\Middleware\RequireOrganization;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrganizationMemberManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_routes_are_scoped_and_only_admin_manages_members(): void
    {
        $admin = $this->user(UserRole::Admin);
        $viewer = $this->user(UserRole::Viewer);

        foreach ([
            'organization-members.index',
            'organization-members.store',
            'organization-members.update-role',
            'organization-members.toggle-active',
        ] as $routeName) {
            $route = app('router')
                ->getRoutes()
                ->getByName($routeName);

            $this->assertNotNull($route);
            $this->assertContains(
                RequireOrganization::class,
                $route->gatherMiddleware()
            );
        }

        $this->actingAs($viewer)
            ->get(route('organization-members.index'))
            ->assertOk()
            ->assertSee('Usuarios y permisos')
            ->assertSee($admin->email)
            ->assertSee($viewer->email);

        $this->actingAs($viewer)
            ->post(route('organization-members.store'), [
                'email' => 'blocked@example.test',
                'role' => UserRole::Viewer->value,
            ])
            ->assertForbidden();

        $this->actingAs($admin)
            ->get(route('organization-members.index'))
            ->assertOk()
            ->assertSee('Agregar acceso')
            ->assertSee('Configurar acceso');

        $this->assertFalse(
            Route::has('organization-members.destroy')
        );
    }

    public function test_admin_provisions_new_user_with_membership_role_as_authority(): void
    {
        $admin = $this->user(UserRole::Admin);
        $organization = $this->organization();

        $this->actingAs($admin)
            ->post(route('organization-members.store'), [
                'name' => 'Operador Nuevo',
                'email' => 'OPERADOR.NUEVO@EXAMPLE.TEST',
                'password' => 'ClaveSegura123!',
                'password_confirmation' => 'ClaveSegura123!',
                'role' => UserRole::Operator->value,
            ])
            ->assertRedirect(
                route('organization-members.index')
            );

        $user = User::query()
            ->where(
                'email',
                'operador.nuevo@example.test'
            )
            ->firstOrFail();

        $this->assertSame(
            UserRole::Viewer,
            $user->role,
            'El rol global legado no debe heredar privilegios.'
        );
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(
            Hash::check(
                'ClaveSegura123!',
                $user->password
            )
        );
        $this->assertSame(
            $organization->id,
            $user->current_organization_id
        );

        $membership = OrganizationMembership::query()
            ->where(
                'organization_id',
                $organization->id
            )
            ->where('user_id', $user->id)
            ->firstOrFail();

        $this->assertSame(
            UserRole::Operator,
            $membership->role
        );
        $this->assertTrue($membership->active);

        app(CurrentOrganization::class)->forget($user);

        $this->assertTrue(
            Gate::forUser($user)
                ->allows('manage-catalog')
        );
        $this->assertTrue(
            Gate::forUser($user)
                ->allows('manage-commerce')
        );
        $this->assertFalse(
            Gate::forUser($user)
                ->allows('manage-organization-members')
        );

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'auditable_type' =>
                OrganizationMembership::class,
            'auditable_id' => (string) $membership->id,
            'event' => 'membership_created',
        ]);
    }

    public function test_existing_user_is_linked_without_duplicate_account(): void
    {
        $admin = $this->user(UserRole::Admin);

        $existing = User::withoutEvents(
            function (): User {
                $user = new User();

                $user->forceFill([
                    'name' => 'Usuario Existente',
                    'email' => 'existing@example.test',
                    'password' => 'ExistingSecret123!',
                    'email_verified_at' => now(),
                    'role' => UserRole::Admin->value,
                    'current_organization_id' => null,
                ])->save();

                return $user;
            }
        );

        $beforeUsers = User::query()->count();

        $this->actingAs($admin)
            ->post(route('organization-members.store'), [
                'email' => 'EXISTING@EXAMPLE.TEST',
                'role' => UserRole::Viewer->value,
            ])
            ->assertRedirect(
                route('organization-members.index')
            );

        $this->assertSame(
            $beforeUsers,
            User::query()->count()
        );

        $membership = OrganizationMembership::query()
            ->where('user_id', $existing->id)
            ->where(
                'organization_id',
                $this->organization()->id
            )
            ->firstOrFail();

        $this->assertSame(
            UserRole::Viewer,
            $membership->role
        );
        $this->assertTrue($membership->active);
        $this->assertSame(
            $this->organization()->id,
            $existing->fresh()->current_organization_id
        );
        $this->assertSame(
            UserRole::Admin,
            $existing->fresh()->role,
            'Vincular una membresía no debe mutar el rol global legado.'
        );
    }

    public function test_membership_role_overrides_global_role_for_catalog_permissions(): void
    {
        $admin = $this->user(UserRole::Admin);
        $target = User::withoutEvents(
            function (): User {
                $user = new User();

                $user->forceFill([
                    'name' => 'Global Admin Local Viewer',
                    'email' => 'role-authority@example.test',
                    'password' => 'SecretRole123!',
                    'email_verified_at' => now(),
                    'role' => UserRole::Admin->value,
                    'current_organization_id' =>
                        $this->organization()->id,
                ])->save();

                return $user;
            }
        );

        $membership = OrganizationMembership::query()
            ->create([
                'organization_id' =>
                    $this->organization()->id,
                'user_id' => $target->id,
                'role' => UserRole::Viewer->value,
                'active' => true,
            ]);

        app(CurrentOrganization::class)->forget($target);

        $this->assertFalse(
            Gate::forUser($target)
                ->allows('manage-catalog')
        );
        $this->assertFalse(
            Gate::forUser($target)
                ->allows('manage-commerce')
        );

        $this->actingAs($admin)
            ->patch(
                route(
                    'organization-members.update-role',
                    $membership->id
                ),
                [
                    'role' =>
                        UserRole::Operator->value,
                ]
            )
            ->assertRedirect();

        $membership->refresh();
        app(CurrentOrganization::class)->forget($target);

        $this->assertSame(
            UserRole::Operator,
            $membership->role
        );
        $this->assertSame(
            UserRole::Admin,
            $target->fresh()->role
        );
        $this->assertTrue(
            Gate::forUser($target)
                ->allows('manage-catalog')
        );
        $this->assertTrue(
            Gate::forUser($target)
                ->allows('manage-commerce')
        );

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' =>
                $this->organization()->id,
            'auditable_type' =>
                OrganizationMembership::class,
            'auditable_id' =>
                (string) $membership->id,
            'event' => 'membership_role_changed',
        ]);
    }

    public function test_self_access_is_protected_and_deactivation_clears_current_organization(): void
    {
        $admin = $this->user(UserRole::Admin);
        $operator = $this->user(UserRole::Operator);

        $adminMembership = $this->membership($admin);
        $operatorMembership = $this->membership(
            $operator
        );

        $this->actingAs($admin)
            ->patch(
                route(
                    'organization-members.update-role',
                    $adminMembership->id
                ),
                ['role' => UserRole::Viewer->value]
            )
            ->assertRedirect()
            ->assertSessionHas(
                'error',
                'No podés cambiar tu propio rol administrativo.'
            );

        $this->actingAs($admin)
            ->patch(
                route(
                    'organization-members.toggle-active',
                    $adminMembership->id
                )
            )
            ->assertRedirect()
            ->assertSessionHas(
                'error',
                'No podés desactivar tu propio acceso.'
            );

        $this->assertSame(
            UserRole::Admin,
            $adminMembership->fresh()->role
        );
        $this->assertTrue(
            $adminMembership->fresh()->active
        );

        $this->actingAs($admin)
            ->patch(
                route(
                    'organization-members.toggle-active',
                    $operatorMembership->id
                )
            )
            ->assertRedirect();

        $this->assertFalse(
            $operatorMembership->fresh()->active
        );
        $this->assertNull(
            $operator->fresh()->current_organization_id
        );

        app(CurrentOrganization::class)->forget($operator);

        $this->assertFalse(
            Gate::forUser($operator)
                ->allows('manage-catalog')
        );

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' =>
                $this->organization()->id,
            'auditable_type' =>
                OrganizationMembership::class,
            'auditable_id' =>
                (string) $operatorMembership->id,
            'event' => 'membership_deactivated',
        ]);
    }

    public function test_foreign_membership_is_hidden_from_current_organization(): void
    {
        $admin = $this->user(UserRole::Admin);
        $foreignOrganization = Organization::withoutEvents(
            fn () => Organization::query()->create([
                'name' => 'Organización Externa',
                'slug' => 'organization-external-'
                    .Str::lower(Str::random(6)),
                'active' => true,
            ])
        );
        $foreignUser = User::withoutEvents(
            function () use (
                $foreignOrganization
            ): User {
                $user = new User();

                $user->forceFill([
                    'name' => 'Usuario Ajeno',
                    'email' => 'foreign-member@example.test',
                    'password' => 'ForeignSecret123!',
                    'email_verified_at' => now(),
                    'role' => UserRole::Viewer->value,
                    'current_organization_id' =>
                        $foreignOrganization->id,
                ])->save();

                return $user;
            }
        );

        $foreignMembership =
            OrganizationMembership::withoutEvents(
                fn () => OrganizationMembership::query()
                    ->create([
                        'organization_id' =>
                            $foreignOrganization->id,
                        'user_id' => $foreignUser->id,
                        'role' => UserRole::Admin->value,
                        'active' => true,
                    ])
            );

        $this->actingAs($admin)
            ->patch(
                route(
                    'organization-members.update-role',
                    $foreignMembership->id
                ),
                ['role' => UserRole::Viewer->value]
            )
            ->assertNotFound();

        $this->actingAs($admin)
            ->patch(
                route(
                    'organization-members.toggle-active',
                    $foreignMembership->id
                )
            )
            ->assertNotFound();

        $this->actingAs($admin)
            ->get(route('organization-members.index'))
            ->assertOk()
            ->assertDontSee($foreignUser->email);
    }

    private function organization(): Organization
    {
        return Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();
    }

    private function user(UserRole $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }

    private function membership(
        User $user
    ): OrganizationMembership {
        return OrganizationMembership::query()
            ->where(
                'organization_id',
                $this->organization()->id
            )
            ->where('user_id', $user->id)
            ->firstOrFail();
    }
}
