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

    /*
    |--------------------------------------------------------------------------
    | Protected-main inactive alignment authorization
    |--------------------------------------------------------------------------
    |
    | This one-time recovery/alignment switch is independent from both the
    | historical empty-releases bootstrap and normal active deployment. It may
    | only install the exact protected-main revision as a second immutable
    | INACTIVE release while current remains absent.
    |
    */
    'protected_main_inactive_alignment_enabled' => false,

    /*
    |--------------------------------------------------------------------------
    | P13.A Release Manifest V1
    |--------------------------------------------------------------------------
    |
    | The canonical manifest binds an exact Git revision and immutable artifact
    | digest to an explicit environment identity. This cut defines the contract
    | only; executable build/deploy wiring remains a separate reviewed cut.
    |
    */
    'release_manifest' => [
        'foundation_version' => 1,
        'schema' => 'straleon.release-manifest.v1',
        'sidecar_filename_pattern' => 'srcm-{release_sha}.manifest.json',
        'required_fields' => [
            'schema',
            'release_sha',
            'artifact_sha256',
            'source_ref',
            'environment_identity',
            'environment_fingerprint',
        ],
        'release_sha_format' => 'lowercase_hex_40',
        'artifact_sha256_format' => 'lowercase_hex_64',
        'source_ref' => 'refs/heads/main',
        'manifest_is_immutable' => true,
        'manifest_is_built_before_remote_io' => true,
        'manifest_is_sidecar_to_immutable_artifact' => true,
        'artifact_digest_embedded_in_manifest' => true,
        'manifest_sha256_required' => true,
        'manifest_and_artifact_must_be_transferred_together' => true,
        'environment_identity_required' => true,
        'secrets_forbidden' => true,
        'activation_requires_exact_manifest_match' => true,
        'executable_integration_status' => 'foundation_only_not_yet_wired',
        'executable_integration_requires_separate_reviewed_cut' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | P13.A Environment Identity V1
    |--------------------------------------------------------------------------
    |
    | Runtime installation identity and deployment generation are deliberately
    | sourced from protected shared state outside immutable release directories.
    | No production INSTALLATION_ID or generation is invented by source code.
    |
    */
    'environment_identity' => [
        'foundation_version' => 1,
        'schema' => 'straleon.environment-identity.v1',
        'required_fields' => [
            'schema',
            'environment_id',
            'installation_id',
            'organization_scope',
            'organization_id',
            'deployment_generation',
            'stable_node_name',
        ],
        'environment_id' => 'production',
        'organization_scope' => 'installation',
        'organization_id' => null,
        'stable_node_name' => 'straleon-prod-01',
        'protected_ref' => 'refs/heads/main',
        'identity_file_path' => '/srv/srcm/shared/release/environment-identity.json',
        'installation_id_source' => 'protected_runtime_identity_file',
        'deployment_generation_source' => 'protected_runtime_identity_file',
        'deployment_generation_minimum' => 1,
        'identity_file_must_be_outside_release_directories' => true,
        'identity_file_must_not_contain_secrets' => true,
        'live_target_match_required_before_remote_io' => true,
        'organization_scope_must_be_explicit' => true,
        'deployment_generation_must_be_monotonic' => true,
        'runtime_binding_status' => 'not_yet_provisioned',
        'runtime_binding_requires_separate_reviewed_cut' => true,
    ],


    /*
    |--------------------------------------------------------------------------
    | P13.A Migration Contract V1
    |--------------------------------------------------------------------------
    |
    | This foundation binds a release to an exact tracked migration catalog and
    | explicit compatibility, risk, downtime, destructive-change, data-transform,
    | backup and rollback declarations. Runtime sidecar construction, transfer and
    | exact pending-set enforcement remain a separate reviewed integration cut.
    |
    */
    'migration_contract' => [
        'foundation_version' => 1,
        'schema' => 'straleon.migration-contract.v1',
        'sidecar_filename_pattern' => 'srcm-{release_sha}.migration-contract.json',
        'required_fields' => [
            'schema',
            'release_sha',
            'target_migration_catalog_sha256',
            'target_migration_count',
            'database_engine',
            'compatibility',
            'risk_class',
            'maintenance_required',
            'destructive_change',
            'data_transform',
            'verified_backup_required',
            'restore_verification_required',
            'previous_release_compatibility_after_migration',
            'automatic_database_rollback_allowed',
        ],
        'catalog_fingerprint_basis' => 'ordered_tracked_migration_path_plus_git_blob_sha',
        'database_engine' => 'sqlite',
        'compatibility_values' => [
            'NO_SCHEMA_CHANGE',
            'BACKWARD_COMPATIBLE',
            'MAINTENANCE_REQUIRED',
            'BREAKING',
        ],
        'risk_values' => [
            'NONE',
            'LOW',
            'MEDIUM',
            'HIGH',
            'CRITICAL',
        ],
        'previous_release_compatibility_values' => [
            'COMPATIBLE',
            'INCOMPATIBLE',
            'UNKNOWN',
        ],
        'unknown_previous_release_compatibility_fails_closed' => true,
        'verified_backup_required_for_database_mutation' => true,
        'restore_verification_required_for_database_mutation' => true,
        'automatic_database_rollback_allowed' => false,
        'destructive_and_data_transform_declaration_required' => true,
        'target_pending_set_exact_match_required_before_migrate' => true,
        'release_bound_backup_evidence_required_for_database_mutation' => true,
        'release_bound_restore_evidence_required_for_database_mutation' => true,
        'contract_is_immutable' => true,
        'contract_sha256_required' => true,
        'release_sha_exact_match_required' => true,
        'secrets_forbidden' => true,
        'runtime_wiring_status' => 'foundation_only_not_yet_wired',
        'runtime_wiring_requires_separate_reviewed_cut' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | P13.A Release State Machine V1
    |--------------------------------------------------------------------------
    |
    | This foundation formalizes the release lifecycle as a typed, forward-only
    | state graph with explicit transition evidence. Runtime persistence and
    | deploy/workflow integration remain separate reviewed cuts.
    |
    */
    'release_state_machine' => [
        'foundation_version' => 1,
        'canonical_states' => \App\Domain\Release\ReleaseState::values(),
        'transitions' => \App\Domain\Release\ReleaseStateMachine::transitionMap(),
        'transition_evidence' => \App\Domain\Release\ReleaseStateMachine::evidenceMap(),
        'illegal_transitions_fail_closed' => true,
        'state_progression_is_forward_only' => true,
        'current_symlink_switch_does_not_commit_active_state' => true,
        'active_requires_post_activation_readiness' => true,
        'failed_ready_to_active_transition_keeps_candidate_ready' => true,
        'previous_active_remains_active_until_replacement_active_confirmed' => true,
        'previous_active_becomes_superseded_only_after_replacement_active_confirmed' => true,
        'active_uniqueness_required' => true,
        'retirement_requires_superseded_state' => true,
        'automatic_database_rollback_is_outside_state_machine' => true,
        'runtime_persistence_status' => 'foundation_only_not_yet_wired',
        'runtime_persistence_requires_separate_reviewed_cut' => true,
        'deploy_wiring_status' => 'foundation_only_not_yet_wired',
        'deploy_wiring_requires_separate_reviewed_cut' => true,
    ],

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
        'operating_governance' => [
            'foundation_version' => 1,
            'current_mode' => 'single_trusted_operator',
            'second_operator_status' => 'planned_not_yet_onboarded',
            'independent_second_reviewer_required_before_prevent_self_review' => true,
            'normal_release_remains_blocked_until_second_operator_onboarded' => true,
            'single_operator_mode_must_not_enable_production_release' => true,
        ],
        'recovery_anchor' => [
            'foundation_version' => 1,
            'required_before_sensitive_mutation' => true,
            'evidence_result_sha256_lock_required' => true,
            'git_identity_anchor_required' => true,
            'local_environment_integrity_anchor_required' => true,
            'database_canonical_integrity_anchor_required' => true,
            'verified_database_snapshot_required_before_database_mutation' => true,
            'post_mutation_failure_requires_reconciliation_before_retry' => true,
            'code_rollback_never_implies_database_rollback' => true,
            'previous_immutable_release_must_be_preserved' => true,
            'precommit_failure_may_restore_exact_anchor_automatically' => true,
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
        'protected_main_inactive_alignment' => [
            'foundation_version' => 4,
            'mode' => 'protected_main_inactive_alignment',
            'authorization_switch' => 'protected_main_inactive_alignment_enabled',
            'authorized_target_release_sha' => '3378ce249fb69e922ea218e1858e4efe8186e17d',
            'prior_authorization_sha' => '3d5984bee332cc6abb7f8456077db26e7998530a',
            'authorization_commit_must_directly_descend_from_prior_authorization' => true,
            'prior_authorization_must_directly_descend_from_target' => true,
            'authorization_commit_must_not_be_installed' => true,
            'target_release_must_remain_fail_closed' => true,
            'revocation_required_after_execution' => true,
            'authorization_revoked_after_execution' => true,
            'successful_execution_run_id' => 33414463206,
            'successful_execution_install_job_id' => 99562174859,
            'successful_execution_authorization_sha' => '725082eaa23572e1e9a03da2f8f059ddabeab700',
            'successful_execution_artifact_digest' => 'sha256:efc113d134d2fa3f170c764a52911de7976e6751f02b210f6ba5d0f0fe6c9f96',
            'successful_execution_state' => 'GREEN_TARGET_INSTALLED_INACTIVE_HISTORICAL_PRESERVED_CURRENT_ABSENT',
            'failed_dispatch_run_id' => 33388095599,
            'failed_dispatch_classification' => 'SETUP_ACTION_RESOLUTION_BEFORE_ANY_WORKFLOW_STEP',
            'failed_dispatch_must_not_be_rerun' => true,
            'historical_release_sha' => 'fad6f4ff0ddcffeca5230bf3bcbb604262e55dcc',
            'artifact_built_in_github_actions' => true,
            'artifact_build_is_pre_authorization' => true,
            'remote_install_is_environment_protected' => true,
            'requires_current_absent' => true,
            'requires_historical_release_present' => true,
            'requires_target_release_absent' => true,
            'preserves_historical_release' => true,
            'preserves_shared_state' => true,
            'migration_allowed' => false,
            'creates_current_symlink' => false,
            'starts_or_reloads_services' => false,
            'activation_is_separate_cut' => true,
        ],

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
        'production_environment_secrets_and_approvals' => true,
    ],
];
