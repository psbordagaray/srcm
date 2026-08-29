<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Passkeys;
use Tests\TestCase;

class PasskeyCredentialFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_implements_official_passkey_contract_with_opaque_stable_handle(): void
    {
        config([
            'passkeys.user_handle_secret' => str_repeat('k', 32),
        ]);

        $user = User::factory()->create([
            'name' => 'Offline Operator',
            'email' => 'offline-operator@example.test',
            'email_verified_at' => now(),
        ]);

        $this->assertInstanceOf(PasskeyUser::class, $user);

        $expected = hash_hmac(
            'sha256',
            $user->getTable().'|'.$user->getKey(),
            str_repeat('k', 32),
            true,
        );

        $first = $user->getPasskeyUserHandle();
        $second = $user->fresh()->getPasskeyUserHandle();

        $this->assertSame(32, strlen($first));
        $this->assertSame($expected, $first);
        $this->assertSame($first, $second);
        $this->assertNotSame((string) $user->getKey(), $first);
        $this->assertSame($user->email, $user->getPasskeyUsername());
        $this->assertSame($user->name, $user->getPasskeyDisplayName());
    }

    public function test_official_passkeys_schema_contract_is_present(): void
    {
        $this->assertTrue(Schema::hasTable('passkeys'));
        $this->assertTrue(Schema::hasColumns('passkeys', [
            'id',
            'user_id',
            'name',
            'credential_id',
            'credential',
            'last_used_at',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_default_provider_routes_are_suppressed_and_guest_passkey_login_is_absent(): void
    {
        $this->assertFalse(Passkeys::shouldRegisterRoutes());
        $this->assertFalse(Route::has('passkey.login-options'));
        $this->assertFalse(Route::has('passkey.login'));

        foreach ([
            'passkey.confirm-options',
            'passkey.confirm',
            'passkey.registration-options',
            'passkey.store',
            'passkey.destroy',
        ] as $name) {
            $this->assertTrue(Route::has($name), $name.' must be registered.');
        }
    }

    public function test_confirmation_and_management_routes_are_restricted(): void
    {
        $confirm = Route::getRoutes()->getByName('passkey.confirm');
        $register = Route::getRoutes()->getByName('passkey.store');
        $destroy = Route::getRoutes()->getByName('passkey.destroy');

        $this->assertNotNull($confirm);
        $this->assertNotNull($register);
        $this->assertNotNull($destroy);

        $confirmMiddleware = $confirm->gatherMiddleware();
        $registerMiddleware = $register->gatherMiddleware();
        $destroyMiddleware = $destroy->gatherMiddleware();

        foreach (['auth:web', 'verified', 'throttle:6,1'] as $middleware) {
            $this->assertContains($middleware, $confirmMiddleware);
            $this->assertContains($middleware, $registerMiddleware);
            $this->assertContains($middleware, $destroyMiddleware);
        }

        $this->assertNotContains('password.confirm', $confirmMiddleware);
        $this->assertContains('password.confirm', $registerMiddleware);
        $this->assertContains('password.confirm', $destroyMiddleware);
    }
}
