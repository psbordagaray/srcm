<?php

namespace App\Enums;

enum PurchaseObligationCondition: string
{
    case OnReceipt = 'on_receipt';
    case DueDate = 'due_date';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::OnReceipt => 'Contra recepción',
            self::DueDate => 'Vencimiento en fecha',
            self::Other => 'Otra condición',
        };
    }
}
