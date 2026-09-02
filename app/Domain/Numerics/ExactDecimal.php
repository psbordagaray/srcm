<?php

namespace App\Domain\Numerics;

use InvalidArgumentException;
use Stringable;

final readonly class ExactDecimal implements Stringable
{
    public const PATTERN = '/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D';

    private function __construct(
        public string $value,
        public int $scale,
    ) {
    }

    public static function fromCanonical(string $value): self
    {
        if (
            $value === ''
            || strlen($value) > 256
            || preg_match(self::PATTERN, $value) !== 1
            || preg_match('/^-0(?:\.0+)?$/D', $value) === 1
        ) {
            throw new InvalidArgumentException(
                'Exact decimal must use canonical dot-decimal syntax without grouping, exponent, plus sign or negative zero.'
            );
        }

        $dot = strpos($value, '.');
        $scale = $dot === false
            ? 0
            : strlen($value) - $dot - 1;

        return new self($value, $scale);
    }

    public function assertMaxScale(int $maxScale): self
    {
        if ($maxScale < 0 || $maxScale > 18) {
            throw new InvalidArgumentException(
                'Numeric scale must be between 0 and 18.'
            );
        }

        if ($this->scale > $maxScale) {
            throw new InvalidArgumentException(
                'Scale overflow fails closed; silent truncation and implicit rounding are forbidden.'
            );
        }

        return $this;
    }

    public function assertInteger(): self
    {
        if ($this->scale !== 0) {
            throw new InvalidArgumentException(
                'COUNT requires an exact integer value.'
            );
        }

        return $this;
    }

    public function isZero(): bool
    {
        return preg_match('/^0(?:\.0+)?$/D', $this->value) === 1;
    }

    public function isNegative(): bool
    {
        return str_starts_with($this->value, '-');
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
