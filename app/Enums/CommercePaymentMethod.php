<?php

namespace App\Enums;

enum CommercePaymentMethod: string
{
    case Cash = 'cash';
    case DebitCard = 'debit_card';
    case CreditCard = 'credit_card';
    case BankTransfer = 'bank_transfer';
    case DigitalWallet = 'digital_wallet';
    case AccountCredit = 'account_credit';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Efectivo',
            self::DebitCard => 'Tarjeta de débito',
            self::CreditCard => 'Tarjeta de crédito',
            self::BankTransfer => 'Transferencia bancaria',
            self::DigitalWallet => 'Billetera digital',
            self::AccountCredit => 'Crédito en cuenta',
            self::Other => 'Otro medio',
        };
    }

    public function requiresReference(): bool
    {
        return $this !== self::Cash;
    }
}
