<?php

namespace App\Domain\Release;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ReleasePreflightInspector
{
    public function __construct(private readonly Router $router)
    {
    }

    /** @return array<string, mixed> */
    public function inspect(): array
    {
        $ciWorkflowBody = $this->fileBody(base_path('.github/workflows/ci.yml'));
        $deployWorkflowBody = $this->fileBody(base_path('.github/workflows/deploy-production.yml'));
        $deployScriptBody = $this->fileBody(base_path('ops/production/deploy-release.sh'));
        $bootstrapWorkflowBody = $this->fileBody(
            base_path('.github/workflows/bootstrap-production-initial-release.yml')
        );
        $bootstrapScriptBody = $this->fileBody(
            base_path('ops/production/bootstrap-initial-release.sh')
        );
        $queueUnitBody = $this->fileBody(base_path('ops/production/systemd/srcm-queue.service'));
        $schedulerServiceBody = $this->fileBody(base_path('ops/production/systemd/srcm-schedule.service'));
        $schedulerTimerBody = $this->fileBody(base_path('ops/production/systemd/srcm-schedule.timer'));
        $nginxBody = $this->fileBody(base_path('ops/production/nginx/srcm.conf'));

        $migrationFiles = $this->migrationFiles();
        $irreversible = [];
        foreach ($migrationFiles as $path) {
            if (! $this->hasNonEmptyDownMethod($path)) {
                $irreversible[] = basename($path);
            }
        }

        $ran = $this->ranMigrations();
        $migrationNames = array_map(
            static fn (string $path): string => pathinfo($path, PATHINFO_FILENAME),
            $migrationFiles
        );
        $pending = array_values(array_diff($migrationNames, $ran));
        sort($pending, SORT_STRING);

        $static = [
            'composer_lock' => is_file(base_path('composer.lock')),
            'package_lock' => is_file(base_path('package-lock.json')),
            'versioned_ci_workflow' => $ciWorkflowBody !== '',
            'ci_default_branch_push_coverage' => $this->workflowEventIncludesBranch(
                $ciWorkflowBody,
                'push',
                'main'
            ),
            'ci_default_branch_pull_request_coverage' => $this->workflowEventIncludesBranch(
                $ciWorkflowBody,
                'pull_request',
                'main'
            ),
            'ci_pinned_checkout' => str_contains(
                $ciWorkflowBody,
                'actions/checkout@11bd71901bbe5b1630ceea73d27597364c9af683'
            ),
            'ci_locked_composer_install' => str_contains($ciWorkflowBody, 'composer install'),
            'ci_locked_node_install' => str_contains($ciWorkflowBody, 'npm ci --ignore-scripts'),
            'ci_diff_check' => str_contains($ciWorkflowBody, 'git diff --check'),
            'ci_full_suite' => str_contains($ciWorkflowBody, 'composer test'),
            'ci_asset_build' => str_contains($ciWorkflowBody, 'npm run build'),
            'ci_release_preflight' => str_contains(
                $ciWorkflowBody,
                'php artisan srcm:release-preflight --ci'
            ),
            'p13_release_manifest_policy_contract' => $this->releaseManifestPolicyIsPresent(),
            'p13_environment_identity_policy_contract' => $this->environmentIdentityPolicyIsPresent(),
            'p13_migration_contract_policy_contract' => $this->migrationContractPolicyIsPresent(),
            'p13_release_state_machine_policy_contract' => $this->releaseStateMachinePolicyIsPresent(),
            'p13_capability_authorization_policy_contract' => $this->capabilityAuthorizationPolicyIsPresent(),
            'p13_numeric_integrity_policy_contract' => $this->numericIntegrityPolicyIsPresent(),
            'p13_numeric_money_boundary_adapter_policy_contract' => $this->numericMoneyBoundaryAdapterPolicyIsPresent(),
            'production_deploy_workflow' => $deployWorkflowBody !== '',
            'production_deploy_manual_only' => $this->deploymentWorkflowIsManualOnly($deployWorkflowBody),
            'production_deploy_protected_main_dispatch_identity' =>
                $this->workflowUsesProtectedMainDispatchIdentity($deployWorkflowBody, 1),
            'production_deploy_environment_gate' => str_contains(
                $deployWorkflowBody,
                'environment: production'
            ),
            'production_deploy_concurrency_gate' => str_contains(
                $deployWorkflowBody,
                'group: srcm-production-deploy'
            ) && str_contains($deployWorkflowBody, 'cancel-in-progress: false'),
            'production_deploy_dual_source_authorization' => str_contains(
                $deployWorkflowBody,
                "production_environment_secrets_and_approvals"
            ) && str_contains($deployWorkflowBody, "production_release_enabled"),
            'production_deploy_relative_checksum_contract' => str_contains(
                $deployWorkflowBody,
                'sha256sum "srcm-${RELEASE_SHA}.tar.gz"'
            ) && ! str_contains(
                $deployWorkflowBody,
                'sha256sum "$RUNNER_TEMP/srcm-${RELEASE_SHA}.tar.gz"'
            ),
            'production_deploy_runtime_secrets_excluded' => $this->workflowExcludesRuntimeSecrets(
                $deployWorkflowBody
            ),
            'production_deploy_private_tailscale_transport' =>
                $this->workflowUsesPrivateTailscaleTransport(
                    $deployWorkflowBody,
                    'Authorization boundary - fail closed before remote IO'
                ),
            'immutable_release_activation_contract' => $this->immutableReleaseContractIsPresent(
                $deployScriptBody
            ),
            'production_initial_bootstrap_workflow' => $bootstrapWorkflowBody !== '',
            'production_initial_bootstrap_manual_only' => $this->deploymentWorkflowIsManualOnly(
                $bootstrapWorkflowBody
            ),
            'production_initial_bootstrap_protected_main_dispatch_identity' =>
                $this->workflowUsesProtectedMainDispatchIdentity($bootstrapWorkflowBody, 2),
            'production_initial_bootstrap_environment_gate' => str_contains(
                $bootstrapWorkflowBody,
                'environment: production'
            ),
            'production_initial_bootstrap_concurrency_gate' => str_contains(
                $bootstrapWorkflowBody,
                'group: srcm-production-initial-bootstrap'
            ) && str_contains($bootstrapWorkflowBody, 'cancel-in-progress: false'),
            'production_initial_bootstrap_pre_authorization_artifact_handoff' =>
                $this->initialBootstrapWorkflowSeparatesBuildFromProtectedInstall(
                    $bootstrapWorkflowBody
                ),
            'production_initial_bootstrap_policy_contract' =>
                $this->initialBootstrapPolicyIsPresent(),
            'production_environment_governance_policy_contract' =>
                $this->productionEnvironmentGovernancePolicyIsPresent(),
            'production_normal_release_reviewer_hardening_guard' =>
                $this->normalProductionReleaseReviewerHardeningIsSafe(),
            'production_operating_governance_contract' =>
                $this->productionOperatingGovernancePolicyIsPresent(),
            'production_recovery_anchor_contract' =>
                $this->productionRecoveryAnchorPolicyIsPresent(),
            'production_initial_bootstrap_source_authorization' => str_contains(
                $bootstrapWorkflowBody,
                'initial_application_release_bootstrap_enabled'
            ) && str_contains(
                $bootstrapWorkflowBody,
                'production_environment_secrets_and_approvals'
            ) && str_contains($bootstrapWorkflowBody, 'production_release_enabled'),
            'production_initial_bootstrap_relative_checksum_contract' => str_contains(
                $bootstrapWorkflowBody,
                'sha256sum "$artifact" > "$artifact.sha256"'
            ) && ! str_contains(
                $bootstrapWorkflowBody,
                'sha256sum "$RUNNER_TEMP/$artifact"'
            ),
            'production_initial_bootstrap_runtime_secrets_excluded' => $this->workflowExcludesRuntimeSecrets(
                $bootstrapWorkflowBody
            ),
            'production_initial_bootstrap_private_tailscale_transport' =>
                $this->workflowUsesPrivateTailscaleTransport(
                    $bootstrapWorkflowBody,
                    'Authorization boundary - fail closed before bootstrap remote IO'
                ),
            'immutable_initial_bootstrap_contract' => $this->initialBootstrapContractIsPresent(
                $bootstrapScriptBody
            ),
            'production_runtime_units' => $this->runtimeUnitsArePresent(
                $queueUnitBody,
                $schedulerServiceBody,
                $schedulerTimerBody,
                $nginxBody
            ),
            'all_migrations_have_non_empty_down' => $irreversible === [],
            'post_deploy_readiness_contract' => $this->readinessContractIsPresent(),
        ];

        $external = [
            'production_release_switch' => config('release.production_release_enabled') === true,
            'off_host_encrypted_backup' => config(
                'release.external_gates.off_host_encrypted_backup'
            ) === true,
            'operational_restore_drill' => config(
                'release.external_gates.operational_restore_drill'
            ) === true,
            'production_environment_secrets_and_approvals' => config(
                'release.external_gates.production_environment_secrets_and_approvals'
            ) === true,
        ];

        $staticGreen = ! in_array(false, $static, true);
        $externalGreen = ! in_array(false, $external, true);
        $productionAuthorized = app()->environment('production')
            && $staticGreen
            && $externalGreen;

        return [
            'static' => $static,
            'external' => $external,
            'migration_files_count' => count($migrationFiles),
            'applied_migrations_count' => count($ran),
            'pending_migrations_count' => count($pending),
            'pending_migrations' => $pending,
            'irreversible_migrations' => $irreversible,
            'static_green' => $staticGreen,
            'external_green' => $externalGreen,
            'production_authorized' => $productionAuthorized,
        ];
    }

    private function releaseManifestPolicyIsPresent(): bool
    {
        $policy = config('release.release_manifest');
        if (! is_array($policy)) {
            return false;
        }

        return class_exists(ReleaseManifest::class)
            && ($policy['foundation_version'] ?? null) === 1
            && ($policy['schema'] ?? null) === ReleaseManifest::SCHEMA
            && ($policy['sidecar_filename_pattern'] ?? null) === 'srcm-{release_sha}.manifest.json'
            && ($policy['required_fields'] ?? null) === [
                'schema',
                'release_sha',
                'artifact_sha256',
                'source_ref',
                'environment_identity',
                'environment_fingerprint',
            ]
            && ($policy['release_sha_format'] ?? null) === 'lowercase_hex_40'
            && ($policy['artifact_sha256_format'] ?? null) === 'lowercase_hex_64'
            && ($policy['source_ref'] ?? null) === 'refs/heads/main'
            && ($policy['manifest_is_immutable'] ?? null) === true
            && ($policy['manifest_is_built_before_remote_io'] ?? null) === true
            && ($policy['manifest_is_sidecar_to_immutable_artifact'] ?? null) === true
            && ($policy['artifact_digest_embedded_in_manifest'] ?? null) === true
            && ($policy['manifest_sha256_required'] ?? null) === true
            && ($policy['manifest_and_artifact_must_be_transferred_together'] ?? null) === true
            && ($policy['environment_identity_required'] ?? null) === true
            && ($policy['secrets_forbidden'] ?? null) === true
            && ($policy['activation_requires_exact_manifest_match'] ?? null) === true
            && ($policy['executable_integration_status'] ?? null)
                === 'foundation_only_not_yet_wired'
            && ($policy['executable_integration_requires_separate_reviewed_cut'] ?? null) === true;
    }

    private function environmentIdentityPolicyIsPresent(): bool
    {
        $policy = config('release.environment_identity');
        if (! is_array($policy)) {
            return false;
        }

        return class_exists(EnvironmentIdentity::class)
            && ($policy['foundation_version'] ?? null) === 1
            && ($policy['schema'] ?? null) === EnvironmentIdentity::SCHEMA
            && ($policy['required_fields'] ?? null) === [
                'schema',
                'environment_id',
                'installation_id',
                'organization_scope',
                'organization_id',
                'deployment_generation',
                'stable_node_name',
            ]
            && ($policy['environment_id'] ?? null) === 'production'
            && ($policy['organization_scope'] ?? null) === EnvironmentIdentity::SCOPE_INSTALLATION
            && array_key_exists('organization_id', $policy)
            && $policy['organization_id'] === null
            && ($policy['stable_node_name'] ?? null) === 'straleon-prod-01'
            && ($policy['protected_ref'] ?? null) === 'refs/heads/main'
            && ($policy['identity_file_path'] ?? null)
                === '/srv/srcm/shared/release/environment-identity.json'
            && ($policy['installation_id_source'] ?? null)
                === 'protected_runtime_identity_file'
            && ($policy['deployment_generation_source'] ?? null)
                === 'protected_runtime_identity_file'
            && ($policy['deployment_generation_minimum'] ?? null) === 1
            && ($policy['identity_file_must_be_outside_release_directories'] ?? null) === true
            && ($policy['identity_file_must_not_contain_secrets'] ?? null) === true
            && ($policy['live_target_match_required_before_remote_io'] ?? null) === true
            && ($policy['organization_scope_must_be_explicit'] ?? null) === true
            && ($policy['deployment_generation_must_be_monotonic'] ?? null) === true
            && ($policy['runtime_binding_status'] ?? null) === 'not_yet_provisioned'
            && ($policy['runtime_binding_requires_separate_reviewed_cut'] ?? null) === true;
    }

    private function migrationContractPolicyIsPresent(): bool
    {
        $policy = config('release.migration_contract');
        if (! is_array($policy)) {
            return false;
        }

        return class_exists(MigrationContract::class)
            && class_exists(MigrationCatalog::class)
            && enum_exists(MigrationCompatibility::class)
            && enum_exists(MigrationRiskClass::class)
            && ($policy['foundation_version'] ?? null) === 1
            && ($policy['schema'] ?? null) === MigrationContract::SCHEMA
            && ($policy['sidecar_filename_pattern'] ?? null)
                === 'srcm-{release_sha}.migration-contract.json'
            && ($policy['required_fields'] ?? null) === [
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
            ]
            && ($policy['catalog_fingerprint_basis'] ?? null)
                === 'ordered_tracked_migration_path_plus_git_blob_sha'
            && ($policy['database_engine'] ?? null) === MigrationContract::DATABASE_ENGINE_SQLITE
            && ($policy['compatibility_values'] ?? null) === MigrationCompatibility::values()
            && ($policy['risk_values'] ?? null) === MigrationRiskClass::values()
            && ($policy['previous_release_compatibility_values'] ?? null)
                === MigrationContract::previousReleaseCompatibilityValues()
            && ($policy['unknown_previous_release_compatibility_fails_closed'] ?? null) === true
            && ($policy['verified_backup_required_for_database_mutation'] ?? null) === true
            && ($policy['restore_verification_required_for_database_mutation'] ?? null) === true
            && ($policy['automatic_database_rollback_allowed'] ?? null) === false
            && ($policy['destructive_and_data_transform_declaration_required'] ?? null) === true
            && ($policy['target_pending_set_exact_match_required_before_migrate'] ?? null) === true
            && ($policy['release_bound_backup_evidence_required_for_database_mutation'] ?? null) === true
            && ($policy['release_bound_restore_evidence_required_for_database_mutation'] ?? null) === true
            && ($policy['contract_is_immutable'] ?? null) === true
            && ($policy['contract_sha256_required'] ?? null) === true
            && ($policy['release_sha_exact_match_required'] ?? null) === true
            && ($policy['secrets_forbidden'] ?? null) === true
            && ($policy['runtime_wiring_status'] ?? null) === 'foundation_only_not_yet_wired'
            && ($policy['runtime_wiring_requires_separate_reviewed_cut'] ?? null) === true;
    }

    private function releaseStateMachinePolicyIsPresent(): bool
    {
        $policy = config('release.release_state_machine');
        if (! is_array($policy)) {
            return false;
        }

        return enum_exists(ReleaseState::class)
            && class_exists(ReleaseStateTransition::class)
            && class_exists(ReleaseStateMachine::class)
            && ($policy['foundation_version'] ?? null) === 1
            && ($policy['canonical_states'] ?? null) === ReleaseState::values()
            && ($policy['transitions'] ?? null) === ReleaseStateMachine::transitionMap()
            && ($policy['transition_evidence'] ?? null) === ReleaseStateMachine::evidenceMap()
            && ($policy['illegal_transitions_fail_closed'] ?? null) === true
            && ($policy['state_progression_is_forward_only'] ?? null) === true
            && ($policy['current_symlink_switch_does_not_commit_active_state'] ?? null) === true
            && ($policy['active_requires_post_activation_readiness'] ?? null) === true
            && ($policy['failed_ready_to_active_transition_keeps_candidate_ready'] ?? null) === true
            && ($policy['previous_active_remains_active_until_replacement_active_confirmed'] ?? null)
                === true
            && ($policy['previous_active_becomes_superseded_only_after_replacement_active_confirmed'] ?? null)
                === true
            && ($policy['active_uniqueness_required'] ?? null) === true
            && ($policy['retirement_requires_superseded_state'] ?? null) === true
            && ($policy['automatic_database_rollback_is_outside_state_machine'] ?? null) === true
            && ($policy['runtime_persistence_status'] ?? null)
                === 'foundation_only_not_yet_wired'
            && ($policy['runtime_persistence_requires_separate_reviewed_cut'] ?? null) === true
            && ($policy['deploy_wiring_status'] ?? null) === 'foundation_only_not_yet_wired'
            && ($policy['deploy_wiring_requires_separate_reviewed_cut'] ?? null) === true;
    }

    private function capabilityAuthorizationPolicyIsPresent(): bool
    {
        $policy = config('release.capability_authorization');
        if (! is_array($policy)) {
            return false;
        }

        return class_exists(\App\Domain\Authorization\Capability::class)
            && enum_exists(\App\Domain\Authorization\CapabilityScope::class)
            && enum_exists(\App\Domain\Authorization\CapabilityPrincipal::class)
            && enum_exists(\App\Domain\Authorization\CapabilityDecision::class)
            && class_exists(\App\Domain\Authorization\CapabilityAuthorizationContract::class)
            && ($policy['foundation_version'] ?? null) === 1
            && ($policy['schema'] ?? null)
                === \App\Domain\Authorization\CapabilityAuthorizationContract::SCHEMA
            && ($policy['required_fields'] ?? null)
                === \App\Domain\Authorization\CapabilityAuthorizationContract::REQUIRED_FIELDS
            && ($policy['capability_identifier_model'] ?? null)
                === 'namespaced_immutable_value_object'
            && ($policy['capability_identifier_pattern'] ?? null)
                === \App\Domain\Authorization\Capability::PATTERN
            && ($policy['wildcard_capabilities_allowed'] ?? null) === false
            && ($policy['unknown_or_invalid_capability_fails_closed'] ?? null) === true
            && ($policy['scope_values'] ?? null)
                === \App\Domain\Authorization\CapabilityScope::values()
            && ($policy['scope_must_be_explicit'] ?? null) === true
            && ($policy['principal_values'] ?? null)
                === \App\Domain\Authorization\CapabilityPrincipal::values()
            && ($policy['anonymous_principal_allowed'] ?? null) === false
            && ($policy['principal_id_required'] ?? null) === true
            && ($policy['principal_secret_material_allowed'] ?? null) === false
            && ($policy['decision_values'] ?? null)
                === \App\Domain\Authorization\CapabilityDecision::values()
            && ($policy['default_or_missing_decision'] ?? null)
                === \App\Domain\Authorization\CapabilityDecision::Deny->value
            && ($policy['allow_requires_authorization_source'] ?? null) === true
            && ($policy['allow_requires_evidence_ref'] ?? null) === true
            && ($policy['contract_is_immutable'] ?? null) === true
            && ($policy['contract_sha256_required'] ?? null) === true
            && ($policy['application_user_role_is_authorization_input_not_capability_id'] ?? null)
                === true
            && ($policy['laravel_gate_is_runtime_adapter_not_contract'] ?? null) === true
            && ($policy['production_environment_review_is_external_authority_not_application_role'] ?? null)
                === true
            && ($policy['application_admin_role_alone_can_authorize_production'] ?? null)
                === false
            && ($policy['authentication_and_authorization_are_separate'] ?? null) === true
            && ($policy['global_admin_bypass_allowed'] ?? null) === false
            && ($policy['provider_device_capabilities_are_not_principal_authorization'] ?? null)
                === true
            && ($policy['runtime_wiring_status'] ?? null)
                === 'foundation_only_not_yet_wired'
            && ($policy['runtime_wiring_requires_separate_reviewed_cut'] ?? null) === true
            && ($policy['user_role_refactor_status'] ?? null) === 'not_in_foundation_cut'
            && ($policy['laravel_gate_rewiring_status'] ?? null) === 'not_in_foundation_cut'
            && ($policy['production_workflow_wiring_status'] ?? null) === 'not_in_foundation_cut'
            && ($policy['deploy_script_wiring_status'] ?? null) === 'not_in_foundation_cut';
    }

    private function numericIntegrityPolicyIsPresent(): bool
    {
        $policy = config('release.numeric_integrity');

        if (! is_array($policy)) {
            return false;
        }

        return enum_exists(\App\Domain\Numerics\NumericKind::class)
            && class_exists(\App\Domain\Numerics\ExactDecimal::class)
            && enum_exists(\App\Domain\Numerics\NumericRoundingMode::class)
            && class_exists(\App\Domain\Numerics\NumericIntegrityContract::class)
            && class_exists(\App\Domain\Numerics\HumanNumericInput::class)
            && ($policy['foundation_version'] ?? null) === 1
            && ($policy['schema'] ?? null)
                === \App\Domain\Numerics\NumericIntegrityContract::SCHEMA
            && ($policy['numeric_kind_values'] ?? null)
                === \App\Domain\Numerics\NumericKind::values()
            && ($policy['canonical_decimal_pattern'] ?? null)
                === \App\Domain\Numerics\ExactDecimal::PATTERN
            && ($policy['canonical_decimal_representation'] ?? null)
                === 'exact_string_value_object'
            && ($policy['authoritative_financial_binary_float_allowed'] ?? null) === false
            && ($policy['scientific_notation_allowed'] ?? null) === false
            && ($policy['human_decimal_separator_values'] ?? null)
                === \App\Domain\Numerics\HumanNumericInput::SEPARATOR_VALUES
            && ($policy['ambiguous_human_decimal_input_policy'] ?? null)
                === 'reject_fail_closed'
            && ($policy['grouping_separators_allowed'] ?? null) === false
            && ($policy['silent_truncation_allowed'] ?? null) === false
            && ($policy['scale_overflow_policy'] ?? null) === 'deny_fail_closed'
            && ($policy['rounding_mode_values'] ?? null)
                === \App\Domain\Numerics\NumericRoundingMode::values()
            && ($policy['rounding_requires_named_mode'] ?? null) === true
            && ($policy['rounding_requires_defined_boundary'] ?? null) === true
            && ($policy['intermediate_rounding_allowed'] ?? null) === false
            && ($policy['money_scale_is_globally_two'] ?? null) === false
            && ($policy['money_scale_policy'] ?? null) === 'currency_or_domain_specific'
            && ($policy['quantity_scale_policy'] ?? null) === 'unit_or_domain_specific'
            && ($policy['count_must_be_exact_integer'] ?? null) === true
            && ($policy['raw_human_input_preservation_required_for_high_impact_manual_input'] ?? null)
                === true
            && ($policy['high_impact_manual_numeric_mutation_requires_reason'] ?? null) === true
            && ($policy['high_impact_manual_numeric_mutation_requires_evidence'] ?? null) === true
            && ($policy['high_impact_manual_numeric_mutation_requires_capability_authorization'] ?? null)
                === true
            && ($policy['authentication_or_role_alone_authorizes_numeric_override'] ?? null)
                === false
            && ($policy['runtime_calculation_refactor_status'] ?? null)
                === 'not_in_foundation_cut'
            && ($policy['model_cast_rewiring_status'] ?? null)
                === 'not_in_foundation_cut'
            && ($policy['database_schema_change_status'] ?? null)
                === 'not_in_foundation_cut'
            && ($policy['frontend_rewiring_status'] ?? null)
                === 'not_in_foundation_cut'
            && ($policy['import_rewiring_status'] ?? null)
                === 'not_in_foundation_cut'
            && ($policy['capability_runtime_wiring_status'] ?? null)
                === 'not_in_foundation_cut'
            && ($policy['runtime_integration_requires_separate_reviewed_cuts'] ?? null)
                === true;
    }

    private function numericMoneyBoundaryAdapterPolicyIsPresent(): bool
    {
        $policy = config(
            'release.numeric_integrity.money_boundary_adapter'
        );

        if (! is_array($policy)) {
            return false;
        }

        return class_exists(
            \App\Domain\Numerics\ExactDecimalLegacyAdapter::class
        )
            && class_exists(
                \App\Domain\Numerics\NumericRoundingBoundary::class
            )
            && class_exists(
                \App\Domain\Numerics\AuthoritativeNumericInput::class
            )
            && ($policy['foundation_version'] ?? null) === 1
            && ($policy['legacy_adapter_class'] ?? null)
                === \App\Domain\Numerics\ExactDecimalLegacyAdapter::class
            && ($policy['rounding_boundary_class'] ?? null)
                === \App\Domain\Numerics\NumericRoundingBoundary::class
            && ($policy['authoritative_input_class'] ?? null)
                === \App\Domain\Numerics\AuthoritativeNumericInput::class
            && ($policy['legacy_minor_unit_scale_must_be_explicit'] ?? null)
                === true
            && ($policy['legacy_minor_unit_automatic_rewrite_allowed'] ?? null)
                === false
            && ($policy['machine_canonical_binary_float_allowed'] ?? null)
                === false
            && ($policy['human_input_must_be_preparsed_by_human_numeric_input'] ?? null)
                === true
            && ($policy['rounding_boundary_must_be_explicit'] ?? null)
                === true
            && ($policy['rounding_scale_must_be_explicit'] ?? null)
                === true
            && array_key_exists('money_scale_global_default', $policy)
            && $policy['money_scale_global_default'] === null
            && ($policy['wave_1_target'] ?? null)
                === 'server_side_authoritative_money_boundaries'
            && ($policy['runtime_wiring_status'] ?? null)
                === 'foundation_only_not_yet_wired'
            && ($policy['runtime_wiring_requires_separate_reviewed_cut'] ?? null)
                === true
            && ($policy['purchase_money_rewrite_status'] ?? null)
                === 'not_in_foundation_cut'
            && ($policy['commerce_checkout_rewrite_status'] ?? null)
                === 'not_in_foundation_cut'
            && ($policy['mercado_pago_rewrite_status'] ?? null)
                === 'wired_v1_machine_canonical_exact_scale_2'
            && ($policy['service_cancellation_request_rewrite_status'] ?? null)
                === 'not_in_foundation_cut'
            && ($policy['financial_statement_date_serial_float_status'] ?? null)
                === 'non_money_float_outside_wave_1'
            && ($policy['database_schema_change_status'] ?? null)
                === 'not_in_foundation_cut'
            && ($policy['frontend_rewiring_status'] ?? null)
                === 'not_in_foundation_cut'
            && ($policy['import_rewiring_status'] ?? null)
                === 'not_in_foundation_cut'
            && ($policy['capability_runtime_wiring_status'] ?? null)
                === 'not_in_foundation_cut';
    }

    private function productionEnvironmentGovernancePolicyIsPresent(): bool

    {
        $policy = config('release.deployment.environment_governance');
        if (! is_array($policy)) {
            return false;
        }

        return ($policy['foundation_version'] ?? null) === 1
            && ($policy['environment'] ?? null) === 'production'
            && ($policy['minimum_required_reviewers'] ?? null) === 1
            && ($policy['prevent_self_review'] ?? null) === false
            && ($policy['bootstrap_self_review_temporarily_allowed'] ?? null) === true
            && ($policy['normal_release_requires_prevent_self_review'] ?? null) === true
            && ($policy['can_admins_bypass'] ?? null) === false
            && ($policy['protected_branches_only'] ?? null) === true
            && ($policy['required_secret_names'] ?? null) === [
                'TS_OAUTH_CLIENT_ID',
                'TS_AUDIENCE',
                'SRCM_DEPLOY_SSH_PRIVATE_KEY',
                'SRCM_DEPLOY_KNOWN_HOSTS',
            ]
            && ($policy['required_variables'] ?? null) === [
                'SRCM_DEPLOY_HOST' => 'straleon-prod-01',
                'SRCM_DEPLOY_USER' => 'straleon-deploy',
                'SRCM_DEPLOY_PORT' => '22',
            ]
            && ($policy['secret_values_must_never_be_read_or_logged'] ?? null) === true
            && ($policy['authorization_requires_live_policy_match'] ?? null) === true;
    }

    private function productionOperatingGovernancePolicyIsPresent(): bool
    {
        $policy = config('release.deployment.operating_governance');
        if (! is_array($policy)) {
            return false;
        }

        return ($policy['foundation_version'] ?? null) === 1
            && ($policy['current_mode'] ?? null) === 'single_trusted_operator'
            && ($policy['second_operator_status'] ?? null) === 'planned_not_yet_onboarded'
            && ($policy['independent_second_reviewer_required_before_prevent_self_review'] ?? null) === true
            && ($policy['normal_release_remains_blocked_until_second_operator_onboarded'] ?? null) === true
            && ($policy['single_operator_mode_must_not_enable_production_release'] ?? null) === true;
    }

    private function productionRecoveryAnchorPolicyIsPresent(): bool
    {
        $policy = config('release.deployment.recovery_anchor');
        if (! is_array($policy)) {
            return false;
        }

        return ($policy['foundation_version'] ?? null) === 1
            && ($policy['required_before_sensitive_mutation'] ?? null) === true
            && ($policy['evidence_result_sha256_lock_required'] ?? null) === true
            && ($policy['git_identity_anchor_required'] ?? null) === true
            && ($policy['local_environment_integrity_anchor_required'] ?? null) === true
            && ($policy['database_canonical_integrity_anchor_required'] ?? null) === true
            && ($policy['verified_database_snapshot_required_before_database_mutation'] ?? null) === true
            && ($policy['post_mutation_failure_requires_reconciliation_before_retry'] ?? null) === true
            && ($policy['code_rollback_never_implies_database_rollback'] ?? null) === true
            && ($policy['previous_immutable_release_must_be_preserved'] ?? null) === true
            && ($policy['precommit_failure_may_restore_exact_anchor_automatically'] ?? null) === true;
    }

    private function normalProductionReleaseReviewerHardeningIsSafe(): bool
    {
        if (config('release.production_release_enabled') !== true) {
            return true;
        }

        return config('release.deployment.environment_governance.prevent_self_review') === true
            && config('release.deployment.operating_governance.current_mode')
                === 'independent_second_operator'
            && config('release.deployment.operating_governance.second_operator_status')
                === 'onboarded_verified';
    }

    private function fileBody(string $path): string
    {
        if (! is_file($path)) {
            return '';
        }

        $body = file_get_contents($path);

        return is_string($body) ? $body : '';
    }

    private function workflowEventIncludesBranch(
        string $workflow,
        string $event,
        string $branch
    ): bool {
        if ($workflow === '') {
            return false;
        }

        $eventPattern = sprintf(
            '/(?ms)^  %s:\s*\R(?<body>.*?)(?=^  [A-Za-z_][A-Za-z0-9_-]*:\s*|^permissions:\s*|^concurrency:\s*|^jobs:\s*|\z)/',
            preg_quote($event, '/')
        );

        if (preg_match($eventPattern, $workflow, $match) !== 1) {
            return false;
        }

        $body = (string) ($match['body'] ?? '');
        if (preg_match('/(?m)^    branches:\s*$/', $body) !== 1) {
            return false;
        }

        $branchPattern = sprintf(
            '/(?m)^      -\s+["\']?%s["\']?\s*$/',
            preg_quote($branch, '/')
        );

        return preg_match($branchPattern, $body) === 1;
    }

    private function workflowUsesProtectedMainDispatchIdentity(
        string $workflow,
        int $expectedOccurrences
    ): bool {
        if ($workflow === '' || $expectedOccurrences < 1) {
            return false;
        }

        foreach ([
            'test "$GITHUB_REF_TYPE" = "branch"',
            'test "$GITHUB_REF_NAME" = "main"',
            'test "$GITHUB_REF_PROTECTED" = "true"',
            'test "${RELEASE_SHA_INPUT,,}" = "${GITHUB_SHA,,}"',
        ] as $required) {
            if (substr_count($workflow, $required) !== $expectedOccurrences) {
                return false;
            }
        }

        return true;
    }

    private function deploymentWorkflowIsManualOnly(string $workflow): bool
    {
        if ($workflow === '' || ! str_contains($workflow, 'workflow_dispatch:')) {
            return false;
        }

        return ! preg_match('/^\s{2}(push|pull_request|schedule):/m', $workflow);
    }

    private function initialBootstrapWorkflowSeparatesBuildFromProtectedInstall(
        string $workflow
    ): bool {
        if ($workflow === '') {
            return false;
        }

        $buildJob = strpos($workflow, '  build-initial-release-artifact:');
        $artifactBuild = strpos($workflow, 'Build immutable initial release artifact');
        $artifactUpload = strpos(
            $workflow,
            'actions/upload-artifact@ea165f8d65b6e75b540449e92b4886f43607fa02'
        );
        $installJob = strpos($workflow, '  install-inactive-initial-release:');
        $environmentGate = strpos($workflow, '    environment: production');
        $artifactDownload = strpos(
            $workflow,
            'actions/download-artifact@d3f86a106a0bac45b974a628896c90dbdf5c8093'
        );
        $authorization = strpos(
            $workflow,
            'Authorization boundary - fail closed before bootstrap remote IO'
        );
        $remoteIo = strpos($workflow, 'Configure SSH transport');

        foreach ([
            $buildJob,
            $artifactBuild,
            $artifactUpload,
            $installJob,
            $environmentGate,
            $artifactDownload,
            $authorization,
            $remoteIo,
        ] as $position) {
            if (! is_int($position)) {
                return false;
            }
        }

        return $buildJob < $artifactBuild
            && $artifactBuild < $artifactUpload
            && $artifactUpload < $installJob
            && $installJob < $environmentGate
            && $environmentGate < $artifactDownload
            && $artifactDownload < $authorization
            && $authorization < $remoteIo
            && str_contains($workflow, 'needs: build-initial-release-artifact');
    }

    private function initialBootstrapPolicyIsPresent(): bool
    {
        $policy = config('release.deployment.initial_application_release');
        if (! is_array($policy)) {
            return false;
        }

        return ($policy['foundation_version'] ?? null) === 1
            && ($policy['mode'] ?? null) === 'one_time_inactive_bootstrap'
            && ($policy['authorization_switch'] ?? null)
                === 'initial_application_release_bootstrap_enabled'
            && ($policy['requires_current_absent'] ?? null) === true
            && ($policy['requires_releases_directory_empty'] ?? null) === true
            && ($policy['artifact_built_in_github_actions'] ?? null) === true
            && ($policy['artifact_build_is_pre_authorization'] ?? null) === true
            && ($policy['remote_install_is_environment_protected'] ?? null) === true
            && ($policy['expected_database_sha256'] ?? null)
                === 'b07434ffcaaea6c1be8373b2187e725dceb70be40bfbdc3571af5df5ba85595e'
            && ($policy['expected_database_size_bytes'] ?? null) === 3694592
            && ($policy['expected_applied_migrations'] ?? null) === 122
            && ($policy['migration_allowed'] ?? null) === false
            && ($policy['creates_current_symlink'] ?? null) === false
            && ($policy['starts_services'] ?? null) === false
            && ($policy['public_readiness_check'] ?? null) === false
            && ($policy['activation_is_separate_cut'] ?? null) === true;
    }

    private function workflowUsesPrivateTailscaleTransport(
        string $workflow,
        string $authorizationBoundary
    ): bool {
        if ($workflow === '') {
            return false;
        }

        foreach ([
            'id-token: write',
            'tailscale/github-action@780049a30b6ff5c378a9e7b389d15ece7a204888',
            '${{ secrets.TS_OAUTH_CLIENT_ID }}',
            '${{ secrets.TS_AUDIENCE }}',
            'tag:straleon-ci-deploy',
            'test "$DEPLOY_HOST" = "straleon-prod-01"',
            'test "$DEPLOY_USER" = "straleon-deploy"',
            'test "$DEPLOY_PORT" = "22"',
            'tailscale ip --4 "$DEPLOY_HOST"',
            'tailscale whois --json "$resolved_deploy_ip"',
            '.Node.Name // empty',
            'tag:straleon-prod',
            '(.Node.Tags // [])',
            'ip route get "$resolved_deploy_ip"',
            'dev tailscale0',
            'RESOLVED_DEPLOY_IP',
            'known_key_material=',
            'test \"\$(hostname)\" = \"straleon-prod-01\"',
            'SHA256:x6L1N7kD+rcrlqD7EB+boZgwDQc4AtO6NMMltEHZhpw',
            'SHA256:iy4hCZtEYlqi3MjSxLFmX7cKPTFXXfecZultd7c2Xj4',
            'straleon-prod-01',
        ] as $required) {
            if (! str_contains($workflow, $required)) {
                return false;
            }
        }

        foreach ([
            '/(?m)^\h+test "\$DEPLOY_PORT" = "22"\h*\r?$/',
            '/(?m)^\h+test -n "\$SSH_PRIVATE_KEY"\h*\r?$/',
        ] as $requiredCommandLine) {
            if (preg_match($requiredCommandLine, $workflow) !== 1) {
                return false;
            }
        }

        if (
            str_contains($workflow, 'READINESS_URL')
            && preg_match(
                '/(?m)^\h+\[\[ "\$READINESS_URL" =~ \^https:\/\/ \]\]\h*\r?$/',
                $workflow
            ) !== 1
        ) {
            return false;
        }

        foreach (['64.176.3.12', '100.64.245.55'] as $forbiddenTarget) {
            if (str_contains($workflow, $forbiddenTarget)) {
                return false;
            }
        }

        if (substr_count(
            $workflow,
            'tailscale/github-action@780049a30b6ff5c378a9e7b389d15ece7a204888'
        ) !== 1) {
            return false;
        }

        $authorization = strpos($workflow, $authorizationBoundary);
        $tailscale = strpos(
            $workflow,
            'tailscale/github-action@780049a30b6ff5c378a9e7b389d15ece7a204888'
        );
        $resolve = strpos($workflow, 'tailscale ip --4 "$DEPLOY_HOST"');
        $whois = strpos($workflow, 'tailscale whois --json "$resolved_deploy_ip"');
        $route = strpos($workflow, 'ip route get "$resolved_deploy_ip"');
        $ssh = strpos($workflow, 'Configure SSH transport');

        foreach ([$authorization, $tailscale, $resolve, $whois, $route, $ssh] as $position) {
            if (! is_int($position)) {
                return false;
            }
        }

        return $authorization < $tailscale
            && $tailscale < $resolve
            && $resolve < $whois
            && $whois < $route
            && $route < $ssh;
    }

    private function workflowExcludesRuntimeSecrets(string $workflow): bool
    {
        if ($workflow === '') {
            return false;
        }

        foreach ([
            'SRCM_MERCADO_PAGO_CONNECTION_SECRETS_JSON',
            'SRCM_ARCA_WSAA_CREDENTIAL_REFERENCES_JSON',
            'SRCM_ARCA_WSAA_CREDENTIAL_ROOT',
            'SRCM_BACKUP_ENCRYPTION_KEY_REFERENCE',
            'SRCM_BACKUP_S3_ACCESS_KEY_ID',
            'SRCM_BACKUP_S3_SECRET_ACCESS_KEY',
        ] as $forbidden) {
            if (str_contains($workflow, $forbidden)) {
                return false;
            }
        }

        return str_contains($workflow, 'SRCM_DEPLOY_SSH_PRIVATE_KEY')
            && str_contains($workflow, 'SRCM_DEPLOY_KNOWN_HOSTS');
    }

    private function immutableReleaseContractIsPresent(string $script): bool
    {
        if ($script === '') {
            return false;
        }

        foreach ([
            '/srv/srcm/releases',
            '/srv/srcm/current',
            '/srv/srcm/shared',
            'database/database.sqlite',
            'migrate --force',
            'api/health/ready',
            'srcm-queue.service',
        ] as $required) {
            if (! str_contains($script, $required)) {
                return false;
            }
        }

        return ! str_contains($script, 'migrate:rollback');
    }

    private function initialBootstrapContractIsPresent(string $script): bool
    {
        if ($script === '') {
            return false;
        }

        foreach ([
            '/srv/srcm/releases',
            '/srv/srcm/current',
            '/srv/srcm/shared',
            'initial_current_must_be_absent',
            'initial_releases_directory_must_be_empty',
            'EXPECTED_DB_SHA256=b07434ffcaaea6c1be8373b2187e725dceb70be40bfbdc3571af5df5ba85595e',
            'EXPECTED_DB_SIZE=3694592',
            'EXPECTED_MIGRATIONS=122',
            'php artisan srcm:release-preflight --ci',
            'php artisan optimize',
            'mv "$incoming_release" "$final_release"',
            'SRCM_INITIAL_BOOTSTRAP_CURRENT=ABSENT',
            'SRCM_INITIAL_BOOTSTRAP_SERVICES=INACTIVE',
            'SRCM_INITIAL_BOOTSTRAP_MIGRATE=NO',
        ] as $required) {
            if (! str_contains($script, $required)) {
                return false;
            }
        }

        foreach ([
            'php artisan migrate',
            'systemctl start',
            'systemctl restart',
            'systemctl reload',
            'systemctl enable',
            'ln -s "$final_release" "$CURRENT"',
        ] as $forbidden) {
            if (str_contains($script, $forbidden)) {
                return false;
            }
        }

        return true;
    }

    private function runtimeUnitsArePresent(
        string $queueUnit,
        string $schedulerService,
        string $schedulerTimer,
        string $nginx
    ): bool {
        return str_contains($queueUnit, 'artisan queue:work')
            && str_contains($schedulerService, 'artisan schedule:run')
            && str_contains($schedulerTimer, 'OnCalendar=*-*-* *:*:00')
            && str_contains($nginx, '/run/php/php8.3-fpm.sock')
            && str_contains($nginx, '/srv/srcm/current/public');
    }

    /** @return list<string> */
    private function migrationFiles(): array
    {
        $files = glob(database_path('migrations/*.php')) ?: [];
        sort($files, SORT_STRING);

        return array_values($files);
    }

    /** @return list<string> */
    private function ranMigrations(): array
    {
        try {
            if (! Schema::hasTable('migrations')) {
                return [];
            }

            return DB::table('migrations')
                ->orderBy('migration')
                ->pluck('migration')
                ->map(static fn (mixed $value): string => (string) $value)
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    private function readinessContractIsPresent(): bool
    {
        $name = config('release.post_deploy_readiness.route_name');
        $uri = config('release.post_deploy_readiness.uri');
        $method = config('release.post_deploy_readiness.method');

        if (! is_string($name) || ! is_string($uri) || ! is_string($method)) {
            return false;
        }

        $route = $this->router->getRoutes()->getByName($name);
        if ($route === null) {
            return false;
        }

        return $route->uri() === $uri
            && in_array(strtoupper($method), $route->methods(), true);
    }

    private function hasNonEmptyDownMethod(string $path): bool
    {
        $source = file_get_contents($path);
        if (! is_string($source)) {
            return false;
        }

        $tokens = token_get_all($source);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }

            $nameIndex = $this->nextSignificantToken($tokens, $i + 1);
            if ($nameIndex === null) {
                continue;
            }

            if ($tokens[$nameIndex] === '&') {
                $nameIndex = $this->nextSignificantToken($tokens, $nameIndex + 1);
            }

            if ($nameIndex === null
                || ! is_array($tokens[$nameIndex])
                || $tokens[$nameIndex][0] !== T_STRING
                || strtolower($tokens[$nameIndex][1]) !== 'down') {
                continue;
            }

            for ($j = $nameIndex + 1; $j < $count; $j++) {
                if ($tokens[$j] !== '{') {
                    continue;
                }

                $depth = 1;
                $hasBody = false;
                for ($k = $j + 1; $k < $count && $depth > 0; $k++) {
                    $token = $tokens[$k];
                    if ($token === '{') {
                        $depth++;
                        $hasBody = true;
                        continue;
                    }
                    if ($token === '}') {
                        $depth--;
                        continue;
                    }
                    if ($depth <= 0) {
                        break;
                    }
                    if (is_array($token)
                        && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    $hasBody = true;
                }

                return $hasBody;
            }
        }

        return false;
    }

    /** @param array<int, array{0:int,1:string,2:int}|string> $tokens */
    private function nextSignificantToken(array $tokens, int $start): ?int
    {
        for ($i = $start, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_array($token)
                && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }
}
