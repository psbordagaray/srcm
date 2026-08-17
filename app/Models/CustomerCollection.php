<?php

namespace App\Models;

use App\Enums\CommercePaymentMethod;
use App\Enums\CustomerCollectionStatus;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class CustomerCollection extends Model
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
        'collected_at',
        'idempotency_key',
        'fingerprint',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (CustomerCollection $collection): void {
            if (blank($collection->public_id)) {
                $collection->public_id = (string) Str::uuid();
            }

            if ($collection->status !== CustomerCollectionStatus::Building) {
                throw new DomainException(
                    'Una cobranza debe comenzar en preparación.'
                );
            }
        });

        static::updating(function (CustomerCollection $collection): void {
            $allowed = $collection->getDirty();

            if (
                array_keys($allowed) !== ['status']
                || $collection->getRawOriginal('status')
                    !== CustomerCollectionStatus::Building->value
                || $collection->status
                    !== CustomerCollectionStatus::Confirmed
            ) {
                throw new DomainException(
                    'Una cobranza sólo puede pasar de preparación a confirmada.'
                );
            }
        });

        static::deleting(fn () => throw new DomainException(
            'Una cobranza confirmada no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'status' => CustomerCollectionStatus::class,
            'method' => CommercePaymentMethod::class,
            'amount_minor' => 'integer',
            'tendered_amount_minor' => 'integer',
            'change_amount_minor' => 'integer',
            'collected_at' => 'immutable_datetime',
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
        return $this->belongsTo(FinancialAccount::class);
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

    public function allocations(): HasMany
    {
        return $this->hasMany(CustomerCollectionAllocation::class)
            ->orderBy('sequence');
    }

    public function cashMovement(): HasOne
    {
        return $this->hasOne(
            CashMovement::class,
            'customer_collection_id'
        );
    }
}
