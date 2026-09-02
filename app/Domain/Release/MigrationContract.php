<?php

namespace App\Domain\Release;

use InvalidArgumentException;
use JsonException;

final class MigrationContract
{
    public const SCHEMA = 'straleon.migration-contract.v1';

    public const DATABASE_ENGINE_SQLITE = 'sqlite';

    public const PREVIOUS_RELEASE_COMPATIBLE = 'COMPATIBLE';

    public const PREVIOUS_RELEASE_INCOMPATIBLE = 'INCOMPATIBLE';

    public const PREVIOUS_RELEASE_UNKNOWN = 'UNKNOWN';

    /** @return list<string> */
    public static function previousReleaseCompatibilityValues(): array
    {
        return [
            self::PREVIOUS_RELEASE_COMPATIBLE,
            self::PREVIOUS_RELEASE_INCOMPATIBLE,
            self::PREVIOUS_RELEASE_UNKNOWN,
        ];
    }

    public function __construct(
        public readonly string $releaseSha,
        public readonly MigrationCatalog $targetCatalog,
        public readonly string $databaseEngine,
        public readonly MigrationCompatibility $compatibility,
        public readonly MigrationRiskClass $riskClass,
        public readonly bool $maintenanceRequired,
        public readonly bool $destructiveChange,
        public readonly bool $dataTransform,
        public readonly bool $verifiedBackupRequired,
        public readonly bool $restoreVerificationRequired,
        public readonly string $previousReleaseCompatibilityAfterMigration,
        public readonly bool $automaticDatabaseRollbackAllowed,
    ) {
        if (preg_match('/^[0-9a-f]{40}$/D', $releaseSha) !== 1) {
            throw new InvalidArgumentException(
                'Release SHA must be canonical lowercase 40-hex Git identity.'
            );
        }

        if ($databaseEngine !== self::DATABASE_ENGINE_SQLITE) {
            throw new InvalidArgumentException('Migration Contract V1 supports SQLite only.');
        }

        if (! in_array(
            $previousReleaseCompatibilityAfterMigration,
            self::previousReleaseCompatibilityValues(),
            true
        )) {
            throw new InvalidArgumentException(
                'Invalid previous release compatibility after migration.'
            );
        }

        if ($automaticDatabaseRollbackAllowed) {
            throw new InvalidArgumentException(
                'Automatic database rollback is forbidden by Migration Contract V1.'
            );
        }

        if ($this->requiresDatabaseMutation()) {
            if (! $verifiedBackupRequired || ! $restoreVerificationRequired) {
                throw new InvalidArgumentException(
                    'Database mutation requires verified backup and restore verification.'
                );
            }

            if ($riskClass === MigrationRiskClass::None) {
                throw new InvalidArgumentException(
                    'Database mutation cannot declare NONE migration risk.'
                );
            }
        }
    }

    public function requiresDatabaseMutation(): bool
    {
        return $this->compatibility !== MigrationCompatibility::NoSchemaChange
            || $this->destructiveChange
            || $this->dataTransform;
    }

    public function previousReleaseCompatibilityIsKnownSafe(): bool
    {
        return $this->previousReleaseCompatibilityAfterMigration
            === self::PREVIOUS_RELEASE_COMPATIBLE;
    }

    public function isFailClosedForActivation(): bool
    {
        if (! $this->previousReleaseCompatibilityIsKnownSafe()) {
            return true;
        }

        if ($this->requiresDatabaseMutation()) {
            return ! $this->verifiedBackupRequired
                || ! $this->restoreVerificationRequired;
        }

        return false;
    }

    /** @return array<string, bool|int|string> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'release_sha' => $this->releaseSha,
            'target_migration_catalog_sha256' => $this->targetCatalog->targetMigrationCatalogSha256,
            'target_migration_count' => $this->targetCatalog->targetMigrationCount,
            'database_engine' => $this->databaseEngine,
            'compatibility' => $this->compatibility->value,
            'risk_class' => $this->riskClass->value,
            'maintenance_required' => $this->maintenanceRequired,
            'destructive_change' => $this->destructiveChange,
            'data_transform' => $this->dataTransform,
            'verified_backup_required' => $this->verifiedBackupRequired,
            'restore_verification_required' => $this->restoreVerificationRequired,
            'previous_release_compatibility_after_migration' =>
                $this->previousReleaseCompatibilityAfterMigration,
            'automatic_database_rollback_allowed' => $this->automaticDatabaseRollbackAllowed,
        ];
    }

    /** @throws JsonException */
    public function canonicalJson(): string
    {
        return json_encode(
            $this->toArray(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        );
    }

    /** @throws JsonException */
    public function fingerprint(): string
    {
        return hash('sha256', $this->canonicalJson());
    }
}
