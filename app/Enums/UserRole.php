<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Operator = 'operator';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrador',
            self::Operator => 'Operador',
            self::Viewer => 'Consulta',
        };
    }

    public function canManageCatalog(): bool
    {
        return match ($this) {
            self::Admin,
            self::Operator => true,
            self::Viewer => false,
        };
    }

    public function canManageCommerce(): bool
    {
        return match ($this) {
            self::Admin,
            self::Operator => true,
            self::Viewer => false,
        };
    }

    public function canViewAudit(): bool
    {
        return $this === self::Admin;
    }
}
