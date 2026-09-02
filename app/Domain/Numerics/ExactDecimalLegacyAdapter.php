<?php

namespace App\Domain\Numerics;

use InvalidArgumentException;

final class ExactDecimalLegacyAdapter
{
    public const MAX_EXPLICIT_SCALE = 18;

    public static function fromMinorUnit(
        int $minorUnit,
        int $scale,
    ): ExactDecimal {
        self::assertScale($scale);

        $text = (string) $minorUnit;
        $negative = str_starts_with($text, '-');
        $digits = $negative
            ? substr($text, 1)
            : $text;

        if ($scale === 0) {
            return ExactDecimal::fromCanonical($text);
        }

        if (strlen($digits) <= $scale) {
            $digits = str_pad(
                $digits,
                $scale + 1,
                '0',
                STR_PAD_LEFT,
            );
        }

        $whole = substr($digits, 0, -$scale);
        $fraction = substr($digits, -$scale);

        return ExactDecimal::fromCanonical(
            ($negative ? '-' : '')
            .$whole
            .'.'
            .$fraction
        );
    }

    public static function toMinorUnit(
        ExactDecimal $value,
        int $scale,
    ): int {
        self::assertScale($scale);
        $value->assertMaxScale($scale);

        $text = $value->value;
        $negative = str_starts_with($text, '-');

        if ($negative) {
            $text = substr($text, 1);
        }

        [$whole, $fraction] = array_pad(
            explode('.', $text, 2),
            2,
            '',
        );

        $fraction = str_pad(
            $fraction,
            $scale,
            '0',
            STR_PAD_RIGHT,
        );

        $digits = ltrim($whole.$fraction, '0');
        $digits = $digits === '' ? '0' : $digits;
        $minorText = $negative && $digits !== '0'
            ? '-'.$digits
            : $digits;

        $minor = filter_var(
            $minorText,
            FILTER_VALIDATE_INT,
        );

        if ($minor === false) {
            throw new InvalidArgumentException(
                'Legacy minor-unit value exceeds the platform integer range.'
            );
        }

        return $minor;
    }

    public static function fromCanonicalMachine(
        mixed $value,
        int $maxScale,
    ): ExactDecimal {
        self::assertScale($maxScale);

        if (is_float($value)) {
            throw new InvalidArgumentException(
                'Authoritative machine numeric input rejects binary float.'
            );
        }

        if (! is_string($value) && ! is_int($value)) {
            throw new InvalidArgumentException(
                'Authoritative machine numeric input must be canonical string or integer.'
            );
        }

        $text = (string) $value;

        if (trim($text) !== $text) {
            throw new InvalidArgumentException(
                'Authoritative machine numeric input must already be canonical and whitespace-free.'
            );
        }

        return ExactDecimal::fromCanonical($text)
            ->assertMaxScale($maxScale);
    }

    private static function assertScale(int $scale): void
    {
        if ($scale < 0 || $scale > self::MAX_EXPLICIT_SCALE) {
            throw new InvalidArgumentException(
                'Legacy minor-unit scale must be explicitly between 0 and 18.'
            );
        }
    }
}
