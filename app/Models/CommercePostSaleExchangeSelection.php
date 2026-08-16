<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CommercePostSaleExchangeSelection extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'public_id',
        'commerce_post_sale_resolution_id',
        'currency_code',
        'recognized_amount_minor',
        'selected_by_user_id',
        'selected_at',
        'notes',
        'idempotency_key',
        'fingerprint',
    ];

    protected static function booted(): void
    {
        static::creating(function (
            CommercePostSaleExchangeSelection $selection
        ): void {
            if (blank($selection->public_id)) {
                $selection->public_id =
                    (string) Str::uuid();
            }
        });

        static::updating(
            fn () => throw new DomainException(
                'Una selección de cambio confirmada es inmutable.'
            )
        );

        static::deleting(
            fn () => throw new DomainException(
                'Una selección de cambio confirmada no puede eliminarse.'
            )
        );
    }

    protected function casts(): array
    {
        return [
            'recognized_amount_minor' => 'integer',
            'selected_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function resolution(): BelongsTo
    {
        return $this->belongsTo(
            CommercePostSaleResolution::class,
            'commerce_post_sale_resolution_id'
        );
    }

    public function selectedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'selected_by_user_id'
        );
    }

    public function lines(): HasMany
    {
        return $this->hasMany(
            CommercePostSaleExchangeSelectionLine::class,
            'commerce_post_sale_exchange_selection_id'
        )->orderBy('sequence');
    }

    public function replacementAmountMinor(): int
    {
        $lines = $this->relationLoaded('lines')
            ? $this->lines
            : $this->lines()->get();

        $total = 0;

        foreach ($lines as $line) {
            $amount = (int) $line->line_amount_minor;

            if (
                $amount < 0
                || $total > PHP_INT_MAX - $amount
            ) {
                throw new DomainException(
                    'El valor total del reemplazo supera el importe admitido.'
                );
            }

            $total += $amount;
        }

        return $total;
    }

    public function differenceAmountMinor(): int
    {
        return $this->replacementAmountMinor()
            - (int) $this->recognized_amount_minor;
    }
}
