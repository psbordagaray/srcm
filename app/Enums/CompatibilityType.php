<?php

namespace App\Enums;

enum CompatibilityType: string
{
    case CompatibleWith = 'compatible_with';
    case Replaces = 'replaces';
    case ComponentOf = 'component_of';
    case AccessoryFor = 'accessory_for';

    public function outgoingLabel(): string
    {
        return match ($this) {
            self::CompatibleWith => 'Compatible con',
            self::Replaces => 'Reemplaza a',
            self::ComponentOf => 'Componente de',
            self::AccessoryFor => 'Accesorio para',
        };
    }

    public function incomingLabel(): string
    {
        return match ($this) {
            self::CompatibleWith => 'Compatible con',
            self::Replaces => 'Reemplazado por',
            self::ComponentOf => 'Contiene el componente',
            self::AccessoryFor => 'Usa el accesorio',
        };
    }

    public function label(bool $incoming): string
    {
        return $incoming
            ? $this->incomingLabel()
            : $this->outgoingLabel();
    }

    public function isSymmetric(): bool
    {
        return $this === self::CompatibleWith;
    }

    public function description(): string
    {
        return match ($this) {
            self::CompatibleWith =>
                'Ambas entidades funcionan juntas en forma comprobada.',
            self::Replaces =>
                'La entidad actual puede reemplazar a la entidad relacionada.',
            self::ComponentOf =>
                'La entidad actual forma parte de la entidad relacionada.',
            self::AccessoryFor =>
                'La entidad actual se utiliza como accesorio de la relacionada.',
        };
    }
}
