<?php

namespace App\Enums;

enum FiscalIntegrationMode: string
{
    case WsfeV1 = 'wsfe_v1';
    case Wsmtxca = 'wsmtxca';

    public function label(): string
    {
        return match ($this) {
            self::WsfeV1 => 'WSFEv1',
            self::Wsmtxca => 'WSMTXCA',
        };
    }
}

