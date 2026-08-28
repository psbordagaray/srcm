<?php

namespace App\Enums;

enum OperationalDeviceCapability: string
{
    case RestrictedOfflineReplay = 'restricted_offline_replay';
    case RestrictedOfflineReadModel = 'restricted_offline_read_model';

    public function label(): string
    {
        return match ($this) {
            self::RestrictedOfflineReplay =>
                'Replay restringido de operaciones offline',
            self::RestrictedOfflineReadModel =>
                'Read-model offline restringido',
        };
    }
}
