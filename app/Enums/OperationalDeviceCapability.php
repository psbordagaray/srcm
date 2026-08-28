<?php

namespace App\Enums;

enum OperationalDeviceCapability: string
{
    case RestrictedOfflineReplay = 'restricted_offline_replay';

    public function label(): string
    {
        return match ($this) {
            self::RestrictedOfflineReplay =>
                'Replay restringido de operaciones offline',
        };
    }
}
