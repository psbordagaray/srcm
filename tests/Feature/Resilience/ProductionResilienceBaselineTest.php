<?php

namespace Tests\Feature\Resilience;

use App\Domain\Resilience\SqliteBackupManager;
use PDO;
use RuntimeException;
use Tests\TestCase;

final class ProductionResilienceBaselineTest extends TestCase
{
    /** @var list<string> */
    private array $tempRoots = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->tempRoots) as $root) {
            $this->removeTree($root);
        }
        parent::tearDown();
    }

    public function test_resilience_policy_configuration_and_hourly_schedule_are_explicit(): void
    {
        $this->assertSame(168, config('resilience.backup.retention_count'));
        $this->assertSame(90, config('resilience.backup.freshness_minutes'));
        $this->assertSame(60, config('resilience.objectives.rpo_minutes'));
        $this->assertSame(240, config('resilience.objectives.rto_minutes'));

        $console = file_get_contents(base_path('routes/console.php'));
        $this->assertIsString($console);
        $this->assertStringContainsString('SrcmBackupDatabase::class', $console);
        $this->assertStringContainsString('->hourly()', $console);
        $this->assertStringContainsString("->environments('production')", $console);
        $this->assertStringContainsString('->withoutOverlapping(55)', $console);
        $this->assertStringContainsString('->evenInMaintenanceMode()', $console);
    }

    public function test_backup_is_consistent_verified_and_source_bytes_remain_unchanged(): void
    {
        [$source, $directory] = $this->syntheticDatabase();
        $before = hash_file('sha256', $source);

        $result = app(SqliteBackupManager::class)->create();

        $this->assertTrue($result['verified']);
        $this->assertSame($before, hash_file('sha256', $source));
        $backup = $directory.DIRECTORY_SEPARATOR.$result['filename'];
        $this->assertFileExists($backup);
        $this->assertFileExists($backup.'.sha256');
        $this->assertFileExists($backup.'.json');
        $this->assertSame($result['sha256'], hash_file('sha256', $backup));

        $verified = app(SqliteBackupManager::class)->verifyRestore($result['filename']);
        $this->assertTrue($verified['verified']);
        $this->assertSame(2, $verified['migration_count']);
        $this->assertSame(2, $verified['table_count']);
    }

    public function test_retention_keeps_only_newest_configured_snapshot_set(): void
    {
        [, $directory] = $this->syntheticDatabase();
        config(['resilience.backup.retention_count' => 2]);

        for ($i = 0; $i < 3; $i++) {
            app(SqliteBackupManager::class)->create();
            usleep(20000);
        }

        $backups = glob($directory.DIRECTORY_SEPARATOR.'srcm-db-*.sqlite') ?: [];
        $this->assertCount(2, $backups);
        foreach ($backups as $backup) {
            $this->assertFileExists($backup.'.sha256');
            $this->assertFileExists($backup.'.json');
        }
    }

    public function test_restore_verification_rejects_checksum_tampering_without_touching_live_source(): void
    {
        [$source, $directory] = $this->syntheticDatabase();
        $before = hash_file('sha256', $source);
        $result = app(SqliteBackupManager::class)->create();
        $backup = $directory.DIRECTORY_SEPARATOR.$result['filename'];

        file_put_contents($backup, 'tamper', FILE_APPEND);

        $this->expectException(RuntimeException::class);
        try {
            app(SqliteBackupManager::class)->verifyRestore($result['filename']);
        } finally {
            $this->assertSame($before, hash_file('sha256', $source));
        }
    }

    public function test_backup_rejects_memory_database_and_production_repo_local_target(): void
    {
        $root = $this->tempRoot();
        config([
            'resilience.backup.connection' => 'sqlite',
            'database.connections.sqlite.url' => null,
            'database.connections.sqlite.database' => ':memory:',
            'resilience.backup.directory' => $root,
        ]);

        try {
            app(SqliteBackupManager::class)->create();
            $this->fail('In-memory SQLite backup must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('physical SQLite', $exception->getMessage());
        }

        $source = $root.DIRECTORY_SEPARATOR.'source.sqlite';
        $this->createDatabase($source);
        config(['database.connections.sqlite.database' => $source]);
        $originalEnv = $this->app->environment();
        $this->app['env'] = 'production';
        config(['app.env' => 'production']);
        config(['resilience.backup.directory' => storage_path()]);

        try {
            app(SqliteBackupManager::class)->create();
            $this->fail('Production repo-local backup target must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('outside the SRCM repository', $exception->getMessage());
        } finally {
            $this->app['env'] = $originalEnv;
            config(['app.env' => $originalEnv]);
        }
    }

    public function test_commands_are_explicit_and_real_restore_is_not_exposed(): void
    {
        $backupCommand = file_get_contents(
            app_path('Console/Commands/SrcmBackupDatabase.php')
        );
        $verifyCommand = file_get_contents(
            app_path('Console/Commands/SrcmVerifyDatabaseBackup.php')
        );
        $this->assertIsString($backupCommand);
        $this->assertIsString($verifyCommand);
        $this->assertStringContainsString(
            "srcm:backup-database",
            $backupCommand
        );
        $this->assertStringContainsString(
            "srcm:verify-database-backup",
            $verifyCommand
        );
        $this->assertStringNotContainsString(
            "srcm:restore-database",
            $backupCommand.$verifyCommand
        );

        $controller = file_get_contents(
            app_path('Http/Controllers/ProductionReadinessController.php')
        );
        $this->assertIsString($controller);
        $this->assertStringContainsString("'verified_backup'", $controller);
        $this->assertStringContainsString('isFreshVerifiedBackupAvailable', $controller);
    }

    private function syntheticDatabase(): array
    {
        $root = $this->tempRoot();
        $source = $root.DIRECTORY_SEPARATOR.'source.sqlite';
        $directory = $root.DIRECTORY_SEPARATOR.'backups';
        $this->createDatabase($source);

        config([
            'resilience.backup.enabled' => true,
            'resilience.backup.connection' => 'sqlite',
            'resilience.backup.directory' => $directory,
            'resilience.backup.retention_count' => 168,
            'resilience.backup.freshness_minutes' => 90,
            'resilience.objectives.rpo_minutes' => 60,
            'resilience.objectives.rto_minutes' => 240,
            'database.connections.sqlite.driver' => 'sqlite',
            'database.connections.sqlite.url' => null,
            'database.connections.sqlite.database' => $source,
        ]);

        return [$source, $directory];
    }

    private function createDatabase(string $path): void
    {
        $pdo = new PDO('sqlite:'.$path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT NOT NULL, batch INTEGER NOT NULL)');
        $pdo->exec("INSERT INTO migrations (migration, batch) VALUES ('one', 1), ('two', 1)");
        $pdo->exec('CREATE TABLE facts (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
        $pdo->exec("INSERT INTO facts (value) VALUES ('alpha'), ('beta')");
        $pdo = null;
    }

    private function tempRoot(): string
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR
            .'srcm-resilience-'.bin2hex(random_bytes(6));
        if (! mkdir($root, 0700, true) && ! is_dir($root)) {
            throw new RuntimeException('Unable to create test temp directory.');
        }
        $this->tempRoots[] = $root;
        return $root;
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $items = scandir($path) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $candidate = $path.DIRECTORY_SEPARATOR.$item;
            if (is_dir($candidate)) {
                $this->removeTree($candidate);
            } else {
                @unlink($candidate);
            }
        }
        @rmdir($path);
    }
}
