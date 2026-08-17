<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CustomerCreditPolicy extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'public_id',
        'business_party_id',
        'currency_code',
        'version',
        'limit_minor',
        'reason',
        'idempotency_key',
        'fingerprint',
        'set_by_user_id',
        'set_at',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (
            CustomerCreditPolicy $policy
        ): void {
            if (blank($policy->public_id)) {
                $policy->public_id = (string) Str::uuid();
            }

            $policy->guardCreation();
        });

        static::updating(fn () => throw new DomainException(
            'Una versión de política de crédito es inmutable.'
        ));

        static::deleting(fn () => throw new DomainException(
            'Una versión de política de crédito no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'limit_minor' => 'integer',
            'set_at' => 'immutable_datetime',
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

    public function setBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'set_by_user_id'
        );
    }

    private function guardCreation(): void
    {
        if (
            preg_match(
                '/^[A-Z]{3}$/D',
                (string) $this->currency_code
            ) !== 1
            || (int) $this->version < 1
            || (int) $this->limit_minor < 0
            || blank($this->reason)
            || mb_strlen((string) $this->reason) > 2000
            || blank($this->idempotency_key)
            || mb_strlen((string) $this->idempotency_key) > 180
            || blank($this->fingerprint)
            || strlen((string) $this->fingerprint) !== 64
            || $this->set_by_user_id === null
            || $this->set_at === null
            || $this->created_at === null
        ) {
            throw new DomainException(
                'La política de crédito no conserva límite, motivo, versión y trazabilidad válidos.'
            );
        }

        $customer = BusinessParty::query()
            ->whereKey($this->business_party_id)
            ->where('organization_id', $this->organization_id)
            ->whereHas(
                'customer',
                fn ($query) => $query->where('active', true)
            )
            ->exists();

        $administrator = OrganizationMembership::query()
            ->where('organization_id', $this->organization_id)
            ->where('user_id', $this->set_by_user_id)
            ->where('active', true)
            ->first();

        if (
            ! $customer
            || ! $administrator?->role
                ->canManageCustomerCreditPolicy()
        ) {
            throw new DomainException(
                'La política de crédito requiere cliente activo y Administrador de la organización.'
            );
        }
    }
}
