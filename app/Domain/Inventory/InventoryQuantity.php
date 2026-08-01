<?php

namespace App\Domain\Inventory;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Throwable;

final class InventoryQuantity
{
    public const SCALE = 6;

    public const FACTOR_SCALE = 8;

    public static function positive(
        mixed $value,
        int $scale = self::SCALE,
        string $label = 'La cantidad'
    ): string {
        $decimal = self::decimal($value, $scale, $label);

        if (! BigDecimal::of($decimal)->isPositive()) {
            throw new DomainException($label.' debe ser mayor que cero.');
        }

        return $decimal;
    }

    public static function factor(mixed $value): string
    {
        return self::positive(
            $value,
            self::FACTOR_SCALE,
            'El factor de conversión'
        );
    }

    public static function multiply(
        mixed $quantity,
        mixed $factor
    ): string {
        try {
            return (string) BigDecimal::of(
                self::positive($quantity)
            )->multipliedBy(
                self::factor($factor)
            )->toScale(
                self::SCALE,
                RoundingMode::Unnecessary
            );
        } catch (DomainException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new DomainException(
                'La conversión produce una cantidad que exige redondeo.',
                previous: $exception
            );
        }
    }

    public static function assertEquivalent(
        mixed $enteredQuantity,
        mixed $conversionFactor,
        mixed $baseQuantity
    ): void {
        $expected = self::multiply(
            $enteredQuantity,
            $conversionFactor
        );
        $actual = self::positive($baseQuantity);

        if (! BigDecimal::of($expected)->isEqualTo($actual)) {
            throw new DomainException(
                'La cantidad base no coincide con la cantidad ingresada y su conversión.'
            );
        }
    }

    public static function assertFitsScale(
        mixed $value,
        int $scale,
        string $label = 'La cantidad base'
    ): void {
        if ($scale < 0 || $scale > self::SCALE) {
            throw new DomainException(
                'La precisión configurada debe estar entre 0 y '.self::SCALE.'.'
            );
        }

        try {
            BigDecimal::of(
                self::positive($value)
            )->toScale($scale, RoundingMode::Unnecessary);
        } catch (DomainException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new DomainException(
                $label.' supera la precisión admitida por el producto.',
                previous: $exception
            );
        }
    }

    public static function negate(mixed $value): string
    {
        return (string) BigDecimal::of(
            self::positive($value)
        )->negated();
    }

    public static function isNegative(mixed $value): bool
    {
        try {
            return BigDecimal::of((string) $value)->isNegative();
        } catch (Throwable $exception) {
            throw new DomainException(
                'El efecto del movimiento no es una cantidad decimal válida.',
                previous: $exception
            );
        }
    }

    public static function isPositive(mixed $value): bool
    {
        try {
            return BigDecimal::of((string) $value)->isPositive();
        } catch (Throwable $exception) {
            throw new DomainException(
                'El efecto del movimiento no es una cantidad decimal válida.',
                previous: $exception
            );
        }
    }

    public static function signed(
        mixed $value,
        string $label = 'La cantidad'
    ): string {
        $value = trim((string) $value);

        if (preg_match('/^[+-]?\d+(?:\.\d+)?$/', $value) !== 1) {
            throw new DomainException(
                $label.' debe expresarse como un decimal válido con punto.'
            );
        }

        try {
            return (string) BigDecimal::of($value)->toScale(
                self::SCALE,
                RoundingMode::Unnecessary
            );
        } catch (Throwable $exception) {
            throw new DomainException(
                $label.' supera la precisión permitida y no puede redondearse silenciosamente.',
                previous: $exception
            );
        }
    }

    public static function add(mixed $left, mixed $right): string
    {
        return (string) BigDecimal::of(
            self::signed($left)
        )->plus(
            self::signed($right)
        )->toScale(
            self::SCALE,
            RoundingMode::Unnecessary
        );
    }

    public static function subtract(mixed $left, mixed $right): string
    {
        return (string) BigDecimal::of(
            self::signed($left)
        )->minus(
            self::signed($right)
        )->toScale(
            self::SCALE,
            RoundingMode::Unnecessary
        );
    }

    public static function equal(mixed $left, mixed $right): bool
    {
        return BigDecimal::of(
            self::signed($left)
        )->isEqualTo(
            self::signed($right)
        );
    }

    public static function lessThanOrEqual(
        mixed $left,
        mixed $right
    ): bool {
        return BigDecimal::of(
            self::signed($left)
        )->isLessThanOrEqualTo(
            self::signed($right)
        );
    }

    public static function minimum(mixed $left, mixed $right): string
    {
        $left = self::signed($left);
        $right = self::signed($right);

        return BigDecimal::of($left)->isLessThanOrEqualTo($right)
            ? $left
            : $right;
    }

    public static function nonNegative(mixed $value): string
    {
        $quantity = self::signed($value);

        if (BigDecimal::of($quantity)->isNegative()) {
            return self::signed('0');
        }

        return $quantity;
    }

    public static function deficit(mixed $value): string
    {
        $quantity = self::signed($value);
        $decimal = BigDecimal::of($quantity);

        if (! $decimal->isNegative()) {
            return self::signed('0');
        }

        return (string) $decimal->negated()->toScale(
            self::SCALE,
            RoundingMode::Unnecessary
        );
    }

    private static function decimal(
        mixed $value,
        int $scale,
        string $label
    ): string {
        $value = trim((string) $value);

        if (preg_match('/^\+?\d+(?:\.\d+)?$/', $value) !== 1) {
            throw new DomainException(
                $label.' debe expresarse como un decimal válido con punto.'
            );
        }

        try {
            return (string) BigDecimal::of($value)->toScale(
                $scale,
                RoundingMode::Unnecessary
            );
        } catch (Throwable $exception) {
            throw new DomainException(
                $label.' supera la precisión permitida y no puede redondearse silenciosamente.',
                previous: $exception
            );
        }
    }
}
