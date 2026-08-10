<?php

namespace App\Enums;

enum FinancialAccountType: string
{
    case CashBox = 'cash_box';
    case BankAccount = 'bank_account';
    case DigitalWallet = 'digital_wallet';
    case CardProcessor = 'card_processor';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::CashBox => 'Caja de efectivo',
            self::BankAccount => 'Cuenta bancaria',
            self::DigitalWallet => 'Billetera digital',
            self::CardProcessor => 'Procesador / adquirente',
            self::Other => 'Otra cuenta financiera',
        };
    }
}
