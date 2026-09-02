<?php

namespace App\Domain\Authorization;

use InvalidArgumentException;
use Stringable;

final readonly class Capability implements Stringable
{
    public const PATTERN = '/^[a-z][a-z0-9_-]*(?:\.[a-z][a-z0-9_-]*)+$/D';

    public function __construct(public string $value)
    {
        if (
            $value === ''
            || strlen($value) > 128
            || preg_match(self::PATTERN, $value) !== 1
            || str_contains($value, '*')
        ) {
            throw new InvalidArgumentException(
                'Capability must be a lowercase dot-namespaced identifier without wildcards.'
            );
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
