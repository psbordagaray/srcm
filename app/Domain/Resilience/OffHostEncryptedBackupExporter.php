<?php

namespace App\Domain\Resilience;

use App\Contracts\Resilience\BackupEncryptionKeyResolver;
use App\Contracts\Resilience\OffHostBackupTransport;
use RuntimeException;

final class OffHostEncryptedBackupExporter
{
    public function __construct(
        private readonly SqliteBackupManager $backups,
        private readonly AuthenticatedBackupEnvelope $envelope,
        private readonly BackupEncryptionKeyResolver $keys,
        private readonly OffHostBackupTransport $transport,
    ) {
    }

    /**
     * @return array{remote_key:string,plaintext_sha256:string,ciphertext_sha256:string,key_id:string,verified:bool}
     */
    public function export(?string $backup = null): array
    {
        if (config('resilience.off_host.enabled') !== true) {
            throw new RuntimeException('SRCM off-host backup export is disabled.');
        }

        $this->transport->assertReady();
        $verified = $this->backups->verifyRestore($backup);
        $filename = $verified['filename'];
        $source = $this->backups->backupDirectory().DIRECTORY_SEPARATOR.$filename;
        $material = $this->keys->resolve();
        $remoteKey = $this->remotePrefix().'/'.$filename.'.srcmenc';

        $base = tempnam(sys_get_temp_dir(), 'srcm-offhost-');
        if (! is_string($base) || $base === '') {
            throw new RuntimeException('Unable to allocate SRCM encrypted backup temporary path.');
        }
        @unlink($base);
        $encrypted = $base.'.srcmenc';
        $uploaded = false;

        try {
            $localEnvelope = $this->envelope->encryptFile(
                $source,
                $encrypted,
                $material,
                $filename,
            );
            if (! hash_equals($verified['sha256'], $localEnvelope['plaintext_sha256'])) {
                throw new RuntimeException('SRCM encrypted export plaintext checksum changed before upload.');
            }

            $upload = fopen($encrypted, 'rb');
            if (! is_resource($upload)) {
                throw new RuntimeException('Unable to open SRCM encrypted backup for upload.');
            }
            try {
                $this->transport->putStream($remoteKey, $upload);
                $uploaded = true;
            } finally {
                fclose($upload);
            }

            $remote = $this->transport->readStream($remoteKey);
            try {
                $remoteEnvelope = $this->envelope->verifyStream($remote, $material);
            } finally {
                fclose($remote);
            }

            foreach (['plaintext_sha256', 'ciphertext_sha256'] as $key) {
                if (! hash_equals($localEnvelope[$key], $remoteEnvelope[$key])) {
                    throw new RuntimeException('SRCM off-host encrypted backup readback checksum mismatch.');
                }
            }
            if ($remoteEnvelope['filename'] !== $filename || $remoteEnvelope['key_id'] !== $material->keyId) {
                throw new RuntimeException('SRCM off-host encrypted backup readback metadata mismatch.');
            }

            return [
                'remote_key' => $remoteKey,
                'plaintext_sha256' => $remoteEnvelope['plaintext_sha256'],
                'ciphertext_sha256' => $remoteEnvelope['ciphertext_sha256'],
                'key_id' => $material->keyId,
                'verified' => true,
            ];
        } catch (\Throwable $exception) {
            if ($uploaded) {
                $this->transport->delete($remoteKey);
            }
            throw $exception;
        } finally {
            if (is_file($encrypted)) {
                @unlink($encrypted);
            }
        }
    }

    private function remotePrefix(): string
    {
        $prefix = config('resilience.off_host.remote_prefix', 'srcm/backups/database');
        if (! is_string($prefix)) {
            throw new RuntimeException('SRCM off-host backup remote prefix is invalid.');
        }
        $prefix = trim($prefix, " \t\n\r\0\x0B/");
        if ($prefix === ''
            || str_contains($prefix, '..')
            || preg_match('#^[A-Za-z0-9._/-]{1,240}$#D', $prefix) !== 1) {
            throw new RuntimeException('SRCM off-host backup remote prefix is invalid.');
        }

        return $prefix;
    }
}
