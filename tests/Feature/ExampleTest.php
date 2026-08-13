<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_root_redirects_to_the_login_surface(): void
    {
        $this->get('/')
            ->assertRedirect(
                route('login', absolute: false)
            );
    }
}
