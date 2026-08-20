<?php

namespace App\Adapters\Resilience;

use App\Contracts\Resilience\BackupEncryptionKeyResolver;
use App\Domain\Resilience\BackupEncryptionKeyMaterial;
use RuntimeException;

final class EnvironmentBackupEncryptionKeyResolver implements BackupEncryptionKeyResolver
{
    public function resolve(): BackupEncryptionKeyMaterial
    {
        $keyId = config('resilience.off_host.encryption.key_id');
        $reference = config('resilience.off_host.encryption.key_reference');

        if (! is_string($keyId) || trim($keyId) === '') {
            throw new RuntimeException('SRCM backup encryption key id is not configured.');
        }
        if (! is_string($reference) || trim($reference) === '') {
            throw new RuntimeException('SRCM backup encryption key reference is not configured.');
        }

        $reference = trim($reference);
        if (preg_match('/^env:([A-Z][A-Z0-9_]{2,127})$/D', $reference, $matches) !== 1) {
            throw new RuntimeException('SRCM backup encryption key reference must use env:VARIABLE.');
        }

        $encoded = $this->environmentValue($matches[1]);
        if ($encoded === null) {
            throw new RuntimeException('SRCM backup encryption key environment value is unavailable.');
        }

        $key = base64_decode($encoded, true);
        if (! is_string($key) || strlen($key) !== 32) {
            throw new RuntimeException('SRCM backup encryption key must be base64 for exactly 32 bytes.');
        }

        return new BackupEncryptionKeyMaterial(trim($keyId), $key);
    }

    private function environmentValue(string $name): ?string
    {
        foreach ([
            $_SERVER[$name] ?? null,
            $_ENV[$name] ?? null,
            getenv($name),
        ] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
