<?php

namespace App\Domain\Catalog;

use DomainException;

final class SemanticKey
{
    public const MAX_LENGTH = 160;

    public static function assertValid(string $key): void
    {
        if (
            $key === ''
            || strlen($key) > self::MAX_LENGTH
            || preg_match(
                '/^[a-z0-9]+(?:_[a-z0-9]+)*(?:\.[a-z0-9]+(?:_[a-z0-9]+)*)+$/D',
                $key
            ) !== 1
        ) {
            throw new DomainException(
                'La semantic key debe usar segmentos ASCII en minúsculas, '
                .'separados por puntos, con dígitos o guiones bajos internos, '
                .'sin espacios y con un máximo de 160 caracteres.'
            );
        }
    }
}
