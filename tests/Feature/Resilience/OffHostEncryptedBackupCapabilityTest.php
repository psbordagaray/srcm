<?php

namespace Tests\Feature\Resilience;

use App\Adapters\Resilience\LaravelFilesystemOffHostBackupTransport;
use App\Contracts\Resilience\OffHostBackupTransport;
use App\Domain\Resilience\OffHostEncryptedBackupExporter;
use App\Domain\Resilience\SqliteBackupManager;
use PDO;
use RuntimeException;
use Tests\TestCase;

final class OffHostEncryptedBackupCapabilityTest extends TestCase
{
    /** @var list<string> */
    private array $tempRoots = [];

    /** @var list<string> */
    private array $environmentKeys = [];

    protected function tearDown(): void
    {
        foreach ($this->environmentKeys as $name) {
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);
        }
        foreach (array_reverse($this->tempRoots) as $root) {
            $this->removeTree($root);
        }
        parent::tearDown();
    }

    public function test_verified_local_backup_is_exported_only_as_authenticated_ciphertext(): void
    {
        [$source] = $this->syntheticDatabase();
        $before = hash_file('sha256', $source);
        $local = app(SqliteBackupManager::class)->create();
        $transport = new InMemoryOffHostBackupTransport;
        $this->app->instance(OffHostBackupTransport::class, $transport);
        $this->configureEncryption();

        $result = app(OffHostEncryptedBackupExporter::class)
            ->export($local['filename']);

        $this->assertTrue($result['verified']);
        $this->assertSame($local['sha256'], $result['plaintext_sha256']);
        $this->assertStringEndsWith('.sqlite.srcmenc', $result['remote_key']);
        $this->assertArrayHasKey($result['remote_key'], $transport->objects);
        $remote = $transport->objects[$result['remote_key']];
        $this->assertStringStartsWith("SRCMEB1\n", $remote);
        $this->assertStringNotContainsString('SQLite format 3', $remote);
        $this->assertSame(hash('sha256', $remote), $result['ciphertext_sha256']);
        $this->assertSame($before, hash_file('sha256', $source));
    }

    public function test_remote_ciphertext_tampering_fails_closed_and_removes_unverified_object(): void
    {
        [$source] = $this->syntheticDatabase();
        $before = hash_file('sha256', $source);
        $local = app(SqliteBackupManager::class)->create();
        $transport = new InMemoryOffHostBackupTransport;
        $transport->tamperOnRead = true;
        $this->app->instance(OffHostBackupTransport::class, $transport);
        $this->configureEncryption();

        try {
            app(OffHostEncryptedBackupExporter::class)->export($local['filename']);
            $this->fail('Tampered off-host ciphertext must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('encrypted backup', strtolower($exception->getMessage()));
        } finally {
            $this->assertSame([], $transport->objects);
            $this->assertSame($before, hash_file('sha256', $source));
        }
    }

    public function test_backup_key_reference_must_be_env_backed_base64_32_bytes(): void
    {
        $this->syntheticDatabase();
        $local = app(SqliteBackupManager::class)->create();
        $transport = new InMemoryOffHostBackupTransport;
        $this->app->instance(OffHostBackupTransport::class, $transport);
        config([
            'resilience.off_host.enabled' => true,
            'resilience.off_host.encryption.key_id' => 'test-key-v1',
            'resilience.off_host.encryption.key_reference' => 'file:forbidden.key',
        ]);

        $this->expectException(RuntimeException::class);
        try {
            app(OffHostEncryptedBackupExporter::class)->export($local['filename']);
        } finally {
            $this->assertSame([], $transport->objects);
        }
    }

    public function test_default_transport_rejects_local_filesystem_driver_before_provider_io(): void
    {
        config([
            'resilience.off_host.enabled' => true,
            'resilience.off_host.remote_disk' => 'local',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('allowed remote driver');
        app(LaravelFilesystemOffHostBackupTransport::class)->assertReady();
    }

    public function test_evidenced_gate_does_not_enable_runtime_or_schedule_provider_io(): void
    {
        $this->assertFalse(config('resilience.off_host.enabled'));
        $this->assertTrue(config('release.external_gates.off_host_encrypted_backup'));

        $console = file_get_contents(base_path('routes/console.php'));
        $this->assertIsString($console);
        $this->assertStringNotContainsString('SrcmExportBackupOffHost', $console);

        $command = file_get_contents(
            app_path('Console/Commands/SrcmExportBackupOffHost.php')
        );
        $this->assertIsString($command);
        $this->assertStringContainsString('srcm:export-backup-off-host', $command);
    }

    private function configureEncryption(): void
    {
        $name = 'SRCM_TEST_BACKUP_ENCRYPTION_KEY';
        $encoded = base64_encode(str_repeat("\x42", 32));
        $_SERVER[$name] = $encoded;
        $_ENV[$name] = $encoded;
        putenv($name.'='.$encoded);
        $this->environmentKeys[] = $name;

        config([
            'resilience.off_host.enabled' => true,
            'resilience.off_host.remote_prefix' => 'tests/srcm/backups',
            'resilience.off_host.encryption.key_id' => 'test-key-v1',
            'resilience.off_host.encryption.key_reference' => 'env:'.$name,
            'resilience.off_host.encryption.chunk_bytes' => 65536,
        ]);
    }

    /** @return array{0:string,1:string} */
    private function syntheticDatabase(): array
    {
        $root = $this->tempRoot();
        $source = $root.DIRECTORY_SEPARATOR.'source.sqlite';
        $directory = $root.DIRECTORY_SEPARATOR.'backups';
        $pdo = new PDO('sqlite:'.$source);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT NOT NULL, batch INTEGER NOT NULL)');
        $pdo->exec("INSERT INTO migrations (migration, batch) VALUES ('one', 1), ('two', 1)");
        $pdo->exec('CREATE TABLE facts (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
        $pdo->exec("INSERT INTO facts (value) VALUES ('alpha'), ('beta')");
        $pdo = null;

        config([
            'resilience.backup.enabled' => true,
            'resilience.backup.connection' => 'sqlite',
            'resilience.backup.directory' => $directory,
            'resilience.backup.retention_count' => 168,
            'resilience.backup.freshness_minutes' => 90,
            'database.connections.sqlite.driver' => 'sqlite',
            'database.connections.sqlite.url' => null,
            'database.connections.sqlite.database' => $source,
        ]);

        return [$source, $directory];
    }

    private function tempRoot(): string
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR
            .'srcm-offhost-'.bin2hex(random_bytes(6));
        if (! mkdir($root, 0700, true) && ! is_dir($root)) {
            throw new RuntimeException('Unable to create off-host backup test temp directory.');
        }
        $this->tempRoots[] = $root;

        return $root;
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
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

final class InMemoryOffHostBackupTransport implements OffHostBackupTransport
{
    /** @var array<string,string> */
    public array $objects = [];

    public bool $tamperOnRead = false;

    public function assertReady(): void
    {
    }

    public function putStream(string $path, $stream): void
    {
        if (! is_resource($stream)) {
            throw new RuntimeException('Invalid test upload stream.');
        }
        $bytes = stream_get_contents($stream);
        if (! is_string($bytes)) {
            throw new RuntimeException('Unable to read test upload stream.');
        }
        $this->objects[$path] = $bytes;
    }

    public function readStream(string $path)
    {
        $bytes = $this->objects[$path] ?? null;
        if (! is_string($bytes)) {
            throw new RuntimeException('Missing test off-host object.');
        }
        if ($this->tamperOnRead && strlen($bytes) > 80) {
            $index = strlen($bytes) - 5;
            $bytes[$index] = chr(ord($bytes[$index]) ^ 1);
        }
        $stream = fopen('php://temp', 'w+b');
        if (! is_resource($stream)) {
            throw new RuntimeException('Unable to open test readback stream.');
        }
        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    }

    public function delete(string $path): void
    {
        unset($this->objects[$path]);
    }
}
