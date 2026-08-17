<?php

namespace App\Models;

use App\Enums\CommercePaymentMethod;
use App\Enums\CustomerAdvanceStatus;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class CustomerAdvance extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'public_id',
        'business_party_id',
        'financial_account_id',
        'cash_register_session_id',
        'cash_register_id',
        'status',
        'method',
        'currency_code',
        'amount_minor',
        'tendered_amount_minor',
        'change_amount_minor',
        'reference',
        'notes',
        'received_by_user_id',
        'received_at',
        'idempotency_key',
        'fingerprint',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (
            CustomerAdvance $advance
        ): void {
            if (blank($advance->public_id)) {
                $advance->public_id =
                    (string) Str::uuid();
            }

            if (
                $advance->status
                    !== CustomerAdvanceStatus::Building
            ) {
                throw new DomainException(
                    'Un anticipo debe comenzar en preparación.'
                );
            }
        });

        static::updating(function (
            CustomerAdvance $advance
        ): void {
            $dirty = $advance->getDirty();

            if (
                array_keys($dirty) !== ['status']
                || $advance->getRawOriginal('status')
                    !== CustomerAdvanceStatus::Building->value
                || $advance->status
                    !== CustomerAdvanceStatus::Confirmed
            ) {
                throw new DomainException(
                    'Un anticipo sólo puede pasar de preparación a confirmado.'
                );
            }
        });

        static::deleting(
            fn () => throw new DomainException(
                'Un anticipo confirmado no puede eliminarse.'
            )
        );
    }

    protected function casts(): array
    {
        return [
            'status' => CustomerAdvanceStatus::class,
            'method' => CommercePaymentMethod::class,
            'amount_minor' => 'integer',
            'tendered_amount_minor' => 'integer',
            'change_amount_minor' => 'integer',
            'received_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(
            BusinessParty::class,
            'business_party_id'
        );
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(
            FinancialAccount::class
        );
    }

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(
            CashRegisterSession::class,
            'cash_register_session_id'
        );
    }

    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(
            CashRegister::class,
            'cash_register_id'
        );
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'received_by_user_id'
        );
    }

    public function cashMovement(): HasOne
    {
        return $this->hasOne(
            CashMovement::class,
            'customer_advance_id'
        );
    }

    public function creditAllocations(): HasMany
    {
        return $this->hasMany(
            CustomerCreditConsumptionAllocation::class,
            'customer_advance_id'
        )->orderBy('id');
    }
}
