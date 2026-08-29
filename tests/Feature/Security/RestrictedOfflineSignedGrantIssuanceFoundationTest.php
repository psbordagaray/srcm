<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Adapters\Offline\EnvironmentRestrictedOfflineSignedGrantSigningKeyProvider;
use App\Adapters\Offline\WebAuthnRestrictedOfflineSignedGrantCredentialMaterialExtractor;
use App\Contracts\Offline\RestrictedOfflineSignedGrantCredentialMaterialExtractor;
use App\Contracts\Offline\RestrictedOfflineSignedGrantSigningKeyProvider;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Device\OperationalDeviceBrowserBindingManager;
use App\Domain\Device\OperationalDeviceBrowserBindingResolver;
use App\Domain\Device\OperationalDeviceRegistry;
use App\Domain\Offline\RestrictedOfflineSignedGrantCredentialMaterial;
use App\Domain\Offline\RestrictedOfflineSignedGrantIssuanceService;
use App\Domain\Offline\RestrictedOfflineSignedGrantKeyCodec;
use App\Domain\Offline\RestrictedOfflineSignedGrantSigningKey;
use App\Domain\Offline\RestrictedOfflineSignedGrantSigningKeyUnavailable;
use App\Domain\Offline\RestrictedOfflineSignedGrantVerifier;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\OperationalDeviceCapability;
use App\Enums\UserRole;
use App\Http\Middleware\RequireOrganization;
use App\Http\Requests\RestrictedOfflineSignedGrantIssueRequest;
use App\Models\AuditLog;
use App\Models\OperationalDeviceBrowserBinding;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Passkeys\Passkey;
use Tests\TestCase;

class RestrictedOfflineSignedGrantIssuanceFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_issuance_routes_are_sensitive_online_session_routes(): void
    {
        foreach ([
            'restricted-offline.signed-grant.options',
            'restricted-offline.signed-grant.issue',
        ] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route);
            $middleware = $route->gatherMiddleware();

            foreach ([
                'auth',
                'verified',
                RequireOrganization::class,
                'can:record-commerce-sales',
                'throttle:restricted-offline-signed-grant',
            ] as $required) {
                $this->assertContains($required, $middleware, $name.' '.$required);
            }

            $this->assertNotContains('password.confirm', $middleware);
        }
    }

    public function test_environment_signing_key_provider_fails_closed_and_checks_trusted_keyring(): void
    {
        $provider = new EnvironmentRestrictedOfflineSignedGrantSigningKeyProvider;

        config(['offline.restricted_signed_grant.enabled' => false]);
        $this->expectException(RestrictedOfflineSignedGrantSigningKeyUnavailable::class);
        $provider->current();
    }

    public function test_environment_signing_key_provider_accepts_only_matching_active_key(): void
    {
        [$secret, $public] = $this->ed25519Keypair();
        $kid = 'test-2026-08';

        config([
            'offline.restricted_signed_grant.enabled' => true,
            'offline.restricted_signed_grant.active_kid' => $kid,
            'offline.restricted_signed_grant.signing_secret_env' =>
                'SRCM_OFFLINE_SIGNED_GRANT_SIGNING_SECRET_B64URL',
            'offline.restricted_signed_grant.trusted_public_keyring_json' => [
                $kid => RestrictedOfflineSignedGrantKeyCodec::publicKeyJwk($public),
            ],
        ]);
        $this->setSigningSecretEnvironment($secret);

        $resolved = (new EnvironmentRestrictedOfflineSignedGrantSigningKeyProvider)
            ->current();

        $this->assertSame($kid, $resolved->kid);
        $this->assertSame($secret, $resolved->secretKey);
        $this->assertSame($public, $resolved->publicKey);
    }

    public function test_environment_signing_key_provider_rejects_mismatched_trusted_public_key(): void
    {
        [$secret, ] = $this->ed25519Keypair();
        [, $otherPublic] = $this->ed25519Keypair();
        $kid = 'test-mismatch-1';

        config([
            'offline.restricted_signed_grant.enabled' => true,
            'offline.restricted_signed_grant.active_kid' => $kid,
            'offline.restricted_signed_grant.signing_secret_env' =>
                'SRCM_OFFLINE_SIGNED_GRANT_SIGNING_SECRET_B64URL',
            'offline.restricted_signed_grant.trusted_public_keyring_json' => [
                $kid => RestrictedOfflineSignedGrantKeyCodec::publicKeyJwk(
                    $otherPublic
                ),
            ],
        ]);
        $this->setSigningSecretEnvironment($secret);

        $this->expectException(
            RestrictedOfflineSignedGrantSigningKeyUnavailable::class
        );

        (new EnvironmentRestrictedOfflineSignedGrantSigningKeyProvider)
            ->current();
    }

    public function test_cose_extractor_accepts_exact_es256_p256_and_fingerprints_raw_bytes(): void
    {
        $x = str_repeat("\x11", 32);
        $y = str_repeat("\x22", 32);
        $cose = "\xA5\x01\x02\x03\x26\x20\x01\x21\x58\x20".$x.
            "\x22\x58\x20".$y;
        $userHandle = str_repeat("\x33", 32);

        $material = (new WebAuthnRestrictedOfflineSignedGrantCredentialMaterialExtractor)
            ->fromRawCose('AQID', $cose, $userHandle);

        $this->assertSame(hash('sha256', $cose), $material->credentialFingerprint);
        $this->assertSame($userHandle, $material->userHandle);
        $this->assertSame('ES256', $material->confirmationJwk['alg']);
        $this->assertSame('P-256', $material->confirmationJwk['crv']);
        $this->assertSame(
            RestrictedOfflineSignedGrantKeyCodec::encodeBase64Url($x),
            $material->confirmationJwk['x']
        );
        $this->assertSame(
            RestrictedOfflineSignedGrantKeyCodec::encodeBase64Url($y),
            $material->confirmationJwk['y']
        );
    }

    public function test_issuance_service_emits_read_model_only_grant_and_audits_no_secrets(): void
    {
        config([
            'passkeys.user_handle_secret' => str_repeat('u', 32),
            'offline.restricted_signed_grant.policy_version' =>
                'restricted-read-model-v1',
        ]);

        [$user, $organization, $binding, $passkey] =
            $this->operationalFixture();
        $this->actingAs($user);

        [$secret, $public] = $this->ed25519Keypair();
        $signingKey = new RestrictedOfflineSignedGrantSigningKey(
            'test-key-1',
            $secret
        );
        $material = new RestrictedOfflineSignedGrantCredentialMaterial(
            credentialId: 'AQID',
            credentialFingerprint: str_repeat('a', 64),
            userHandle: $user->getPasskeyUserHandle(),
            confirmationJwk: [
                'alg' => 'ES256',
                'crv' => 'P-256',
                'kty' => 'EC',
                'x' => RestrictedOfflineSignedGrantKeyCodec::encodeBase64Url(
                    str_repeat("\x44", 32)
                ),
                'y' => RestrictedOfflineSignedGrantKeyCodec::encodeBase64Url(
                    str_repeat("\x55", 32)
                ),
            ],
        );

        $keyProvider = new class($signingKey) implements RestrictedOfflineSignedGrantSigningKeyProvider {
            public function __construct(
                private readonly RestrictedOfflineSignedGrantSigningKey $key
            ) {
            }

            public function current(): RestrictedOfflineSignedGrantSigningKey
            {
                return $this->key;
            }
        };
        $extractor = new class($material) implements RestrictedOfflineSignedGrantCredentialMaterialExtractor {
            public function __construct(
                private readonly RestrictedOfflineSignedGrantCredentialMaterial $material
            ) {
            }

            public function extract(
                Passkey $passkey
            ): RestrictedOfflineSignedGrantCredentialMaterial {
                return $this->material;
            }
        };

        $service = new RestrictedOfflineSignedGrantIssuanceService(
            app(CurrentOrganization::class),
            $keyProvider,
            $extractor,
            app(AuditRecorder::class),
        );

        $issued = $service->issue(
            $user,
            $binding,
            $passkey,
            [OperationalDeviceCapability::RestrictedOfflineReadModel->value],
            600,
        );

        $verified = (new RestrictedOfflineSignedGrantVerifier([
            'test-key-1' => $public,
        ]))->verify($issued->grant);

        $this->assertSame((int) $organization->getKey(), $verified->organizationId);
        $this->assertSame(
            [OperationalDeviceCapability::RestrictedOfflineReadModel->value],
            $verified->capabilities
        );
        $this->assertSame('test-key-1', $issued->kid);

        $audit = AuditLog::query()
            ->where('event', 'restricted_offline_signed_grant_issued')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame((string) $binding->getKey(), $audit->auditable_id);
        $this->assertSame('test-key-1', $audit->new_values['kid']);
        $this->assertArrayNotHasKey('grant', $audit->new_values);
        $this->assertArrayNotHasKey('secret_key', $audit->new_values);
        $this->assertArrayNotHasKey('browser_binding_token', $audit->new_values);
    }

    public function test_canonical_fixture_has_operator_gate_http_binding_and_passkey_preconditions(): void
    {
        [$user, $organization, $binding, , $token] =
            $this->operationalFixture();

        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertSame(
            UserRole::Operator,
            app(CurrentOrganization::class)->roleFor($user)
        );
        $this->assertTrue(
            Gate::forUser($user)->allows('record-commerce-sales')
        );
        $resolved = app(
            OperationalDeviceBrowserBindingResolver::class
        )->resolveToken($user, $token);
        $this->assertNotNull($resolved);
        $this->assertSame(
            (string) $binding->public_id,
            (string) $resolved->public_id
        );
        $this->assertSame(
            (int) $organization->getKey(),
            (int) $resolved->organization_id
        );
        $this->assertTrue($user->hasPasskeysEnabled());

        $this->actingAs($user)
            ->withCredentials()
            ->withCookie(
                OperationalDeviceBrowserBindingManager::COOKIE_NAME,
                $token
            )
            ->getJson(route('operational-runtime.device.show'))
            ->assertOk()
            ->assertJsonPath('bound', true)
            ->assertJsonPath(
                'device.public_id',
                $binding->device->public_id
            );
    }

    public function test_options_endpoint_fails_closed_until_signing_material_is_provisioned(): void
    {
        config([
            'passkeys.user_handle_secret' => str_repeat('p', 32),
            'offline.restricted_signed_grant.enabled' => false,
        ]);
        [$user, , , , $token] = $this->operationalFixture();

        $this->actingAs($user)
            ->withCredentials()
            ->withCookie(
                OperationalDeviceBrowserBindingManager::COOKIE_NAME,
                $token
            )
            ->getJson(route('restricted-offline.signed-grant.options'))
            ->assertStatus(503);

        $this->assertNull(
            session()->get(
                RestrictedOfflineSignedGrantIssueRequest::SESSION_KEY
            )
        );
    }

    public function test_options_endpoint_uses_dedicated_one_time_passkey_session_and_uv_required(): void
    {
        config(['passkeys.user_handle_secret' => str_repeat('o', 32)]);
        [$user, , $binding, , $token] = $this->operationalFixture();

        [$secret, $public] = $this->ed25519Keypair();
        $kid = 'test-options-1';
        config([
            'offline.restricted_signed_grant.enabled' => true,
            'offline.restricted_signed_grant.active_kid' => $kid,
            'offline.restricted_signed_grant.signing_secret_env' =>
                'SRCM_OFFLINE_SIGNED_GRANT_SIGNING_SECRET_B64URL',
            'offline.restricted_signed_grant.trusted_public_keyring_json' => [
                $kid => RestrictedOfflineSignedGrantKeyCodec::publicKeyJwk($public),
            ],
        ]);
        $this->setSigningSecretEnvironment($secret);

        $response = $this->actingAs($user)
            ->withCredentials()
            ->withCookie(OperationalDeviceBrowserBindingManager::COOKIE_NAME, $token)
            ->getJson(route('restricted-offline.signed-grant.options'));

        $response->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('options.userVerification', 'required')
            ->assertJsonPath('max_age_seconds', 300);

        $state = session()->get(
            RestrictedOfflineSignedGrantIssueRequest::SESSION_KEY
        );
        $this->assertIsArray($state);
        $this->assertSame((string) $binding->public_id, $state['binding_public_id']);
        $this->assertSame((int) $binding->organization_id, $state['organization_id']);
        $this->assertSame((string) $user->getAuthIdentifier(), $state['user_id']);
        $this->assertIsInt($state['issued_at']);
        $this->assertIsString($state['serialized']);
        $this->assertNull(session()->get('passkey.verification_options'));
    }

    protected function tearDown(): void
    {
        unset(
            $_ENV['SRCM_OFFLINE_SIGNED_GRANT_SIGNING_SECRET_B64URL'],
            $_SERVER['SRCM_OFFLINE_SIGNED_GRANT_SIGNING_SECRET_B64URL']
        );

        parent::tearDown();
    }

    private function setSigningSecretEnvironment(string $secret): void
    {
        $encoded = RestrictedOfflineSignedGrantKeyCodec::encodeBase64Url($secret);
        $_ENV['SRCM_OFFLINE_SIGNED_GRANT_SIGNING_SECRET_B64URL'] = $encoded;
        $_SERVER['SRCM_OFFLINE_SIGNED_GRANT_SIGNING_SECRET_B64URL'] = $encoded;
    }

    /** @return array{0:User,1:Organization,2:OperationalDeviceBrowserBinding,3:Passkey,4:string} */
    private function operationalFixture(): array
    {
        $organization = Organization::query()->create([
            'name' => 'Offline Test Organization',
            'slug' => 'offline-test-organization',
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

        $adminMembership = OrganizationMembership::query()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $admin->getKey())
            ->where('active', true)
            ->firstOrFail();
        $operatorMembership = OrganizationMembership::query()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $operator->getKey())
            ->where('active', true)
            ->firstOrFail();

        $this->assertSame(UserRole::Admin, $adminMembership->role);
        $this->assertSame(UserRole::Operator, $operatorMembership->role);

        foreach ([$admin, $operator] as $actor) {
            $this->assertSame(
                1,
                OrganizationMembership::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('user_id', $actor->getKey())
                    ->where('active', true)
                    ->count(),
                'Each issuance fixture actor must contain exactly one active membership.'
            );
        }

        $device = app(OperationalDeviceRegistry::class)->register(
            $admin,
            'Offline Browser Device',
            [OperationalDeviceCapability::RestrictedOfflineReadModel]
        );
        $bindingIssue = app(
            OperationalDeviceBrowserBindingManager::class
        )->issue($admin, $device);

        $passkey = $operator->passkeys()->create([
            'name' => 'Offline Test Passkey',
            'credential_id' => 'AQID',
            'credential' => [],
        ]);

        return [
            $operator,
            $organization,
            $bindingIssue->binding,
            $passkey,
            $bindingIssue->token,
        ];
    }

    /** @return array{0:string,1:string} */
    private function ed25519Keypair(): array
    {
        $keypair = sodium_crypto_sign_keypair();

        return [
            sodium_crypto_sign_secretkey($keypair),
            sodium_crypto_sign_publickey($keypair),
        ];
    }
}
