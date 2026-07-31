<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(KnowledgeFoundationSeeder::class);
        $this->call(InventoryLocationSeeder::class);

        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $testUser = User::query()->firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'password' => Hash::make('password'),
            ]
        );

        $organization = Organization::query()
            ->where('slug', 'sulu-tv')
            ->firstOrFail();

        OrganizationMembership::query()->updateOrCreate(
            [
                'organization_id' => $organization->getKey(),
                'user_id' => $testUser->getKey(),
            ],
            [
                'role' => UserRole::Admin->value,
                'active' => true,
            ]
        );

        $testUser->forceFill([
            'role' => UserRole::Admin->value,
            'email_verified_at' =>
                $testUser->email_verified_at ?? now(),
            'current_organization_id' => $organization->getKey(),
        ])->saveQuietly();
    }
}
