<?php

namespace Tests\Feature\Catalog;

use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandWebsiteNormalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_bare_domain_is_accepted_and_normalized(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $this->actingAs($admin)
            ->post(route('brands.store'), [
                'name' => 'Samsung',
                'website' => 'www.samsung.com',
                'description' => 'Marca líder',
                'active' => true,
            ])
            ->assertRedirect(route('brands.index'));

        $this->assertDatabaseHas('brands', [
            'name' => 'Samsung',
            'website' => 'https://www.samsung.com',
        ]);
    }

    public function test_website_is_optional_and_blank_becomes_null(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $this->actingAs($admin)
            ->post(route('brands.store'), [
                'name' => 'Marca sin web',
                'website' => '   ',
                'active' => true,
            ])
            ->assertRedirect(route('brands.index'));

        $brand = Brand::query()
            ->where('name', 'Marca sin web')
            ->sole();

        $this->assertNull($brand->website);
    }

    public function test_existing_http_or_https_url_is_preserved(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        foreach ([
            'http://example.test',
            'https://secure.example.test/path',
        ] as $index => $website) {
            $this->actingAs($admin)
                ->post(route('brands.store'), [
                    'name' => 'Protocol Brand '.$index,
                    'website' => $website,
                    'active' => true,
                ])
                ->assertRedirect(route('brands.index'));

            $this->assertDatabaseHas('brands', [
                'name' => 'Protocol Brand '.$index,
                'website' => $website,
            ]);
        }
    }

    public function test_invalid_text_still_returns_a_clear_validation_error(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $this->actingAs($admin)
            ->from(route('brands.create'))
            ->post(route('brands.store'), [
                'name' => 'Invalid Website Brand',
                'website' => 'esto no es una web',
                'active' => true,
            ])
            ->assertRedirect(route('brands.create'))
            ->assertSessionHasErrors('website');

        $this->assertDatabaseMissing('brands', [
            'name' => 'Invalid Website Brand',
        ]);
    }

    public function test_bare_domain_is_normalized_when_updating(): void
    {
        $brand = Brand::withoutEvents(
            fn () => Brand::query()->create([
                'name' => 'Existing Brand',
                'slug' => 'existing-brand',
                'website' => 'https://old.example.test',
                'active' => true,
            ])
        );

        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $this->actingAs($admin)
            ->patch(route('brands.update', $brand), [
                'name' => 'Existing Brand',
                'website' => 'new.example.test',
                'active' => true,
            ])
            ->assertRedirect(route('brands.index'));

        $this->assertDatabaseHas('brands', [
            'id' => $brand->id,
            'website' => 'https://new.example.test',
        ]);
    }
}
