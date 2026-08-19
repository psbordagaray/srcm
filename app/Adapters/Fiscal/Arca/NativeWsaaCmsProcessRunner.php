<?php

namespace App\Adapters\Fiscal\Arca;

use RuntimeException;

final class NativeWsaaCmsProcessRunner implements
    WsaaCmsProcessRunner
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
    ): void {
        $this->assertCommand(
            $command,
            $timeoutSeconds,
            $operation
        );

        $nullDevice =
            PHP_OS_FAMILY === 'Windows'
                ? 'NUL'
                : '/dev/null';

        $process = @proc_open(
            $command,
            [
                0 => [
                    'file',
                    $nullDevice,
                    'r',
                ],
                1 => [
                    'file',
                    $nullDevice,
                    'w',
                ],
                2 => [
                    'file',
                    $nullDevice,
                    'w',
                ],
            ],
            $pipes,
            base_path(),
            $environment,
            [
                'bypass_shell' => true,
            ]
        );

        if (! is_resource($process)) {
            throw new RuntimeException(
                'No se pudo iniciar la operación local WSAA.'
            );
        }

        $startedAt =
            microtime(true);
        $processId = null;
        $statusExitCode = null;

        while (true) {
            $status =
                proc_get_status(
                    $process
                );

            if (! is_array($status)) {
                @proc_terminate(
                    $process,
                    9
                );
                @proc_close(
                    $process
                );

                throw new RuntimeException(
                    'No se pudo observar la operación local WSAA.'
                );
            }

            if (
                $processId === null
                && isset($status['pid'])
            ) {
                $processId =
                    (int) $status['pid'];
            }

            if (! ($status['running'] ?? false)) {
                $candidate =
                    $status['exitcode']
                    ?? -1;

                if (
                    is_int($candidate)
                    && $candidate >= 0
                ) {
                    $statusExitCode =
                        $candidate;
                }

                break;
            }

            if (
                microtime(true)
                    - $startedAt
                > $timeoutSeconds
            ) {
                $this->terminateProcessTree(
                    $process,
                    $processId
                );

                throw new RuntimeException(
                    'La operación local WSAA excedió su tiempo máximo.'
                );
            }

            usleep(25000);
        }

        $closeExitCode =
            proc_close(
                $process
            );

        $exitCode =
            $statusExitCode
            ?? (
                is_int($closeExitCode)
                && $closeExitCode >= 0
                    ? $closeExitCode
                    : -1
            );

        if ($exitCode !== 0) {
            throw new RuntimeException(
                'La operación local WSAA no pudo completarse.'
            );
        }
    }

    /**
     * @param  list<string>  $command
     */
    private function assertCommand(
        array $command,
        int $timeoutSeconds,
        string $operation
    ): void {
        if (
            $command === []
            || $timeoutSeconds < 1
            || $timeoutSeconds > 300
            || preg_match(
                '/^[a-z0-9_.-]{1,64}$/D',
                $operation
            ) !== 1
        ) {
            throw new RuntimeException(
                'La operación local WSAA es inválida.'
            );
        }

        foreach ($command as $argument) {
            if (
                ! is_string($argument)
                || $argument === ''
                || str_contains(
                    $argument,
                    "\0"
                )
                || str_contains(
                    $argument,
                    "\r"
                )
                || str_contains(
                    $argument,
                    "\n"
                )
            ) {
                throw new RuntimeException(
                    'La operación local WSAA contiene un argumento inválido.'
                );
            }
        }
    }

    /**
     * @param  resource  $process
     */
    private function terminateProcessTree(
        mixed $process,
        ?int $processId
    ): void {
        if (
            PHP_OS_FAMILY === 'Windows'
            && is_int($processId)
            && $processId > 0
        ) {
            $killer = @proc_open(
                [
                    'taskkill.exe',
                    '/PID',
                    (string) $processId,
                    '/T',
                    '/F',
                ],
                [
                    0 => [
                        'file',
                        'NUL',
                        'r',
                    ],
                    1 => [
                        'file',
                        'NUL',
                        'w',
                    ],
                    2 => [
                        'file',
                        'NUL',
                        'w',
                    ],
                ],
                $killerPipes,
                base_path(),
                null,
                [
                    'bypass_shell' => true,
                ]
            );

            if (is_resource($killer)) {
                @proc_close(
                    $killer
                );
            }
        } else {
            @proc_terminate(
                $process,
                9
            );
        }

        @proc_close(
            $process
        );
    }
}
