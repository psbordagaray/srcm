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
            'technical-models.index',
            'technical-models.create',
            'technical-models.store',
            'technical-models.show',
            'technical-models.edit',
            'technical-models.update',
            'technical-models.toggle-active',
            'knowledge.explorer',
            'knowledge.show',
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
            ->get(route('knowledge.explorer'))
            ->assertOk();
    }
}
