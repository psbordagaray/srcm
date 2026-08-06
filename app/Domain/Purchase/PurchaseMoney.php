<?php

namespace App\Domain\Purchase;

use App\Domain\Inventory\InventoryQuantity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Throwable;

final class PurchaseMoney
{
    public static function nonNegative(
        int $value,
        string $label = 'El importe'
    ): int {
        if ($value < 0) {
            throw new DomainException(
                $label.' no puede ser negativo.'
            );
        }

        return $value;
    }

    public static function subtotal(
        mixed $quantity,
        int $unitCostMinor
    ): int {
        $quantity = InventoryQuantity::positive($quantity);
        self::nonNegative(
            $unitCostMinor,
            'El costo unitario'
        );

        try {
            return BigDecimal::of($quantity)
                ->multipliedBy((string) $unitCostMinor)
                ->toScale(0, RoundingMode::Unnecessary)
                ->toInt();
        } catch (Throwable $exception) {
            throw new DomainException(
                'El subtotal produce una fracción de unidad monetaria menor o supera el rango admitido.',
                previous: $exception
            );
        }
    }

    public static function add(
        int $left,
        int $right,
        string $label = 'El total'
    ): int {
        self::nonNegative($left, $label);
        self::nonNegative($right, $label);

        try {
            return BigDecimal::of((string) $left)
                ->plus((string) $right)
                ->toScale(0, RoundingMode::Unnecessary)
                ->toInt();
        } catch (Throwable $exception) {
            throw new DomainException(
                $label.' supera el rango admitido.',
                previous: $exception
            );
        }
    }
}
