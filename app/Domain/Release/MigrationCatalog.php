<?php

namespace App\Domain\Release;

use InvalidArgumentException;
use JsonException;

final class MigrationCatalog
{
    public function __construct(
        public readonly int $targetMigrationCount,
        public readonly string $targetMigrationCatalogSha256,
    ) {
        if ($targetMigrationCount < 0) {
            throw new InvalidArgumentException('Target migration count must not be negative.');
        }

        if (preg_match('/^[0-9a-f]{64}$/D', $targetMigrationCatalogSha256) !== 1) {
            throw new InvalidArgumentException(
                'Target migration catalog SHA-256 must be canonical lowercase 64-hex.'
            );
        }
    }

    /** @return array{target_migration_catalog_sha256:string,target_migration_count:int} */
    public function toArray(): array
    {
        return [
            'target_migration_catalog_sha256' => $this->targetMigrationCatalogSha256,
            'target_migration_count' => $this->targetMigrationCount,
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
