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

    'post_deploy_readiness' => [
        'route_name' => 'api.health.ready',
        'uri' => 'api/health/ready',
        'method' => 'GET',
    ],

    'deployment' => [
        'foundation_version' => 1,
        'environment' => 'production',
        'target_class' => 'linux_vps_single_host',
        'transport' => 'github_actions_ssh',
        'manual_dispatch_required' => true,
        'approval_model' => 'github_environment_reviewer',
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
    ],

    'external_gates' => [
        'off_host_encrypted_backup' => true,
        'operational_restore_drill' => true,
        'production_environment_secrets_and_approvals' => false,
    ],
];
