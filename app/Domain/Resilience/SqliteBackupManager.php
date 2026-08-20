<?php

namespace App\Domain\Resilience;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

final class SqliteBackupManager
{
    /**
     * Create and verify a consistent SQLite snapshot, then apply retention.
     *
     * @return array{filename:string,sha256:string,verified:bool,pruned:int}
     */
    public function create(): array
    {
        if (config('resilience.backup.enabled') !== true) {
            throw new RuntimeException('SRCM database backups are disabled.');
        }

        $source = $this->sourcePath();
        $directory = $this->backupDirectory();
        $this->assertProductionDirectoryPolicy($directory);

        $sourceState = $this->inspect($source, false);
        $stamp = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format('Ymd\\THis\\Z');
        $token = bin2hex(random_bytes(4));
        $stem = 'srcm-db-'.$stamp.'-'.$token;
        $partial = $directory.DIRECTORY_SEPARATOR.$stem.'.partial.sqlite';
        $final = $directory.DIRECTORY_SEPARATOR.$stem.'.sqlite';
        $shaFile = $final.'.sha256';
        $manifestFile = $final.'.json';

        foreach ([$partial, $final, $shaFile, $manifestFile] as $path) {
            if (file_exists($path)) {
                throw new RuntimeException('Backup target already exists.');
            }
        }

        try {
            $pdo = $this->connect($source);
            $pdo->exec('PRAGMA busy_timeout = 5000');
            $quoted = $pdo->quote($partial);
            if (! is_string($quoted)) {
                throw new RuntimeException('Unable to quote backup target.');
            }
            $pdo->exec('VACUUM INTO '.$quoted);
            $pdo = null;

            $snapshotState = $this->inspect($partial, false);
            $this->assertSnapshotMatches($sourceState, $snapshotState);

            if (! rename($partial, $final)) {
                throw new RuntimeException('Unable to publish verified backup snapshot.');
            }

            $sha = hash_file('sha256', $final);
            if (! is_string($sha) || $sha === '') {
                throw new RuntimeException('Unable to hash backup snapshot.');
            }
            $this->writeAtomic(
                $shaFile,
                strtolower($sha).'  '.basename($final).PHP_EOL
            );

            $restoreState = $this->verifyCopiedRestore($final, $sha, null);
            $this->assertSnapshotMatches($sourceState, $restoreState);

            $verifiedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                ->format(DATE_ATOM);
            $manifest = [
                'version' => 1,
                'filename' => basename($final),
                'created_at' => $verifiedAt,
                'verified_at' => $verifiedAt,
                'sha256' => strtolower($sha),
                'schema_sha256' => $restoreState['schema_sha256'],
                'table_count' => $restoreState['table_count'],
                'migration_count' => $restoreState['migration_count'],
                'rpo_minutes' => $this->boundedPositiveInt(
                    config('resilience.objectives.rpo_minutes'),
                    60,
                    1,
                    1440
                ),
                'rto_minutes' => $this->boundedPositiveInt(
                    config('resilience.objectives.rto_minutes'),
                    240,
                    1,
                    10080
                ),
            ];
            $json = json_encode(
                $manifest,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
            $this->writeAtomic($manifestFile, $json.PHP_EOL);

            $pruned = $this->prune();

            return [
                'filename' => basename($final),
                'sha256' => strtolower($sha),
                'verified' => true,
                'pruned' => $pruned,
            ];
        } catch (Throwable $exception) {
            foreach ([$partial, $final, $shaFile, $manifestFile] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            throw $exception;
        }
    }

    /**
     * Verify an existing backup by copying it to an isolated temporary restore
     * target and opening that copy. The live database is never replaced.
     *
     * @return array{filename:string,sha256:string,verified:bool,schema_sha256:string,table_count:int,migration_count:int}
     */
    public function verifyRestore(?string $backup = null): array
    {
        $path = $this->resolveBackup($backup);
        $sha = $this->expectedSha($path);
        $manifest = $this->readManifest($path);
        $state = $this->verifyCopiedRestore($path, $sha, $manifest);

        return [
            'filename' => basename($path),
            'sha256' => strtolower($sha),
            'verified' => true,
            'schema_sha256' => $state['schema_sha256'],
            'table_count' => $state['table_count'],
            'migration_count' => $state['migration_count'],
        ];
    }

    public function isFreshVerifiedBackupAvailable(): bool
    {
        try {
            if (config('resilience.backup.enabled') !== true) {
                return false;
            }
            $directory = $this->backupDirectory();
            $this->assertProductionDirectoryPolicy($directory);
            $path = $this->latestBackupPath();
            if ($path === null) {
                return false;
            }

            $manifest = $this->readManifest($path);
            $shaFile = $path.'.sha256';
            if (! is_file($shaFile)) {
                return false;
            }

            $verifiedAt = $manifest['verified_at'] ?? null;
            if (! is_string($verifiedAt) || $verifiedAt === '') {
                return false;
            }

            $verified = new DateTimeImmutable($verifiedAt);
            $ageSeconds = time() - $verified->getTimestamp();
            if ($ageSeconds < -300) {
                return false;
            }

            $maxAgeMinutes = $this->boundedPositiveInt(
                config('resilience.backup.freshness_minutes'),
                90,
                1,
                10080
            );

            return $ageSeconds <= ($maxAgeMinutes * 60);
        } catch (Throwable) {
            return false;
        }
    }

    public function backupDirectory(): string
    {
        $configured = config('resilience.backup.directory');
        if (! is_string($configured) || trim($configured) === '') {
            throw new RuntimeException('SRCM backup directory is not configured.');
        }

        $directory = trim($configured);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create SRCM backup directory.');
        }

        $resolved = realpath($directory);
        if (! is_string($resolved) || $resolved === '') {
            throw new RuntimeException('Unable to resolve SRCM backup directory.');
        }

        return $resolved;
    }

    private function sourcePath(): string
    {
        $connection = config('resilience.backup.connection', 'sqlite');
        if (! is_string($connection) || $connection === '') {
            throw new RuntimeException('SRCM backup database connection is invalid.');
        }

        $driver = config('database.connections.'.$connection.'.driver');
        if ($driver !== 'sqlite') {
            throw new RuntimeException('SRCM backup baseline supports SQLite only.');
        }

        $url = config('database.connections.'.$connection.'.url');
        if (is_string($url) && trim($url) !== '') {
            throw new RuntimeException('SQLite DB_URL is not accepted by this backup baseline.');
        }

        $database = config('database.connections.'.$connection.'.database');
        if (! is_string($database) || trim($database) === '' || $database === ':memory:') {
            throw new RuntimeException('SRCM backup requires a physical SQLite database file.');
        }

        $resolved = realpath($database);
        if (! is_string($resolved) || ! is_file($resolved)) {
            throw new RuntimeException('SRCM SQLite database file is not available.');
        }

        return $resolved;
    }

    private function assertProductionDirectoryPolicy(string $directory): void
    {
        if (! app()->environment('production')) {
            return;
        }

        $base = realpath(base_path());
        if (! is_string($base) || $base === '') {
            throw new RuntimeException('Unable to resolve application base path.');
        }

        $dir = $this->normalizedComparablePath($directory);
        $root = rtrim($this->normalizedComparablePath($base), '/');

        if ($dir === $root || str_starts_with($dir, $root.'/')) {
            throw new RuntimeException(
                'Production backups must be stored outside the SRCM repository tree.'
            );
        }
    }

    private function normalizedComparablePath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        if (DIRECTORY_SEPARATOR === '\\') {
            $normalized = strtolower($normalized);
        }
        return rtrim($normalized, '/');
    }

    private function connect(string $path): PDO
    {
        return new PDO(
            'sqlite:'.$path,
            null,
            null,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 5,
            ]
        );
    }

    /**
     * @return array{schema_sha256:string,table_count:int,migration_count:int}
     */
    private function inspect(string $path, bool $fullIntegrity): array
    {
        $pdo = $this->connect($path);
        $pragma = $fullIntegrity ? 'PRAGMA integrity_check' : 'PRAGMA quick_check';
        $check = $pdo->query($pragma)->fetchColumn();
        if ($check !== 'ok') {
            throw new RuntimeException('SQLite backup integrity check failed.');
        }

        $schema = $pdo->query(
            "SELECT type, name, tbl_name, COALESCE(sql, '') AS sql\n"
            ."FROM sqlite_master\n"
            ."WHERE type IN ('table','index','trigger','view')\n"
            ."AND name NOT LIKE 'sqlite_%'\n"
            ."ORDER BY type, name"
        )->fetchAll(PDO::FETCH_ASSOC);

        $encoded = json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $tableCount = (int) $pdo->query(
            "SELECT COUNT(*) FROM sqlite_master "
            ."WHERE type='table' AND name NOT LIKE 'sqlite_%'"
        )->fetchColumn();

        $hasMigrations = (int) $pdo->query(
            "SELECT COUNT(*) FROM sqlite_master "
            ."WHERE type='table' AND name='migrations'"
        )->fetchColumn() === 1;
        $migrationCount = $hasMigrations
            ? (int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn()
            : 0;
        $pdo = null;

        return [
            'schema_sha256' => strtoupper(hash('sha256', $encoded)),
            'table_count' => $tableCount,
            'migration_count' => $migrationCount,
        ];
    }

    private function assertSnapshotMatches(array $source, array $snapshot): void
    {
        foreach (['schema_sha256', 'table_count', 'migration_count'] as $key) {
            if (($source[$key] ?? null) !== ($snapshot[$key] ?? null)) {
                throw new RuntimeException('Backup snapshot metadata mismatch: '.$key);
            }
        }
    }

    /**
     * @param array<string,mixed>|null $manifest
     * @return array{schema_sha256:string,table_count:int,migration_count:int}
     */
    private function verifyCopiedRestore(string $backup, string $sha, ?array $manifest): array
    {
        $actual = hash_file('sha256', $backup);
        if (! is_string($actual) || ! hash_equals(strtolower($sha), strtolower($actual))) {
            throw new RuntimeException('Backup checksum mismatch.');
        }

        $directory = $this->backupDirectory();
        $restore = $directory.DIRECTORY_SEPARATOR
            .'restore-check-'.bin2hex(random_bytes(6)).'.sqlite';

        try {
            if (! copy($backup, $restore)) {
                throw new RuntimeException('Unable to create isolated restore verification copy.');
            }

            $state = $this->inspect($restore, true);

            if ($manifest !== null) {
                foreach (['schema_sha256', 'table_count', 'migration_count'] as $key) {
                    if (($manifest[$key] ?? null) !== $state[$key]) {
                        throw new RuntimeException('Restore verification manifest mismatch: '.$key);
                    }
                }
            }

            return $state;
        } finally {
            if (is_file($restore)) {
                @unlink($restore);
            }
        }
    }

    private function expectedSha(string $backup): string
    {
        $path = $backup.'.sha256';
        $contents = @file_get_contents($path);
        if (! is_string($contents)) {
            throw new RuntimeException('Backup checksum sidecar is missing.');
        }

        $parts = preg_split('/\\s+/', trim($contents));
        $sha = strtolower((string) ($parts[0] ?? ''));
        if (preg_match('/^[a-f0-9]{64}$/', $sha) !== 1) {
            throw new RuntimeException('Backup checksum sidecar is invalid.');
        }

        return $sha;
    }

    /** @return array<string,mixed> */
    private function readManifest(string $backup): array
    {
        $contents = @file_get_contents($backup.'.json');
        if (! is_string($contents)) {
            throw new RuntimeException('Backup verification manifest is missing.');
        }

        $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($manifest)) {
            throw new RuntimeException('Backup verification manifest is invalid.');
        }
        if (($manifest['filename'] ?? null) !== basename($backup)) {
            throw new RuntimeException('Backup verification manifest filename mismatch.');
        }
        if (($manifest['sha256'] ?? null) !== $this->expectedSha($backup)) {
            throw new RuntimeException('Backup verification manifest checksum mismatch.');
        }

        return $manifest;
    }

    private function resolveBackup(?string $backup): string
    {
        if ($backup === null || trim($backup) === '') {
            $latest = $this->latestBackupPath();
            if ($latest === null) {
                throw new RuntimeException('No SRCM database backup is available.');
            }
            return $latest;
        }

        $name = trim($backup);
        if ($name !== basename($name) || str_contains($name, '/') || str_contains($name, '\\')) {
            throw new RuntimeException('Backup argument must be a filename in the configured directory.');
        }
        if (preg_match('/^srcm-db-\\d{8}T\\d{6}Z-[a-f0-9]{8}\\.sqlite$/', $name) !== 1) {
            throw new RuntimeException('Backup filename does not match the SRCM backup contract.');
        }

        $path = $this->backupDirectory().DIRECTORY_SEPARATOR.$name;
        if (! is_file($path)) {
            throw new RuntimeException('Requested SRCM backup does not exist.');
        }

        return $path;
    }

    private function latestBackupPath(): ?string
    {
        $files = glob($this->backupDirectory().DIRECTORY_SEPARATOR.'srcm-db-*.sqlite') ?: [];
        $files = array_values(array_filter($files, static fn (string $path): bool =>
            ! str_ends_with($path, '.partial.sqlite') && is_file($path)
        ));
        if ($files === []) {
            return null;
        }

        usort($files, static function (string $a, string $b): int {
            $mtime = ((int) filemtime($b)) <=> ((int) filemtime($a));
            return $mtime !== 0 ? $mtime : strcmp(basename($b), basename($a));
        });

        return $files[0];
    }

    private function prune(): int
    {
        $retention = $this->boundedPositiveInt(
            config('resilience.backup.retention_count'),
            168,
            1,
            10080
        );
        $files = glob($this->backupDirectory().DIRECTORY_SEPARATOR.'srcm-db-*.sqlite') ?: [];
        $files = array_values(array_filter($files, static fn (string $path): bool =>
            ! str_ends_with($path, '.partial.sqlite') && is_file($path)
        ));
        usort($files, static function (string $a, string $b): int {
            $mtime = ((int) filemtime($b)) <=> ((int) filemtime($a));
            return $mtime !== 0 ? $mtime : strcmp(basename($b), basename($a));
        });

        $pruned = 0;
        foreach (array_slice($files, $retention) as $path) {
            foreach ([$path, $path.'.sha256', $path.'.json'] as $candidate) {
                if (is_file($candidate) && ! @unlink($candidate)) {
                    throw new RuntimeException('Unable to prune expired SRCM backup artifact.');
                }
            }
            $pruned++;
        }

        return $pruned;
    }

    private function boundedPositiveInt(mixed $value, int $default, int $min, int $max): int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT);
        if ($int === false) {
            $int = $default;
        }
        if ($int < $min || $int > $max) {
            throw new RuntimeException('SRCM resilience numeric configuration is out of bounds.');
        }
        return $int;
    }

    private function writeAtomic(string $path, string $contents): void
    {
        $temporary = $path.'.tmp-'.bin2hex(random_bytes(4));
        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
                throw new RuntimeException('Unable to write SRCM backup metadata.');
            }
            if (! rename($temporary, $path)) {
                throw new RuntimeException('Unable to publish SRCM backup metadata.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
