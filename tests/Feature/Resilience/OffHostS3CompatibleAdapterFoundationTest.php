<?php

namespace Tests\Feature\Resilience;

use App\Contracts\Resilience\OffHostBackupTransport;
use Tests\TestCase;

final class OffHostS3CompatibleAdapterFoundationTest extends TestCase
{
    public function test_dedicated_backup_disk_is_s3_private_and_uses_only_backup_specific_credentials(): void
    {
        $disk = config('filesystems.disks.srcm_backup_s3');

        $this->assertIsArray($disk);
        $this->assertSame('s3', $disk['driver'] ?? null);
        $this->assertSame('private', $disk['visibility'] ?? null);
        $this->assertTrue($disk['throw'] ?? false);
        $this->assertFalse($disk['report'] ?? true);

        $source = (string) file_get_contents(config_path('filesystems.php'));
        $start = strpos($source, "'srcm_backup_s3' => [");
        $end = strpos($source, "\n\n    ],", $start);

        $this->assertIsInt($start);
        $this->assertIsInt($end);

        $dedicated = substr($source, $start, $end - $start);

        $this->assertStringContainsString("env('SRCM_BACKUP_S3_ACCESS_KEY_ID')", $dedicated);
        $this->assertStringContainsString("env('SRCM_BACKUP_S3_SECRET_ACCESS_KEY')", $dedicated);
        $this->assertStringContainsString("env('SRCM_BACKUP_S3_REGION'", $dedicated);
        $this->assertStringContainsString("env('SRCM_BACKUP_S3_BUCKET')", $dedicated);
        $this->assertStringContainsString("env('SRCM_BACKUP_S3_ENDPOINT')", $dedicated);
        $this->assertStringContainsString("'SRCM_BACKUP_S3_USE_PATH_STYLE_ENDPOINT'", $dedicated);
        $this->assertStringNotContainsString('AWS_ACCESS_KEY_ID', $dedicated);
        $this->assertStringNotContainsString('AWS_SECRET_ACCESS_KEY', $dedicated);
        $this->assertStringNotContainsString('AWS_BUCKET', $dedicated);
        $this->assertStringNotContainsString('AWS_ENDPOINT', $dedicated);

        $this->assertFalse(config('resilience.off_host.enabled'));
        $this->assertSame('srcm_backup_s3', config('resilience.off_host.remote_disk'));
    }

    public function test_s3_adapter_and_aws_sdk_are_locked_without_sftp(): void
    {
        $lock = json_decode(
            (string) file_get_contents(base_path('composer.lock')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $packages = collect($lock['packages'] ?? [])->keyBy('name');

        $this->assertTrue($packages->has('league/flysystem-aws-s3-v3'));
        $this->assertTrue($packages->has('aws/aws-sdk-php'));
        $this->assertFalse($packages->has('league/flysystem-sftp-v3'));

        $version = (string) ($packages->get('league/flysystem-aws-s3-v3')['version'] ?? '');
        $this->assertTrue(
            version_compare(ltrim($version, 'v'), '3.35.2', '>='),
            'Flysystem S3 adapter must remain at or above the reviewed 3.35.2 floor.',
        );
    }

    public function test_transport_accepts_dedicated_driver_without_provider_io(): void
    {
        config()->set('resilience.off_host.enabled', true);
        config()->set('resilience.off_host.remote_disk', 'srcm_backup_s3');

        $transport = app(OffHostBackupTransport::class);
        $transport->assertReady();

        $this->addToAssertionCount(1);
    }

    public function test_env_example_exposes_only_empty_backup_specific_secret_slots(): void
    {
        $example = (string) file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('SRCM_BACKUP_REMOTE_DISK=srcm_backup_s3', $example);
        $this->assertMatchesRegularExpression('/^SRCM_BACKUP_S3_ACCESS_KEY_ID=\s*$/m', $example);
        $this->assertMatchesRegularExpression('/^SRCM_BACKUP_S3_SECRET_ACCESS_KEY=\s*$/m', $example);
        $this->assertMatchesRegularExpression('/^SRCM_BACKUP_S3_BUCKET=\s*$/m', $example);
        $this->assertMatchesRegularExpression('/^SRCM_BACKUP_S3_ENDPOINT=\s*$/m', $example);
        $this->assertStringContainsString('SRCM_BACKUP_S3_REGION=us-east-1', $example);
        $this->assertStringContainsString('SRCM_BACKUP_S3_USE_PATH_STYLE_ENDPOINT=false', $example);
    }

    public function test_evidenced_gate_does_not_schedule_export_or_enable_production(): void
    {
        $release = (string) file_get_contents(config_path('release.php'));
        $console = (string) file_get_contents(base_path('routes/console.php'));

        $this->assertMatchesRegularExpression(
            "/'off_host_encrypted_backup'\s*=>\s*true/",
            $release,
        );
        $this->assertMatchesRegularExpression(
            "/'production_release_enabled'\s*=>\s*false/",
            $release,
        );
        $this->assertStringNotContainsString('srcm:export-backup-off-host', $console);
    }
}
