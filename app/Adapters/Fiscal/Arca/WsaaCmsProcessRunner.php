<?php

namespace App\Adapters\Fiscal\Arca;

interface WsaaCmsProcessRunner
{
    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    public function run(
        array $command,
        array $environment,
        int $timeoutSeconds,
        string $operation
    ): void;
}
