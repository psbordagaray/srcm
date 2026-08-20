<?php

namespace App\Contracts\Resilience;

use App\Domain\Resilience\BackupEncryptionKeyMaterial;

interface BackupEncryptionKeyResolver
{
    public function resolve(): BackupEncryptionKeyMaterial;
}
