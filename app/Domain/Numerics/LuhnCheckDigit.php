<?php

namespace App\Domain\Numerics;

use InvalidArgumentException;

final class LuhnCheckDigit implements CheckDigitAlgorithm
{
    public const IDENTIFIER = 'LUHN_MOD_10';

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function calculate(string $payload): string
    {
        self::assertAsciiDigits($payload);

        $sum = 0;
        $double = true;

        for ($index = strlen($payload) - 1; $index >= 0; $index--) {
            $digit = ord($payload[$index]) - 48;
            $sum += self::weightedDigit($digit, $double);
            $double = ! $double;
        }

        return (string) ((10 - ($sum % 10)) % 10);
    }

    public function append(string $payload): string
    {
        return $payload.$this->calculate($payload);
    }

    public function isValid(string $candidate): bool
    {
        self::assertAsciiDigits($candidate);

        $sum = 0;
        $double = false;

        for ($index = strlen($candidate) - 1; $index >= 0; $index--) {
            $digit = ord($candidate[$index]) - 48;
            $sum += self::weightedDigit($digit, $double);
            $double = ! $double;
        }

        return ($sum % 10) === 0;
    }

    private static function weightedDigit(int $digit, bool $double): int
    {
        if (! $double) {
            return $digit;
        }

        $weighted = $digit * 2;

        return $weighted > 9
            ? $weighted - 9
            : $weighted;
    }

    private static function assertAsciiDigits(string $value): void
    {
        if ($value === '' || preg_match('/^[0-9]+$/D', $value) !== 1) {
            throw new InvalidArgumentException(
                'Check-digit input must be a non-empty ASCII digit string.'
            );
        }
    }
}