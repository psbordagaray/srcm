<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Customer extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'business_party_id',
        'notes',
        'active',
    ];

    protected static function booted(): void
    {
        static::saving(function (Customer $customer): void {
            $matches = BusinessParty::query()
                ->whereKey($customer->business_party_id)
                ->where('organization_id', $customer->organization_id)
                ->exists();

            if (! $matches) {
                throw new DomainException(
                    'La identidad comercial no pertenece a la organización del cliente.'
                );
            }

            if ($customer->exists && $customer->isDirty([
                'organization_id',
                'business_party_id',
            ])) {
                throw new DomainException(
                    'La identidad y organización de un cliente son inmutables.'
                );
            }
        });

        static::deleting(fn () => throw new DomainException(
            'Un cliente no puede eliminarse físicamente.'
        ));
    }

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(BusinessParty::class, 'business_party_id');
    }
}
