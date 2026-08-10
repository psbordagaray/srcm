<?php

namespace App\Models;

use App\Enums\PaymentReconciliationStatus;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentReconciliationEvent extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'payment_reconciliation_id',
        'idempotency_key',
        'status',
        'allocated_gross_amount_minor',
        'difference_minor',
        'note',
        'created_by_user_id',
        'occurred_at',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException(
            'Un evento de conciliación es inmutable.'
        ));
        static::deleting(fn () => throw new DomainException(
            'Un evento de conciliación no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'status' => PaymentReconciliationStatus::class,
            'allocated_gross_amount_minor' => 'integer',
            'difference_minor' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(
            PaymentReconciliation::class,
            'payment_reconciliation_id'
        );
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentReconciliationAllocation::class);
    }
}
