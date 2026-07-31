<?php

namespace App\Enums;

enum InventoryMovementType: string
{
    case InitialBalance = 'initial_balance';
    case Receipt = 'receipt';
    case Issue = 'issue';
    case Transfer = 'transfer';
    case CustomerReturn = 'customer_return';
    case SupplierReturn = 'supplier_return';
    case PositiveAdjustment = 'positive_adjustment';
    case NegativeAdjustment = 'negative_adjustment';
    case Reversal = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::InitialBalance => 'Saldo inicial',
            self::Receipt => 'Recepción',
            self::Issue => 'Salida',
            self::Transfer => 'Transferencia',
            self::CustomerReturn => 'Devolución de cliente',
            self::SupplierReturn => 'Devolución a proveedor',
            self::PositiveAdjustment => 'Ajuste positivo',
            self::NegativeAdjustment => 'Ajuste negativo',
            self::Reversal => 'Reverso',
        };
    }

    public function allowsSource(): bool
    {
        return match ($this) {
            self::Issue,
            self::Transfer,
            self::SupplierReturn,
            self::NegativeAdjustment,
            self::Reversal => true,
            default => false,
        };
    }

    public function allowsDestination(): bool
    {
        return match ($this) {
            self::InitialBalance,
            self::Receipt,
            self::Transfer,
            self::CustomerReturn,
            self::PositiveAdjustment,
            self::Reversal => true,
            default => false,
        };
    }

    public function requiresSource(): bool
    {
        return match ($this) {
            self::Issue,
            self::Transfer,
            self::SupplierReturn,
            self::NegativeAdjustment => true,
            default => false,
        };
    }

    public function requiresDestination(): bool
    {
        return match ($this) {
            self::InitialBalance,
            self::Receipt,
            self::Transfer,
            self::CustomerReturn,
            self::PositiveAdjustment => true,
            default => false,
        };
    }
}
