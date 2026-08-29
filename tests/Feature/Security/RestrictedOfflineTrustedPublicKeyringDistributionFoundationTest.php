<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Adapters\Offline\ConfiguredRestrictedOfflineTrustedPublicKeyringProvider;
use App\Contracts\Offline\RestrictedOfflineTrustedPublicKeyringProvider;
use App\Domain\Device\OperationalDeviceBrowserBindingManager;
use App\Domain\Device\OperationalDeviceRegistry;
use App\Domain\Offline\RestrictedOfflineSignedGrantKeyCodec;
use App\Domain\Offline\RestrictedOfflineTrustedPublicKeyring;
use App\Domain\Offline\RestrictedOfflineTrustedPublicKeyringUnavailable;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\OperationalDeviceCapability;
use App\Enums\UserRole;
use App\Http\Controllers\RestrictedOfflineTrustedPublicKeyringController;
use App\Http\Middleware\RequireOrganization;
use App\Models\OperationalDeviceBrowserBinding;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RestrictedOfflineTrustedPublicKeyringDistributionFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_keyring_route_uses_online_authenticated_binding_authority_gates(): void
    {
        $route = Route::getRoutes()->getByName(
            'restricted-offline.trusted-public-keyring.show'
        );
        $this->assertNotNull($route);
        $middleware = $route->gatherMiddleware();

        foreach ([
            'auth',
            'verified',
            RequireOrganization::class,
            'can:record-commerce-sales',
        ] as $required) {
            $this->assertContains($required, $middleware);
        }

        $this->assertNotContains('password.confirm', $middleware);
    }

    public function test_public_keyring_provider_is_independent_from_private_signing_enablement(): void
    {
        $kid = 'sg-ed25519-test-public';
        $public = str_repeat("\x11", 32);

        config([
            'offline.restricted_signed_grant.enabled' => false,
            'offline.restricted_signed_grant.active_kid' => null,
            'offline.restricted_signed_grant.trusted_public_keyring_version' => 7,
            'offline.restricted_signed_grant.trusted_public_keyring_json' => [
                $kid => RestrictedOfflineSignedGrantKeyCodec::publicKeyJwk($public),
            ],
        ]);

        $resolved = (new ConfiguredRestrictedOfflineTrustedPublicKeyringProvider)
            ->current();

        $this->assertSame(7, $resolved->version);
        $this->assertSame([$kid], array_keys($resolved->keys));
        $this->assertSame(
            '9707aadc29d198db54a9d32607430102f6bbcd6115d460f5ab5a8cc1cbadc121',
            (new RestrictedOfflineTrustedPublicKeyring(7, [
                'sg-ed25519-b' => RestrictedOfflineSignedGrantKeyCodec::publicKeyJwk(
                    str_repeat("\x22", 32)
                ),
                'sg-ed25519-a' => RestrictedOfflineSignedGrantKeyCodec::publicKeyJwk(
                    str_repeat("\x11", 32)
                ),
            ]))->fingerprint
        );
    }

    public function test_public_keyring_provider_fails_closed_for_missing_or_invalid_public_material(): void
    {
        config([
            'offline.restricted_signed_grant.trusted_public_keyring_version' => null,
            'offline.restricted_signed_grant.trusted_public_keyring_json' => null,
        ]);

        $this->expectException(
            RestrictedOfflineTrustedPublicKeyringUnavailable::class
        );

        (new ConfiguredRestrictedOfflineTrustedPublicKeyringProvider)
            ->current();
    }

    public function test_keyring_endpoint_fails_closed_until_public_trust_material_exists(): void
    {
        [$operator, , , $token] = $this->operationalFixture();
        config([
            'offline.restricted_signed_grant.enabled' => false,
            'offline.restricted_signed_grant.trusted_public_keyring_version' => null,
            'offline.restricted_signed_grant.trusted_public_keyring_json' => null,
        ]);

        $this->actingAs($operator)
            ->withCredentials()
            ->withCookie(
                OperationalDeviceBrowserBindingManager::COOKIE_NAME,
                $token
            )
            ->getJson(
                route('restricted-offline.trusted-public-keyring.show')
            )
            ->assertStatus(503);
    }

    public function test_keyring_endpoint_distributes_only_public_scoped_versioned_trust_before_signing_is_enabled(): void
    {
        [$operator, , $binding, $token] = $this->operationalFixture();
        $kid = 'sg-ed25519-test-endpoint';
        $public = str_repeat("\x44", 32);
        $keyring = new RestrictedOfflineTrustedPublicKeyring(9, [
            $kid => RestrictedOfflineSignedGrantKeyCodec::publicKeyJwk($public),
        ]);

        config([
            'offline.restricted_signed_grant.enabled' => false,
            'offline.restricted_signed_grant.active_kid' => null,
            'offline.restricted_signed_grant.trusted_public_keyring_version' => 9,
            'offline.restricted_signed_grant.trusted_public_keyring_json' => $keyring->keys,
        ]);

        $response = $this->actingAs($operator)
            ->withCredentials()
            ->withCookie(
                OperationalDeviceBrowserBindingManager::COOKIE_NAME,
                $token
            )
            ->getJson(
                route('restricted-offline.trusted-public-keyring.show')
            );

        $response->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Vary', 'Cookie')
            ->assertJsonPath('bundle_version', 1)
            ->assertJsonPath('keyring_version', 9)
            ->assertJsonPath('keyring_fingerprint', $keyring->fingerprint)
            ->assertJsonPath(
                'scope.binding_public_id',
                (string) $binding->public_id
            )
            ->assertJsonPath(
                'scope.device_public_id',
                (string) $binding->device->public_id
            )
            ->assertJsonPath('keys.'.$kid.'.kty', 'OKP')
            ->assertJsonPath('keys.'.$kid.'.crv', 'Ed25519')
            ->assertJsonPath('keys.'.$kid.'.alg', 'EdDSA')
            ->assertJsonPath('keys.'.$kid.'.use', 'sig')
            ->assertJsonMissingPath('active_kid')
            ->assertJsonMissingPath('signing_secret')
            ->assertJsonMissingPath('grant');

        $payload = $response->json();
        $this->assertSame(
            RestrictedOfflineTrustedPublicKeyringController::MAX_VALIDITY_SECONDS,
            strtotime($payload['expires_at']) - strtotime($payload['server_issued_at'])
        );
    }

    public function test_container_resolves_separate_public_only_provider_contract(): void
    {
        $resolved = app(RestrictedOfflineTrustedPublicKeyringProvider::class);
        $this->assertInstanceOf(
            ConfiguredRestrictedOfflineTrustedPublicKeyringProvider::class,
            $resolved
        );
    }

    /** @return array{0:User,1:Organization,2:OperationalDeviceBrowserBinding,3:string} */
    private function operationalFixture(): array
    {
        $organization = Organization::query()->create([
            'name' => 'Trusted Keyring Test Organization',
            'slug' => 'trusted-keyring-test-organization',
            'active' => true,
        ]);
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'role' => UserRole::Admin,
        ]);
        $operator = User::factory()->create([
            'email_verified_at' => now(),
            'role' => UserRole::Operator,
        ]);

        foreach ([
            [$admin, UserRole::Admin],
            [$operator, UserRole::Operator],
        ] as [$actor, $role]) {
            $actor->forceFill([
                'current_organization_id' => $organization->getKey(),
            ])->saveQuietly();
            OrganizationMembership::query()->updateOrCreate(
                [
                    'organization_id' => $organization->getKey(),
                    'user_id' => $actor->getKey(),
                ],
                [
                    'role' => $role,
                    'active' => true,
                ]
            );
            app(CurrentOrganization::class)->forget($actor);
        }

        $device = app(OperationalDeviceRegistry::class)->register(
            $admin,
            'Trusted Keyring Browser Device',
            [OperationalDeviceCapability::RestrictedOfflineReadModel]
        );
        $bindingIssue = app(
            OperationalDeviceBrowserBindingManager::class
        )->issue($admin, $device);

        return [
            $operator,
            $organization,
            $bindingIssue->binding,
            $bindingIssue->token,
        ];
    }
}
