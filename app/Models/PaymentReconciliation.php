<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PaymentReconciliation extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'public_id',
        'commerce_payment_id',
        'expected_amount_minor',
        'opened_by_user_id',
        'opened_at',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (PaymentReconciliation $case): void {
            if (blank($case->public_id)) {
                $case->public_id = (string) Str::uuid();
            }
        });

        static::updating(fn () => throw new DomainException(
            'Un expediente de conciliación es inmutable.'
        ));
        static::deleting(fn () => throw new DomainException(
            'Un expediente de conciliación no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'expected_amount_minor' => 'integer',
            'opened_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(
            CommercePayment::class,
            'commerce_payment_id'
        );
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PaymentReconciliationEvent::class)
            ->orderBy('id');
    }

    public function latestEvent(): ?PaymentReconciliationEvent
    {
        return $this->events()->latest('id')->first();
    }
}
