<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
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
        static::saving(function (Supplier $supplier): void {
            $matches = BusinessParty::query()
                ->whereKey($supplier->business_party_id)
                ->where(
                    'organization_id',
                    $supplier->organization_id
                )
                ->exists();

            if (! $matches) {
                throw new DomainException(
                    'La identidad comercial no pertenece a la organización del proveedor.'
                );
            }
        });
    }

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(
            BusinessParty::class,
            'business_party_id'
        );
    }

    public function offers(): HasMany
    {
        return $this->hasMany(SupplierOffer::class);
    }

    public function servicePartPurchases(): HasMany
    {
        return $this->hasMany(ServicePartPurchase::class);
    }
}
