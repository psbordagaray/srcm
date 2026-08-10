<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentReconciliationAllocation extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'payment_reconciliation_event_id',
        'financial_external_movement_id',
        'gross_amount_minor',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException(
            'Una asignación de conciliación es inmutable.'
        ));
        static::deleting(fn () => throw new DomainException(
            'Una asignación de conciliación no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'gross_amount_minor' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(
            PaymentReconciliationEvent::class,
            'payment_reconciliation_event_id'
        );
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(
            FinancialExternalMovement::class,
            'financial_external_movement_id'
        );
    }
}
