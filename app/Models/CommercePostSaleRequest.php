<?php

namespace App\Models;

use App\Enums\CommercePostSaleIntent;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CommercePostSaleRequest extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'public_id',
        'commerce_sale_id',
        'intent',
        'reason',
        'notes',
        'requested_by_user_id',
        'requested_at',
        'idempotency_key',
        'fingerprint',
    ];

    protected static function booted(): void
    {
        static::creating(function (
            CommercePostSaleRequest $request
        ): void {
            if (blank($request->public_id)) {
                $request->public_id = (string) Str::uuid();
            }
        });

        static::updating(
            fn () => throw new DomainException(
                'Una solicitud de posventa confirmada es inmutable.'
            )
        );

        static::deleting(
            fn () => throw new DomainException(
                'Una solicitud de posventa confirmada no puede eliminarse.'
            )
        );
    }

    protected function casts(): array
    {
        return [
            'intent' => CommercePostSaleIntent::class,
            'requested_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(
            CommerceSale::class,
            'commerce_sale_id'
        );
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'requested_by_user_id'
        );
    }

    public function lines(): HasMany
    {
        return $this->hasMany(
            CommercePostSaleRequestLine::class
        )->orderBy('id');
    }
}
