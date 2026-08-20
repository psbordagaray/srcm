<?php

namespace App\Contracts\Resilience;

interface OffHostBackupTransport
{
    public function assertReady(): void;

    /** @param resource $stream */
    public function putStream(string $path, $stream): void;

    /** @return resource */
    public function readStream(string $path);

    public function delete(string $path): void;
}
