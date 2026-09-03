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

    /*
    |--------------------------------------------------------------------------
    | P13.A Capability Authorization Contract V1
    |--------------------------------------------------------------------------
    |
    | Canonical capability authorization is explicit, scope-bound and fail-closed.
    | Application roles, Laravel Gates and production environment review remain
    | authority inputs/adapters; runtime rewiring is a separate reviewed cut.
    |
    */
    'capability_authorization' => [
        'foundation_version' => 1,
        'schema' => \App\Domain\Authorization\CapabilityAuthorizationContract::SCHEMA,
        'required_fields' => \App\Domain\Authorization\CapabilityAuthorizationContract::REQUIRED_FIELDS,
        'capability_identifier_model' => 'namespaced_immutable_value_object',
        'capability_identifier_pattern' => \App\Domain\Authorization\Capability::PATTERN,
        'wildcard_capabilities_allowed' => false,
        'unknown_or_invalid_capability_fails_closed' => true,
        'scope_values' => \App\Domain\Authorization\CapabilityScope::values(),
        'scope_must_be_explicit' => true,
        'principal_values' => \App\Domain\Authorization\CapabilityPrincipal::values(),
        'anonymous_principal_allowed' => false,
        'principal_id_required' => true,
        'principal_secret_material_allowed' => false,
        'decision_values' => \App\Domain\Authorization\CapabilityDecision::values(),
        'default_or_missing_decision' => \App\Domain\Authorization\CapabilityDecision::Deny->value,
        'allow_requires_authorization_source' => true,
        'allow_requires_evidence_ref' => true,
        'contract_is_immutable' => true,
        'contract_sha256_required' => true,
        'application_user_role_is_authorization_input_not_capability_id' => true,
        'laravel_gate_is_runtime_adapter_not_contract' => true,
        'production_environment_review_is_external_authority_not_application_role' => true,
        'application_admin_role_alone_can_authorize_production' => false,
        'authentication_and_authorization_are_separate' => true,
        'global_admin_bypass_allowed' => false,
        'provider_device_capabilities_are_not_principal_authorization' => true,
        'runtime_wiring_status' => 'foundation_only_not_yet_wired',
        'runtime_wiring_requires_separate_reviewed_cut' => true,
        'user_role_refactor_status' => 'not_in_foundation_cut',
        'laravel_gate_rewiring_status' => 'not_in_foundation_cut',
        'production_workflow_wiring_status' => 'not_in_foundation_cut',
        'deploy_script_wiring_status' => 'not_in_foundation_cut',
    ],

    /*
    |--------------------------------------------------------------------------
    | P13.B Numerical Integrity & Human Error Prevention V1
    |--------------------------------------------------------------------------
    |
    | Exact numerical meaning is explicit and fail-closed. This foundation
    | defines canonical decimal/input/rounding policy only; migration of current
    | calculations, casts, database schema, frontend and imports is separate.
    |
    */
    'numeric_integrity' => [
        'foundation_version' => 1,
        'schema' => \App\Domain\Numerics\NumericIntegrityContract::SCHEMA,
        'numeric_kind_values' => \App\Domain\Numerics\NumericKind::values(),
        'canonical_decimal_pattern' => \App\Domain\Numerics\ExactDecimal::PATTERN,
        'canonical_decimal_representation' => 'exact_string_value_object',
        'authoritative_financial_binary_float_allowed' => false,
        'scientific_notation_allowed' => false,
        'human_decimal_separator_values' => \App\Domain\Numerics\HumanNumericInput::SEPARATOR_VALUES,
        'ambiguous_human_decimal_input_policy' => 'reject_fail_closed',
        'grouping_separators_allowed' => false,
        'silent_truncation_allowed' => false,
        'scale_overflow_policy' => 'deny_fail_closed',
        'rounding_mode_values' => \App\Domain\Numerics\NumericRoundingMode::values(),
        'rounding_requires_named_mode' => true,
        'rounding_requires_defined_boundary' => true,
        'intermediate_rounding_allowed' => false,
        'money_scale_is_globally_two' => false,
        'money_scale_policy' => 'currency_or_domain_specific',
        'quantity_scale_policy' => 'unit_or_domain_specific',
        'count_must_be_exact_integer' => true,
        'raw_human_input_preservation_required_for_high_impact_manual_input' => true,
        'high_impact_manual_numeric_mutation_requires_reason' => true,
        'high_impact_manual_numeric_mutation_requires_evidence' => true,
        'high_impact_manual_numeric_mutation_requires_capability_authorization' => true,
        'authentication_or_role_alone_authorizes_numeric_override' => false,
        'check_digit' => [
            'foundation_version' => 1,
            'interface_class' => \App\Domain\Numerics\CheckDigitAlgorithm::class,
            'luhn_class' => \App\Domain\Numerics\LuhnCheckDigit::class,
            'luhn_identifier' => \App\Domain\Numerics\LuhnCheckDigit::IDENTIFIER,
            'input_policy' => 'non_empty_ascii_digits_only',
            'normalization_allowed' => false,
            'silent_repair_allowed' => false,
            'mathematical_validity_only' => true,
            'entity_validity_inference_allowed' => false,
            'runtime_wiring_status' => 'foundation_only_not_yet_wired',
            'runtime_wiring_requires_separate_reviewed_cut' => true,
        ],
        'discrepancy_framework' => [
            'foundation_version' => 1,
            'signal_schema' => \App\Domain\Numerics\NumericalDiscrepancySignal::SCHEMA,
            'kind_values' => \App\Domain\Numerics\NumericalDiscrepancyKind::values(),
            'confidence_values' => \App\Domain\Numerics\NumericalDiscrepancyConfidence::values(),
            'classifier_interface' => \App\Domain\Numerics\NumericalDiscrepancyClassifier::class,
            'analyzer_class' => \App\Domain\Numerics\NumericalDiscrepancyAnalyzer::class,
            'foundation_classifier_classes' => \App\Domain\Numerics\NumericalDiscrepancyAnalyzer::FOUNDATION_CLASSIFIERS,
            'classifier_pack_version' => 1,
            'classifier_pack_classes' => \App\Domain\Numerics\NumericalDiscrepancyAnalyzer::CLASSIFIER_PACK_V1,
            'classifier_pack_status' => 'implemented_v1_not_runtime_wired',
            'classifier_pack_runtime_wiring_requires_separate_reviewed_cut' => true,
            'multiple_signals_may_coexist' => true,
            'signal_priority_or_autocorrection_winner_allowed' => false,
            'structural_match_is_not_human_cause_proof' => true,
            'unique_structural_match_confidence' => \App\Domain\Numerics\NumericalDiscrepancyConfidence::High->value,
            'ambiguous_single_edit_match_confidence' => \App\Domain\Numerics\NumericalDiscrepancyConfidence::Medium->value,
            'separator_misplacement_requires_same_separator_symbol' => true,
            'separator_misplacement_requires_same_digit_sequence' => true,
            'generic_omission_classifier_must_not_infer_special_case' => true,
            'modulo_nine_classifier_order' => 'after_structural_classifiers',
            'transposition_modulo_nine_is_signal_only' => true,
            'transposition_modulo_nine_is_proof' => false,
            'silent_autocorrection_allowed' => false,
            'classifier_signal_is_not_correction' => true,
            'deterministic_rules_are_authoritative' => true,
            'ai_decision_authority_allowed' => false,
            'ai_explanation_may_summarize_deterministic_evidence' => true,
            'warning_audit_required' => true,
            'decision_audit_required' => true,
            'original_value_audit_required' => true,
            'final_value_audit_required' => true,
            'decision_evidence_foundation_version' => 1,
            'decision_evidence_schema' => \App\Domain\Numerics\NumericalDiscrepancyDecisionEvidence::SCHEMA,
            'decision_evidence_class' => \App\Domain\Numerics\NumericalDiscrepancyDecisionEvidence::class,
            'decision_values' => \App\Domain\Numerics\NumericalDiscrepancyDecision::values(),
            'decision_warning_code' => \App\Domain\Numerics\NumericalDiscrepancyDecisionEvidence::WARNING_CODE,
            'decision_requires_explicit_reason' => true,
            'decision_requires_at_least_one_signal' => true,
            'decision_signals_must_match_reference_observed' => true,
            'decision_signal_rule_ids_must_be_unique' => true,
            'decision_signal_order_is_deterministic' => true,
            'decision_original_value_equals_reference_value' => true,
            'decision_final_value_must_match_explicit_choice' => true,
            'decision_normalization_allowed' => false,
            'decision_silent_correction_allowed' => false,
            'decision_evidence_runtime_wiring_status' => 'foundation_only_not_yet_wired',
            'decision_evidence_runtime_wiring_requires_separate_reviewed_cut' => true,
            'decision_capability_authorization_wiring_status' => 'foundation_contract_defined_not_runtime_wired',
            'decision_audit_persistence_wiring_status' => 'audit_payload_foundation_defined_not_runtime_wired',
            'override_authorization_foundation_version' => 1,
            'override_authorization_schema' => \App\Domain\Numerics\NumericalDiscrepancyOverrideAuthorization::SCHEMA,
            'override_capability' => \App\Domain\Numerics\NumericalDiscrepancyOverrideAuthorization::CAPABILITY,
            'override_authorization_class' => \App\Domain\Numerics\NumericalDiscrepancyOverrideAuthorization::class,
            'override_audit_evidence_schema' => \App\Domain\Numerics\NumericalDiscrepancyOverrideAuditEvidence::SCHEMA,
            'override_audit_evidence_class' => \App\Domain\Numerics\NumericalDiscrepancyOverrideAuditEvidence::class,
            'override_warning_audit_event' => \App\Domain\Numerics\NumericalDiscrepancyOverrideAuditEvidence::WARNING_EVENT,
            'override_decision_audit_event' => \App\Domain\Numerics\NumericalDiscrepancyOverrideAuditEvidence::DECISION_EVENT,
            'override_requires_capability_allow' => true,
            'override_authorization_source_required' => true,
            'override_authorization_evidence_ref_required' => true,
            'override_authorization_fingerprint_required' => true,
            'override_warning_and_decision_are_separate_audit_events' => true,
            'override_audit_payload_preserves_reference_observed_final' => true,
            'override_audit_persistence_status' => 'foundation_only_not_yet_wired',
            'override_business_runtime_wiring_status' => 'not_in_foundation_cut',
            'commerce_settlement_decision_semantics_foundation_version' => 1,
            'commerce_settlement_decision_semantics_schema' => \App\Domain\Commerce\CommerceSettlementDiscrepancyDecisionSemantics::SCHEMA,
            'commerce_settlement_decision_semantics_class' => \App\Domain\Commerce\CommerceSettlementDiscrepancyDecisionSemantics::class,
            'commerce_settlement_reference_value_role' => \App\Domain\Commerce\CommerceSettlementDiscrepancyDecisionSemantics::REFERENCE_VALUE_ROLE,
            'commerce_settlement_observed_value_role' => \App\Domain\Commerce\CommerceSettlementDiscrepancyDecisionSemantics::OBSERVED_VALUE_ROLE,
            'commerce_settlement_current_mismatch_behavior' => \App\Domain\Commerce\CommerceSettlementDiscrepancyDecisionSemantics::CURRENT_MISMATCH_BEHAVIOR,
            'commerce_settlement_decision_semantics' => \App\Domain\Commerce\CommerceSettlementDiscrepancyDecisionSemantics::decisionSemantics(),
            'commerce_settlement_aggregate_signal_identifies_source_field' => false,
            'commerce_settlement_field_level_reanalysis_required_before_correction' => true,
            'commerce_settlement_accept_observed_business_mutation_target_status' => 'undefined_not_authorized',
            'commerce_settlement_accept_observed_runtime_authorized' => false,
            'commerce_settlement_accept_observed_may_change_system_total' => false,
            'commerce_settlement_accept_observed_may_rewrite_payment_or_receivable' => false,
            'commerce_settlement_runtime_wiring_status' => \App\Domain\Commerce\CommerceSettlementDiscrepancyDecisionSemantics::RUNTIME_WIRING_STATUS,
            'commerce_settlement_component_evidence_foundation_version' => 1,
            'commerce_settlement_component_evidence_schema' => \App\Domain\Commerce\CommerceSettlementComponentEvidence::SCHEMA,
            'commerce_settlement_component_evidence_class' => \App\Domain\Commerce\CommerceSettlementComponentEvidence::class,
            'commerce_settlement_component_types' => \App\Domain\Commerce\CommerceSettlementComponentEvidence::COMPONENT_TYPES,
            'commerce_settlement_payment_component_id_pattern' => \App\Domain\Commerce\CommerceSettlementComponentEvidence::PAYMENT_COMPONENT_ID_PATTERN,
            'commerce_settlement_receivable_component_id' => \App\Domain\Commerce\CommerceSettlementComponentEvidence::RECEIVABLE_COMPONENT_ID,
            'commerce_settlement_raw_human_input_required' => true,
            'commerce_settlement_original_canonical_value_required' => true,
            'commerce_settlement_minor_value_consistency_required' => true,
            'commerce_settlement_conditional_residual_formula' => \App\Domain\Commerce\CommerceSettlementComponentEvidence::CONDITIONAL_RESIDUAL_FORMULA,
            'commerce_settlement_conditional_residual_is_independent_fact' => false,
            'commerce_settlement_conditional_residual_assumption' => \App\Domain\Commerce\CommerceSettlementComponentEvidence::CONDITIONAL_RESIDUAL_ASSUMPTION,
            'commerce_settlement_multiple_component_candidates_may_coexist' => true,
            'commerce_settlement_component_priority_or_automatic_winner_allowed' => false,
            'commerce_settlement_component_analysis_proves_cause' => false,
            'commerce_settlement_automatic_field_correction_allowed' => false,
            'commerce_settlement_field_level_explicit_review_required' => true,
            'commerce_settlement_tendered_amount_is_settlement_component' => false,
            'commerce_settlement_component_evidence_runtime_wiring_status' => \App\Domain\Commerce\CommerceSettlementComponentEvidence::RUNTIME_WIRING_STATUS,
            'commerce_settlement_component_analysis_input_selection_status' => 'not_in_foundation_cut',
            'service_cancellation_reference_mapping_status' => 'deferred_until_independent_reference_defined',
            'transposition_by_omission_special_case_status' => 'undefined_no_implementation_exact_spec_required',
            'transposition_by_omission_implementation_allowed' => false,
            'runtime_wiring_status' => 'foundation_only_not_yet_wired',
            'runtime_wiring_requires_separate_reviewed_cut' => true,
        ],
        'money_boundary_adapter' => [
            'foundation_version' => 1,
            'legacy_adapter_class' => \App\Domain\Numerics\ExactDecimalLegacyAdapter::class,
            'rounding_boundary_class' => \App\Domain\Numerics\NumericRoundingBoundary::class,
            'authoritative_input_class' => \App\Domain\Numerics\AuthoritativeNumericInput::class,
            'legacy_minor_unit_scale_must_be_explicit' => true,
            'legacy_minor_unit_automatic_rewrite_allowed' => false,
            'machine_canonical_binary_float_allowed' => false,
            'human_input_must_be_preparsed_by_human_numeric_input' => true,
            'rounding_boundary_must_be_explicit' => true,
            'rounding_scale_must_be_explicit' => true,
            'money_scale_global_default' => null,
            'wave_1_target' => 'server_side_authoritative_money_boundaries',
            'runtime_wiring_status' => 'wired_v1_wave_1_closed',
            'runtime_wiring_requires_separate_reviewed_cut' => true,
            'purchase_money_rewrite_status' => 'existing_minor_unit_authority_no_runtime_rewrite_required',
            'commerce_checkout_rewrite_status' => 'wired_v1_human_parsed_exact_scale_2',
            'mercado_pago_rewrite_status' => 'wired_v1_machine_canonical_exact_scale_2',
            'service_cancellation_request_rewrite_status' => 'wired_v1_human_parsed_exact_scale_2',
            'financial_statement_date_serial_float_status' => 'non_money_float_outside_wave_1',
            'database_schema_change_status' => 'not_in_foundation_cut',
            'frontend_rewiring_status' => 'not_in_foundation_cut',
            'import_rewiring_status' => 'not_in_foundation_cut',
            'capability_runtime_wiring_status' => 'not_in_foundation_cut',
        ],
        'runtime_calculation_refactor_status' => 'not_in_foundation_cut',
        'model_cast_rewiring_status' => 'not_in_foundation_cut',
        'database_schema_change_status' => 'not_in_foundation_cut',
        'frontend_rewiring_status' => 'not_in_foundation_cut',
        'import_rewiring_status' => 'not_in_foundation_cut',
        'capability_runtime_wiring_status' => 'not_in_foundation_cut',
        'runtime_integration_requires_separate_reviewed_cuts' => true,
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
