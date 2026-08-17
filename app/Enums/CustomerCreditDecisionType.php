<?php

namespace App\Enums;

enum CustomerCreditDecisionType: string
{
    case LegacyAdmin = 'legacy_admin';
    case WithinPolicy = 'within_policy';
    case AdminOverride = 'admin_override';

    public function label(): string
    {
        return match ($this) {
            self::LegacyAdmin => 'Administrador sin política configurada',
            self::WithinPolicy => 'Dentro de política',
            self::AdminOverride => 'Excepción autorizada por Administrador',
        };
    }
}
