<?php

namespace App\Models;

use App\Enums\FinancialMovementSource;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CommercePostSaleExternalRefundEvidence extends Model
{
    use BelongsToOrganization;

    protected $table =
        'commerce_post_sale_external_refund_evidence';

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'public_id',
        'commerce_post_sale_external_refund_dispatch_id',
        'financial_external_movement_id',
        'source',
        'fingerprint',
        'observed_at',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (
            CommercePostSaleExternalRefundEvidence $evidence
        ): void {
            if (blank($evidence->public_id)) {
                $evidence->public_id =
                    (string) Str::uuid();
            }
        });

        static::updating(
            fn () => throw new DomainException(
                'La evidencia de reembolso externo es inmutable.'
            )
        );

        static::deleting(
            fn () => throw new DomainException(
                'La evidencia de reembolso externo no puede eliminarse.'
            )
        );
    }

    protected function casts(): array
    {
        return [
            'source' =>
                FinancialMovementSource::class,
            'observed_at' =>
                'immutable_datetime',
            'created_at' =>
                'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(
            CommercePostSaleExternalRefundDispatch::class,
            'commerce_post_sale_external_refund_dispatch_id'
        );
    }

    public function financialMovement(): BelongsTo
    {
        return $this->belongsTo(
            FinancialExternalMovement::class,
            'financial_external_movement_id'
        );
    }
}
