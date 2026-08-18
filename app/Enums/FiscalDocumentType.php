<?php

namespace App\Enums;

enum FiscalDocumentType: string
{
    case Invoice = 'invoice';
    case CreditNote = 'credit_note';
    case DebitNote = 'debit_note';
}
