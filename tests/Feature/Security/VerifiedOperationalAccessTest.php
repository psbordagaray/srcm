<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VerifiedOperationalAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_model_requires_email_verification(): void
    {
        $this->assertInstanceOf(
            MustVerifyEmail::class,
            new User()
        );
    }

    public function test_all_operational_routes_require_verified_middleware(): void
    {
        $routeNames = [
            'dashboard',
            'organization.show',
            'organization.edit',
            'organization.update',
            'organizations.activate',
            'entities.create',
            'entities.store',
            'entities.show',
            'entities.identifiers.store',
            'entities.identifiers.make-primary',
            'entities.identifiers.toggle-active',
            'entities.compatibilities.store',
            'entities.compatibilities.toggle-active',
            'product-categories.index',
            'product-categories.create',
            'product-categories.store',
            'product-categories.show',
            'product-categories.edit',
            'product-categories.update',
            'product-categories.toggle-active',
            'brands.index',
            'brands.create',
            'brands.store',
            'brands.show',
            'brands.edit',
            'brands.update',
            'brands.toggle-active',
            'manufacturers.index',
            'manufacturers.create',
            'manufacturers.store',
            'manufacturers.show',
            'manufacturers.edit',
            'manufacturers.update',
            'manufacturers.toggle-active',
            'products.index',
            'products.create',
            'products.store',
            'products.show',
            'products.edit',
            'products.update',
            'products.toggle-active',
            'suppliers.index',
            'suppliers.create',
            'suppliers.store',
            'suppliers.show',
            'suppliers.edit',
            'suppliers.update',
            'suppliers.toggle-active',
            'supplier-offers.index',
            'supplier-offers.create',
            'supplier-offers.store',
            'supplier-offers.show',
            'supplier-offers.edit',
            'supplier-offers.update',
            'supplier-offers.toggle-active',
            'technical-models.index',
            'technical-models.create',
            'technical-models.store',
            'technical-models.show',
            'technical-models.edit',
            'technical-models.update',
            'technical-models.toggle-active',
            'knowledge.explorer',
            'knowledge.show',
            'audit-logs.index',
            'audit-logs.show',
        ];

        foreach ($routeNames as $routeName) {
            $route = app('router')
                ->getRoutes()
                ->getByName($routeName);

            $this->assertNotNull(
                $route,
                "La ruta {$routeName} debe existir."
            );

            $middleware = $route->gatherMiddleware();

            $this->assertContains(
                'auth',
                $middleware,
                "La ruta {$routeName} debe exigir autenticación."
            );

            $this->assertContains(
                'verified',
                $middleware,
                "La ruta {$routeName} debe exigir correo verificado."
            );
        }
    }

    public function test_unverified_user_is_redirected_from_operational_routes(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->get(route('organization.show'))
            ->assertRedirect(route('verification.notice'));

        $this->actingAs($user)
            ->get(route('brands.index'))
            ->assertRedirect(route('verification.notice'));

        $this->actingAs($user)
            ->get(route('knowledge.explorer'))
            ->assertRedirect(route('verification.notice'));

        $this->actingAs($user)
            ->get(
                route(
                    'knowledge.show',
                    ['query' => 'EN2BC27']
                )
            )
            ->assertRedirect(route('verification.notice'));
    }

    public function test_unverified_user_can_access_profile_and_verification_notice(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('verification.notice'))
            ->assertOk();
    }

    public function test_verified_user_can_access_operational_routes(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($user->hasVerifiedEmail());

        $this->actingAs($user)
            ->get(route('brands.index'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('manufacturers.index'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('products.index'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('suppliers.index'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('supplier-offers.index'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('knowledge.explorer'))
            ->assertOk();
    }
}
