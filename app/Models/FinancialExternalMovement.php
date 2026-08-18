<?php

namespace App\Models;

use App\Enums\FinancialMovementDirection;
use App\Enums\FinancialMovementSource;
use App\Enums\FinancialMovementStatus;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class FinancialExternalMovement extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'financial_account_id',
        'public_id',
        'source',
        'source_key',
        'fingerprint',
        'external_operation_id',
        'direction',
        'status',
        'currency_code',
        'gross_amount_minor',
        'fee_amount_minor',
        'withholding_amount_minor',
        'net_amount_minor',
        'occurred_at',
        'imported_at',
        'raw_reference',
        'created_by_user_id',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (FinancialExternalMovement $movement): void {
            if (blank($movement->public_id)) {
                $movement->public_id = (string) Str::uuid();
            }
        });

        static::updating(fn () => throw new DomainException(
            'Un movimiento financiero externo registrado es inmutable.'
        ));
        static::deleting(fn () => throw new DomainException(
            'Un movimiento financiero externo no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'source' => FinancialMovementSource::class,
            'direction' => FinancialMovementDirection::class,
            'status' => FinancialMovementStatus::class,
            'gross_amount_minor' => 'integer',
            'fee_amount_minor' => 'integer',
            'withholding_amount_minor' => 'integer',
            'net_amount_minor' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'imported_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(
            FinancialAccount::class,
            'financial_account_id'
        );
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function purchasePaymentVerification(): HasOne
    {
        return $this->hasOne(
            PurchasePaymentExternalVerification::class,
            'financial_external_movement_id'
        );
    }
}
