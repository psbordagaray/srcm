<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationalAttentionReceipt extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'user_id',
        'attention_key',
        'source_type',
        'source_public_id',
        'acknowledged_at',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException(
            'Un acuse de atención operativa es append-only.'
        ));

        static::deleting(fn () => throw new DomainException(
            'Un acuse de atención operativa no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'acknowledged_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
