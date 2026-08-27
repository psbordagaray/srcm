<?php

return [
    /*
    |--------------------------------------------------------------------------
    | P11 production release authorization
    |--------------------------------------------------------------------------
    |
    | Production remains fail-closed until both the external evidence gate and
    | this final global authorization switch are independently closed by later,
    | reviewed cuts. Merely versioning the deployment foundation never enables
    | a production release.
    |
    */
    'production_release_enabled' => false,

    /*
    |--------------------------------------------------------------------------
    | One-time initial application release bootstrap authorization
    |--------------------------------------------------------------------------
    |
    | This switch is independent from normal production activation. It may only
    | authorize the one-time installation of an immutable release while current
    | is absent and all production services remain stopped. A later cut must
    | explicitly activate current and the runtimes.
    |
    */
    'initial_application_release_bootstrap_enabled' => false,

    'post_deploy_readiness' => [
        'route_name' => 'api.health.ready',
        'uri' => 'api/health/ready',
        'method' => 'GET',
    ],

    'deployment' => [
        'foundation_version' => 2,
        'environment' => 'production',
        'target_class' => 'linux_vps_single_host',
        'transport' => 'github_actions_ssh',
        'manual_dispatch_required' => true,
        'approval_model' => 'github_environment_reviewer',
        'environment_governance' => [
            'foundation_version' => 1,
            'environment' => 'production',
            'minimum_required_reviewers' => 1,
            'prevent_self_review' => false,
            'bootstrap_self_review_temporarily_allowed' => true,
            'normal_release_requires_prevent_self_review' => true,
            'can_admins_bypass' => false,
            'protected_branches_only' => true,
            'required_secret_names' => [
                'TS_OAUTH_CLIENT_ID',
                'TS_AUDIENCE',
                'SRCM_DEPLOY_SSH_PRIVATE_KEY',
                'SRCM_DEPLOY_KNOWN_HOSTS',
            ],
            'required_variables' => [
                'SRCM_DEPLOY_HOST' => 'straleon-prod-01',
                'SRCM_DEPLOY_USER' => 'straleon-deploy',
                'SRCM_DEPLOY_PORT' => '22',
            ],
            'secret_values_must_never_be_read_or_logged' => true,
            'authorization_requires_live_policy_match' => true,
        ],
        'release_layout' => 'immutable_release_dirs_current_symlink_shared_state',
        'root' => '/srv/srcm',
        'releases_directory' => '/srv/srcm/releases',
        'current_symlink' => '/srv/srcm/current',
        'shared_directory' => '/srv/srcm/shared',
        'shared_dotenv' => '/srv/srcm/shared/.env',
        'shared_sqlite' => '/srv/srcm/shared/database/database.sqlite',
        'shared_storage' => '/srv/srcm/shared/storage',
        'backup_directory' => '/var/backups/srcm',
        'artifact_built_in_github_actions' => true,
        'target_node_required' => false,
        'target_composer_required' => false,
        'php_family' => '8.3',
        'web_runtime' => 'nginx_php_fpm',
        'queue_runtime' => 'systemd_queue_worker',
        'scheduler_runtime' => 'systemd_timer_schedule_run',
        'runtime_secret_store' => 'protected_host_env_outside_release_dirs',
        'github_secret_scope' => 'deploy_transport_only',
        'initial_data_cutover' => 'separate_explicit_restore_from_verified_backup',
        'migration_policy' => 'fresh_readiness_backup_then_migrate_force',
        'automatic_database_rollback' => false,
        'automatic_code_symlink_rollback' => true,
        'initial_application_release' => [
            'foundation_version' => 1,
            'mode' => 'one_time_inactive_bootstrap',
            'authorization_switch' => 'initial_application_release_bootstrap_enabled',
            'requires_current_absent' => true,
            'requires_releases_directory_empty' => true,
            'artifact_built_in_github_actions' => true,
            'artifact_build_is_pre_authorization' => true,
            'remote_install_is_environment_protected' => true,
            'expected_database_sha256' => 'b07434ffcaaea6c1be8373b2187e725dceb70be40bfbdc3571af5df5ba85595e',
            'expected_database_size_bytes' => 3694592,
            'expected_applied_migrations' => 122,
            'migration_allowed' => false,
            'creates_current_symlink' => false,
            'starts_services' => false,
            'public_readiness_check' => false,
            'activation_is_separate_cut' => true,
        ],
    ],

    'external_gates' => [
        'off_host_encrypted_backup' => true,
        'operational_restore_drill' => true,
        'production_environment_secrets_and_approvals' => false,
    ],
];
