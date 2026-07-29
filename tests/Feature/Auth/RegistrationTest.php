<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_route_is_not_defined(): void
    {
        $this->assertFalse(Route::has('register'));
    }

    public function test_public_registration_endpoints_are_not_available(): void
    {
        $email = 'unauthorized@example.com';

        $this->get('/register')->assertNotFound();

        $this->post('/register', [
            'name' => 'Unauthorized User',
            'email' => $email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();

        $this->assertGuest();

        $this->assertDatabaseMissing('users', [
            'email' => $email,
        ]);
    }

    public function test_login_screen_remains_available(): void
    {
        $this->get('/login')->assertOk();
    }
}
