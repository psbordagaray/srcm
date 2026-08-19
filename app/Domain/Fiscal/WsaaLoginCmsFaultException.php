<?php

namespace App\Domain\Fiscal;

use App\Enums\WsaaLoginCmsFaultDisposition;
use RuntimeException;

final class WsaaLoginCmsFaultException extends RuntimeException
{
    public const TRANSIENT_MIN_DELAY_SECONDS = 60;

    public readonly string $arcaCode;

    public readonly WsaaLoginCmsFaultDisposition $disposition;

    private function __construct(
        string $arcaCode,
        WsaaLoginCmsFaultDisposition $disposition
    ) {
        parent::__construct(
            'WSAA LoginCms devolvió un rechazo del proveedor.'
        );

        $this->arcaCode = $arcaCode;
        $this->disposition = $disposition;
    }

    public static function fromArcaCode(
        string $arcaCode
    ): self {
        $normalized = strtolower(
            trim($arcaCode)
        );

        if (
            preg_match(
                '/^[a-z][a-z0-9_.-]{1,63}$/D',
                $normalized
            ) !== 1
        ) {
            $normalized = 'unknown';
        }

        $disposition =
            str_starts_with(
                $normalized,
                'wsaa.'
            )
            || $normalized === 'wsn.unavailable'
                ? WsaaLoginCmsFaultDisposition::
                    TransientNotBefore60Seconds
                : WsaaLoginCmsFaultDisposition::
                    ActionRequiredNoAutomaticRetry;

        return new self(
            $normalized,
            $disposition
        );
    }

    public function retryNotBeforeSeconds(): ?int
    {
        return $this->disposition
            === WsaaLoginCmsFaultDisposition::
                TransientNotBefore60Seconds
            ? self::TRANSIENT_MIN_DELAY_SECONDS
            : null;
    }
}
