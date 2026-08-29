<?php

declare(strict_types=1);

return [
    'foundation_version' => 1,
    'target_host' => 'straleon-prod-01',
    'secret_environment_name' =>
        'SRCM_OFFLINE_SIGNED_GRANT_SIGNING_SECRET_B64URL',
    'secret_directory' => '/etc/srcm/runtime-secrets',
    'secret_file' =>
        '/etc/srcm/runtime-secrets/offline-signed-grant.env',
    'staged_file_pattern' =>
        '/etc/srcm/runtime-secrets/.offline-signed-grant.env.staged-%s',
    'retired_file_pattern' =>
        '/etc/srcm/runtime-secrets/.offline-signed-grant.env.retired-%s',
    'secret_owner' => 'root',
    'secret_group' => 'root',
    'secret_mode' => '0600',
    'systemd_dropin_target' =>
        '/etc/systemd/system/php8.3-fpm.service.d/20-srcm-offline-signed-grant-secret.conf',
    'fpm_pool_fragment_target' =>
        '/etc/php/8.3/fpm/pool.d/99-srcm-offline-signed-grant-env.conf',
    'fpm_service' => 'php8.3-fpm.service',
    'http_runtime_only' => true,
    'queue_scheduler_secret_injection_allowed' => false,
    'shared_dotenv_private_secret_allowed' => false,
    'github_environment_private_secret_allowed' => false,
    'generation_location' => 'production_host_root_only',
    'kid_policy' =>
        'sg-ed25519-prefix-plus-first-16-hex-sha256-public-key',
    'private_bytes_must_never_cross' => [
        'github_actions',
        'github_artifacts',
        'ssh_stdout',
        'result_files',
        'audit_logs',
        'shared_dotenv',
    ],
    'promotion_order' => [
        'generate_staged_secret_on_host',
        'publish_higher_public_keyring_version',
        'validate_browser_public_keyring_distribution',
        'keep_issuance_disabled',
        'atomically_install_secret_environment_file',
        'restart_php_fpm',
        'set_active_kid',
        'validate_secret_public_key_kid_match',
        'enable_issuance_last',
    ],
    'rotation' => [
        'minimum_old_public_key_retention_seconds' => 28920,
        'restart_required_after_secret_change' => true,
        'reload_alone_is_sufficient' => false,
        'compromise_disables_issuance_first' => true,
        'compromised_secret_rollback_allowed' => false,
        'noncompromise_previous_private_retained_until_explicit_retirement' => true,
        'explicit_retirement_required_after_stable_cut' => true,
    ],
    'real_secret_creation_requires' => [
        'foundation_published_and_post_push_reconciled',
        'protected_main_exact_foundation',
        'production_target_release_alignment',
        'dedicated_operational_provisioning_authorization',
    ],
];
