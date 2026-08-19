<?php

namespace App\Domain\Fiscal;

use DomainException;

final class WsaaServiceName
{
    public const MIN_LENGTH = 3;
    public const MAX_LENGTH = 32;

    public static function assertValid(
        string $service
    ): void {
        if (
            preg_match(
                '/^[A-Za-z][A-Za-z0-9_]{2,31}$/D',
                $service
            ) !== 1
        ) {
            throw new DomainException(
                'El servicio WSAA debe cumplir el XSD: 3 a 32 caracteres, comenzar con letra y continuar sólo con letras, dígitos o guion bajo.'
            );
        }
    }
}
