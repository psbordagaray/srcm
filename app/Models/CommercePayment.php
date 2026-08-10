<?php

namespace App\Models;

use App\Enums\CommercePaymentMethod;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommercePayment extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'commerce_sale_id',
        'position',
        'method',
        'amount_minor',
        'reference',
        'card_brand',
        'card_network',
        'card_last4',
        'installments',
        'processor',
        'external_operation_id',
        'authorization_code',
        'provider_status',
        'notes',
        'received_by_user_id',
        'paid_at',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException(
            'Un pago registrado es inmutable.'
        ));
        static::deleting(fn () => throw new DomainException(
            'Un pago registrado no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'method' => CommercePaymentMethod::class,
            'amount_minor' => 'integer',
            'installments' => 'integer',
            'paid_at' => 'immutable_datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(CommerceSale::class, 'commerce_sale_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }
}
