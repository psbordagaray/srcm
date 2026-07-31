<?php

namespace Tests\Feature\Tenancy;

use App\Enums\UserRole;
use App\Models\InventoryLocation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_is_idempotent_and_bootstraps_admin(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $organization = Organization::query()
            ->where('slug', 'sulu-tv')
            ->sole();

        $user = User::query()
            ->where('email', 'test@example.com')
            ->sole();

        $membership = OrganizationMembership::query()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $user->getKey())
            ->sole();

        $this->assertSame(
            1,
            User::query()
                ->where('email', 'test@example.com')
                ->count()
        );

        $this->assertSame(
            1,
            OrganizationMembership::query()
                ->where('organization_id', $organization->getKey())
                ->where('user_id', $user->getKey())
                ->count()
        );

        $this->assertSame(UserRole::Admin, $user->role);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertSame(
            $organization->getKey(),
            (int) $user->current_organization_id
        );

        $this->assertSame(UserRole::Admin, $membership->role);
        $this->assertTrue($membership->active);

        $this->assertSame(
            3,
            InventoryLocation::query()
                ->where(
                    'organization_id',
                    $organization->getKey()
                )
                ->count()
        );
    }
}
