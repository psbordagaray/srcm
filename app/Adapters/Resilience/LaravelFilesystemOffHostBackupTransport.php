<?php

namespace App\Adapters\Resilience;

use App\Contracts\Resilience\OffHostBackupTransport;
use Illuminate\Filesystem\FilesystemManager;
use RuntimeException;

final class LaravelFilesystemOffHostBackupTransport implements OffHostBackupTransport
{
    public function __construct(private readonly FilesystemManager $filesystems)
    {
    }

    public function assertReady(): void
    {
        if (config('resilience.off_host.enabled') !== true) {
            throw new RuntimeException('SRCM off-host backup export is disabled.');
        }

        $disk = $this->diskName();
        $driver = config('filesystems.disks.'.$disk.'.driver');
        $allowed = config('resilience.off_host.allowed_remote_drivers', ['s3', 'sftp']);

        if (! is_string($driver) || ! is_array($allowed) || ! in_array($driver, $allowed, true)) {
            throw new RuntimeException('SRCM off-host backup disk must use an explicitly allowed remote driver.');
        }
    }

    public function putStream(string $path, $stream): void
    {
        $this->assertReady();
        if (! is_resource($stream)) {
            throw new RuntimeException('SRCM off-host backup upload stream is invalid.');
        }

        $ok = $this->filesystems
            ->disk($this->diskName())
            ->put($path, $stream, ['visibility' => 'private']);

        if ($ok !== true) {
            throw new RuntimeException('SRCM off-host backup upload failed.');
        }
    }

    public function readStream(string $path)
    {
        $this->assertReady();
        $stream = $this->filesystems
            ->disk($this->diskName())
            ->readStream($path);

        if (! is_resource($stream)) {
            throw new RuntimeException('SRCM off-host backup readback failed.');
        }

        return $stream;
    }

    public function delete(string $path): void
    {
        try {
            $this->filesystems
                ->disk($this->diskName())
                ->delete($path);
        } catch (\Throwable) {
            // Best-effort cleanup only. The original verification exception wins.
        }
    }

    private function diskName(): string
    {
        $disk = config('resilience.off_host.remote_disk');
        if (! is_string($disk) || trim($disk) === '') {
            throw new RuntimeException('SRCM off-host backup remote disk is not configured.');
        }

        return trim($disk);
    }
}
