<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException(
                'Los registros de auditoría no pueden modificarse.'
            );
        });

        static::deleting(function (): never {
            throw new LogicException(
                'Los registros de auditoría no pueden eliminarse.'
            );
        });
    }
}
