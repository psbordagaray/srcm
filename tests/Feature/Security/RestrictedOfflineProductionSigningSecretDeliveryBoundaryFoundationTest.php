<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domain\Offline\RestrictedOfflineSignedGrantKeyCodec;
use Tests\TestCase;

class RestrictedOfflineProductionSigningSecretDeliveryBoundaryFoundationTest extends TestCase
{
    public function test_delivery_policy_is_host_only_root_owned_fpm_only_and_fail_closed(): void
    {
        $policy = require base_path('config/offline_signing_secret_delivery.php');

        $this->assertSame(1, $policy['foundation_version']);
        $this->assertSame('straleon-prod-01', $policy['target_host']);
        $this->assertSame(
            'SRCM_OFFLINE_SIGNED_GRANT_SIGNING_SECRET_B64URL',
            $policy['secret_environment_name']
        );
        $this->assertSame(
            '/etc/srcm/runtime-secrets/offline-signed-grant.env',
            $policy['secret_file']
        );
        $this->assertSame('root', $policy['secret_owner']);
        $this->assertSame('root', $policy['secret_group']);
        $this->assertSame('0600', $policy['secret_mode']);
        $this->assertTrue($policy['http_runtime_only']);
        $this->assertFalse($policy['queue_scheduler_secret_injection_allowed']);
        $this->assertFalse($policy['shared_dotenv_private_secret_allowed']);
        $this->assertFalse($policy['github_environment_private_secret_allowed']);
        $this->assertSame('production_host_root_only', $policy['generation_location']);
        $this->assertSame(
            28920,
            $policy['rotation']['minimum_old_public_key_retention_seconds']
        );
        $this->assertTrue($policy['rotation']['restart_required_after_secret_change']);
        $this->assertFalse($policy['rotation']['reload_alone_is_sufficient']);
        $this->assertTrue($policy['rotation']['compromise_disables_issuance_first']);
        $this->assertFalse($policy['rotation']['compromised_secret_rollback_allowed']);
        $this->assertTrue(
            $policy['rotation']['noncompromise_previous_private_retained_until_explicit_retirement']
        );
        $this->assertTrue(
            $policy['rotation']['explicit_retirement_required_after_stable_cut']
        );
    }

    public function test_kid_policy_is_deterministic_from_public_key_only(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($keypair);
        $public = sodium_crypto_sign_publickey($keypair);
        $fingerprint = hash('sha256', $public);
        $kid = 'sg-ed25519-'.substr($fingerprint, 0, 16);

        $this->assertSame(64, strlen($secret));
        $this->assertSame(32, strlen($public));
        $this->assertMatchesRegularExpression(
            '/^sg-ed25519-[0-9a-f]{16}$/D',
            $kid
        );
        $this->assertSame('OKP', RestrictedOfflineSignedGrantKeyCodec::publicKeyJwk($public)['kty']);
        $this->assertSame('Ed25519', RestrictedOfflineSignedGrantKeyCodec::publicKeyJwk($public)['crv']);
        $this->assertSame('EdDSA', RestrictedOfflineSignedGrantKeyCodec::publicKeyJwk($public)['alg']);
        $this->assertSame('sig', RestrictedOfflineSignedGrantKeyCodec::publicKeyJwk($public)['use']);

        sodium_memzero($secret);
        sodium_memzero($keypair);
    }

    public function test_systemd_and_fpm_templates_inject_only_the_http_signing_secret(): void
    {
        $systemd = file_get_contents(base_path(
            'ops/production/systemd/php8.3-fpm.service.d/20-srcm-offline-signed-grant-secret.conf'
        ));
        $fpm = file_get_contents(base_path(
            'ops/production/php-fpm/99-srcm-offline-signed-grant-env.conf'
        ));

        $this->assertIsString($systemd);
        $this->assertStringContainsString('[Service]', $systemd);
        $this->assertStringContainsString(
            'EnvironmentFile=-/etc/srcm/runtime-secrets/offline-signed-grant.env',
            $systemd
        );
        $this->assertStringNotContainsString('queue', strtolower($systemd));
        $this->assertStringNotContainsString('schedule', strtolower($systemd));

        $this->assertIsString($fpm);
        $this->assertStringContainsString('[www]', $fpm);
        $this->assertStringContainsString('clear_env = yes', $fpm);
        $this->assertStringContainsString(
            'env[SRCM_OFFLINE_SIGNED_GRANT_SIGNING_SECRET_B64URL] = $SRCM_OFFLINE_SIGNED_GRANT_SIGNING_SECRET_B64URL',
            $fpm
        );
    }

    public function test_boundary_installer_never_creates_private_secret_material(): void
    {
        $source = file_get_contents(base_path(
            'ops/production/offline-signed-grant/install-secret-delivery-boundary.sh'
        ));

        $this->assertIsString($source);
        $this->assertStringContainsString('root_required', $source);
        $this->assertStringContainsString('unexpected_host', $source);
        $this->assertStringContainsString('install -d -o root -g root -m 0700', $source);
        $this->assertStringContainsString('systemctl daemon-reload', $source);
        $this->assertStringContainsString('/usr/sbin/php-fpm8.3 -tt', $source);
        $this->assertStringContainsString('systemctl restart php8.3-fpm.service', $source);
        $this->assertStringContainsString('GREEN_INSTALLED_NO_SECRET_CREATED', $source);
        $this->assertStringNotContainsString('sodium_crypto_sign_keypair', $source);
        $this->assertStringNotContainsString('touch "$SECRET_FILE"', $source);
    }

    public function test_generator_stages_root_only_secret_and_outputs_public_material_only(): void
    {
        $source = file_get_contents(base_path(
            'ops/production/offline-signed-grant/generate-staged-secret.php'
        ));

        $this->assertIsString($source);
        $this->assertStringContainsString("TARGET_HOST = 'straleon-prod-01'", $source);
        $this->assertStringContainsString('posix_geteuid() !== 0', $source);
        $this->assertStringContainsString('sodium_crypto_sign_keypair()', $source);
        $this->assertStringContainsString("'sg-ed25519-'.substr(\$fingerprint, 0, 16)", $source);
        $this->assertStringContainsString('strlen($encodedSecret) !== 86', $source);
        $this->assertStringContainsString('chmod($temporaryPath, 0600)', $source);
        $this->assertStringContainsString('rename($temporaryPath, $stagedPath)', $source);
        $this->assertStringContainsString("printf('KID=%s'", $source);
        $this->assertStringContainsString("'PUBLIC_JWK_JSON=%s'", $source);
        $this->assertStringContainsString("printf('PUBLIC_FINGERPRINT_SHA256=%s'", $source);
        $this->assertDoesNotMatchRegularExpression(
            '/(?:echo|printf)\\s*\\([^;]*\\$encodedSecret/s',
            $source
        );
    }

    public function test_promotion_is_atomic_validated_and_restarts_fpm_without_leaking_secret(): void
    {
        $source = file_get_contents(base_path(
            'ops/production/offline-signed-grant/promote-staged-secret.sh'
        ));

        $this->assertIsString($source);
        $this->assertStringContainsString('root_required', $source);
        $this->assertStringContainsString('unexpected_host', $source);
        $this->assertStringContainsString('^sg-ed25519-[0-9a-f]{16}$', $source);
        $this->assertStringContainsString('"$MODE" == \'rotate\'', $source);
        $this->assertStringContainsString('"$MODE" == \'compromise\'', $source);
        $this->assertStringContainsString('.offline-signed-grant.env.retired-$previous_kid', $source);
        $this->assertStringContainsString('install -o root -g root -m 0600', $source);
        $this->assertStringContainsString('mv -f "$installing" "$SECRET_FILE"', $source);
        $this->assertStringContainsString('systemctl restart php8.3-fpm.service', $source);
        $this->assertStringNotContainsString('systemctl reload php8.3-fpm.service', $source);
        $this->assertStringNotContainsString('srcm-queue.service', $source);
        $this->assertStringNotContainsString('srcm-schedule.service', $source);
        $this->assertStringContainsString('PROMOTED_KID=', $source);
        $this->assertStringContainsString('PUBLIC_FINGERPRINT_SHA256=', $source);
        $this->assertStringNotContainsString('SIGNING_SECRET_B64URL=%s', $source);
    }
    public function test_retirement_is_explicit_and_refuses_to_delete_the_active_secret(): void
    {
        $source = file_get_contents(base_path(
            'ops/production/offline-signed-grant/retire-private-secret.sh'
        ));

        $this->assertIsString($source);
        $this->assertStringContainsString('root_required', $source);
        $this->assertStringContainsString('unexpected_host', $source);
        $this->assertStringContainsString('cannot_retire_active_secret', $source);
        $this->assertStringContainsString('rm -f "$RETIRED_FILE"', $source);
        $this->assertStringContainsString('GREEN_EXPLICITLY_RETIRED', $source);
        $this->assertStringNotContainsString('systemctl restart srcm-queue.service', $source);
        $this->assertStringNotContainsString('systemctl restart srcm-schedule.service', $source);
    }

}
