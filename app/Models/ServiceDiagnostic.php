<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceDiagnostic extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'service_order_id',
        'revision',
        'summary',
        'recommendation',
        'data_risk_notes',
        'diagnosed_by_user_id',
        'diagnosed_at',
        'idempotency_key',
        'fingerprint',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException(
            'Un diagnóstico registrado es inmutable.'
        ));
        static::deleting(fn () => throw new DomainException(
            'Un diagnóstico registrado no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return ['diagnosed_at' => 'immutable_datetime'];
    }

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function findings(): HasMany
    {
        return $this->hasMany(ServiceDiagnosticFinding::class)
            ->orderBy('position');
    }

    public function diagnosedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diagnosed_by_user_id');
    }
}
