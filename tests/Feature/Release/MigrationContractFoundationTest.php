<?php

namespace Tests\Feature\Release;

use App\Domain\Release\MigrationCatalog;
use App\Domain\Release\MigrationCompatibility;
use App\Domain\Release\MigrationContract;
use App\Domain\Release\MigrationRiskClass;
use App\Domain\Release\ReleasePreflightInspector;
use InvalidArgumentException;
use Tests\TestCase;

final class MigrationContractFoundationTest extends TestCase
{
    public function test_migration_contract_policy_is_versioned_fail_closed_and_not_runtime_wired(): void
    {
        $policy = config('release.migration_contract');

        $this->assertIsArray($policy);
        $this->assertSame(1, $policy['foundation_version']);
        $this->assertSame(MigrationContract::SCHEMA, $policy['schema']);
        $this->assertSame(
            'srcm-{release_sha}.migration-contract.json',
            $policy['sidecar_filename_pattern']
        );
        $this->assertSame(
            'ordered_tracked_migration_path_plus_git_blob_sha',
            $policy['catalog_fingerprint_basis']
        );
        $this->assertSame(MigrationContract::DATABASE_ENGINE_SQLITE, $policy['database_engine']);
        $this->assertSame(MigrationCompatibility::values(), $policy['compatibility_values']);
        $this->assertSame(MigrationRiskClass::values(), $policy['risk_values']);
        $this->assertSame(
            MigrationContract::previousReleaseCompatibilityValues(),
            $policy['previous_release_compatibility_values']
        );
        $this->assertTrue($policy['unknown_previous_release_compatibility_fails_closed']);
        $this->assertTrue($policy['verified_backup_required_for_database_mutation']);
        $this->assertTrue($policy['restore_verification_required_for_database_mutation']);
        $this->assertFalse($policy['automatic_database_rollback_allowed']);
        $this->assertTrue($policy['destructive_and_data_transform_declaration_required']);
        $this->assertTrue($policy['target_pending_set_exact_match_required_before_migrate']);
        $this->assertTrue($policy['release_bound_backup_evidence_required_for_database_mutation']);
        $this->assertTrue($policy['release_bound_restore_evidence_required_for_database_mutation']);
        $this->assertTrue($policy['contract_is_immutable']);
        $this->assertTrue($policy['contract_sha256_required']);
        $this->assertTrue($policy['release_sha_exact_match_required']);
        $this->assertTrue($policy['secrets_forbidden']);
        $this->assertSame('foundation_only_not_yet_wired', $policy['runtime_wiring_status']);
        $this->assertTrue($policy['runtime_wiring_requires_separate_reviewed_cut']);

        $result = app(ReleasePreflightInspector::class)->inspect();
        $this->assertTrue($result['static']['p13_migration_contract_policy_contract']);
        $this->assertFalse($result['production_authorized']);
        $this->assertFalse(config('release.production_release_enabled'));
    }

    public function test_migration_catalog_is_canonical_and_deterministic(): void
    {
        $catalog = new MigrationCatalog(125, str_repeat('a', 64));

        $this->assertSame([
            'target_migration_catalog_sha256' => str_repeat('a', 64),
            'target_migration_count' => 125,
        ], $catalog->toArray());

        $same = new MigrationCatalog(125, str_repeat('a', 64));
        $this->assertSame(64, strlen($catalog->fingerprint()));
        $this->assertSame($catalog->fingerprint(), $same->fingerprint());
    }

    public function test_migration_catalog_rejects_noncanonical_digest(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MigrationCatalog(125, 'ABC123');
    }

    public function test_migration_contract_binds_release_catalog_and_explicit_risk_declarations(): void
    {
        $catalog = new MigrationCatalog(
            125,
            '8da12ecbf7c39ad57ca83519f7a644799f94881e9d18933ee6233d990448e223'
        );

        $contract = new MigrationContract(
            releaseSha: str_repeat('b', 40),
            targetCatalog: $catalog,
            databaseEngine: MigrationContract::DATABASE_ENGINE_SQLITE,
            compatibility: MigrationCompatibility::MaintenanceRequired,
            riskClass: MigrationRiskClass::Medium,
            maintenanceRequired: true,
            destructiveChange: false,
            dataTransform: true,
            verifiedBackupRequired: true,
            restoreVerificationRequired: true,
            previousReleaseCompatibilityAfterMigration:
                MigrationContract::PREVIOUS_RELEASE_COMPATIBLE,
            automaticDatabaseRollbackAllowed: false,
        );

        $payload = $contract->toArray();

        $this->assertSame(MigrationContract::SCHEMA, $payload['schema']);
        $this->assertSame(str_repeat('b', 40), $payload['release_sha']);
        $this->assertSame(
            $catalog->targetMigrationCatalogSha256,
            $payload['target_migration_catalog_sha256']
        );
        $this->assertSame(125, $payload['target_migration_count']);
        $this->assertSame('sqlite', $payload['database_engine']);
        $this->assertSame('MAINTENANCE_REQUIRED', $payload['compatibility']);
        $this->assertSame('MEDIUM', $payload['risk_class']);
        $this->assertTrue($payload['maintenance_required']);
        $this->assertFalse($payload['destructive_change']);
        $this->assertTrue($payload['data_transform']);
        $this->assertTrue($payload['verified_backup_required']);
        $this->assertTrue($payload['restore_verification_required']);
        $this->assertSame(
            'COMPATIBLE',
            $payload['previous_release_compatibility_after_migration']
        );
        $this->assertFalse($payload['automatic_database_rollback_allowed']);
        $this->assertTrue($contract->requiresDatabaseMutation());
        $this->assertFalse($contract->isFailClosedForActivation());
        $this->assertSame(64, strlen($contract->fingerprint()));
    }

    public function test_unknown_previous_release_compatibility_remains_fail_closed(): void
    {
        $contract = new MigrationContract(
            str_repeat('c', 40),
            new MigrationCatalog(125, str_repeat('d', 64)),
            MigrationContract::DATABASE_ENGINE_SQLITE,
            MigrationCompatibility::BackwardCompatible,
            MigrationRiskClass::Low,
            false,
            false,
            false,
            true,
            true,
            MigrationContract::PREVIOUS_RELEASE_UNKNOWN,
            false,
        );

        $this->assertTrue($contract->isFailClosedForActivation());
    }

    public function test_database_mutation_requires_verified_backup_and_restore_verification(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MigrationContract(
            str_repeat('e', 40),
            new MigrationCatalog(125, str_repeat('f', 64)),
            MigrationContract::DATABASE_ENGINE_SQLITE,
            MigrationCompatibility::BackwardCompatible,
            MigrationRiskClass::Low,
            false,
            false,
            false,
            true,
            false,
            MigrationContract::PREVIOUS_RELEASE_COMPATIBLE,
            false,
        );
    }

    public function test_automatic_database_rollback_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MigrationContract(
            str_repeat('1', 40),
            new MigrationCatalog(125, str_repeat('2', 64)),
            MigrationContract::DATABASE_ENGINE_SQLITE,
            MigrationCompatibility::NoSchemaChange,
            MigrationRiskClass::None,
            false,
            false,
            false,
            false,
            false,
            MigrationContract::PREVIOUS_RELEASE_COMPATIBLE,
            true,
        );
    }

    public function test_no_schema_change_contract_can_represent_no_database_mutation(): void
    {
        $contract = new MigrationContract(
            str_repeat('3', 40),
            new MigrationCatalog(125, str_repeat('4', 64)),
            MigrationContract::DATABASE_ENGINE_SQLITE,
            MigrationCompatibility::NoSchemaChange,
            MigrationRiskClass::None,
            false,
            false,
            false,
            false,
            false,
            MigrationContract::PREVIOUS_RELEASE_COMPATIBLE,
            false,
        );

        $this->assertFalse($contract->requiresDatabaseMutation());
        $this->assertFalse($contract->isFailClosedForActivation());
    }

    public function test_ci_preflight_exposes_migration_contract_foundation_without_authorizing_production(): void
    {
        $this->artisan('srcm:release-preflight --ci')
            ->expectsOutputToContain('STATIC_P13_MIGRATION_CONTRACT_POLICY_CONTRACT=GREEN')
            ->expectsOutputToContain('PRODUCTION_RELEASE_AUTHORIZED=NO')
            ->expectsOutputToContain('PRODUCTION_REMAINS_BLOCKED=YES')
            ->assertSuccessful();
    }
}
