<?php

namespace App\Domain\Resilience;

use RuntimeException;

final readonly class BackupEncryptionKeyMaterial
{
    public function __construct(
        public string $keyId,
        public string $key,
    ) {
        if (
            preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $this->keyId) !== 1
            || strlen($this->key) !== 32
        ) {
            throw new RuntimeException('Invalid SRCM backup encryption key material.');
        }
    }
}
