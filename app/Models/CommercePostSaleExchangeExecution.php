<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class CommercePostSaleExchangeExecution extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'public_id',
        'commerce_post_sale_exchange_selection_id',
        'inventory_movement_id',
        'recognized_amount_minor',
        'replacement_amount_minor',
        'difference_amount_minor',
        'currency_code',
        'executed_by_user_id',
        'executed_at',
        'notes',
        'idempotency_key',
        'fingerprint',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (
            CommercePostSaleExchangeExecution $execution
        ): void {
            if (blank($execution->public_id)) {
                $execution->public_id = (string) Str::uuid();
            }
        });

        static::updating(
            fn () => throw new DomainException(
                'Una ejecución de cambio confirmada es inmutable.'
            )
        );

        static::deleting(
            fn () => throw new DomainException(
                'Una ejecución de cambio confirmada no puede eliminarse.'
            )
        );
    }

    protected function casts(): array
    {
        return [
            'recognized_amount_minor' => 'integer',
            'replacement_amount_minor' => 'integer',
            'difference_amount_minor' => 'integer',
            'executed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function selection(): BelongsTo
    {
        return $this->belongsTo(
            CommercePostSaleExchangeSelection::class,
            'commerce_post_sale_exchange_selection_id'
        );
    }

    public function inventoryMovement(): BelongsTo
    {
        return $this->belongsTo(
            InventoryMovement::class,
            'inventory_movement_id'
        );
    }

    public function executedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'executed_by_user_id'
        );
    }

    public function lines(): HasMany
    {
        return $this->hasMany(
            CommercePostSaleExchangeExecutionLine::class,
            'commerce_post_sale_exchange_execution_id'
        )->orderBy('sequence');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(
            CommercePostSaleExchangePayment::class,
            'commerce_post_sale_exchange_execution_id'
        )->orderBy('sequence');
    }

    public function creditGrant(): HasOne
    {
        return $this->hasOne(
            CommercePostSaleExchangeCreditGrant::class,
            'commerce_post_sale_exchange_execution_id'
        );
    }

    public function creditConsumptions(): HasMany
    {
        return $this->hasMany(
            CustomerCreditConsumption::class,
            'commerce_post_sale_exchange_execution_id'
        )->orderBy('payment_position');
    }
}
