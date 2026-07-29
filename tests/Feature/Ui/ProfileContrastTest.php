<?php

namespace Tests\Feature\Ui;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class ProfileContrastTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_uses_dark_cards_and_readable_fields(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('profile.edit'));

        $response
            ->assertOk()
            ->assertSee('Mi perfil')
            ->assertSee('Datos personales')
            ->assertSee('Contraseña')
            ->assertSee('data-input-variant="dark"', false)
            ->assertSee('bg-slate-900/80', false)
            ->assertDontSee(
                'bg-white shadow sm:rounded-lg',
                false
            );
    }

    public function test_dark_input_variant_has_dark_background_and_white_text(): void
    {
        $html = Blade::render(
            '<x-text-input variant="dark" name="example" />'
        );

        $this->assertStringContainsString(
            'bg-slate-950',
            $html
        );

        $this->assertStringContainsString(
            'text-white',
            $html
        );

        $this->assertStringNotContainsString(
            'border-gray-300',
            $html
        );
    }

    public function test_delete_account_modal_uses_dark_panel(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee(
                'border border-slate-700 bg-slate-900',
                false
            )
            ->assertSee('Sí, eliminar cuenta');
    }
}
