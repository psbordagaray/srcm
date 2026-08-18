<?php

namespace App\Enums;

/**
 * Estado derivado. P10.2 todavía no registra intentos ni autorizaciones ARCA.
 */
enum FiscalDocumentState: string
{
    case Pending = 'pending';
}
