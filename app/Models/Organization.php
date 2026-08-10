<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Organization extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'tax_id',
        'email',
        'phone',
        'website',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function setNameAttribute(string $value): void
    {
        $name = Str::of($value)->squish()->toString();

        $this->attributes['name'] = $name;
        $this->attributes['normalized_name'] =
            static::normalizeName($name);
    }

    public function setSlugAttribute(string $value): void
    {
        $this->attributes['slug'] = Str::of($value)
            ->slug()
            ->lower()
            ->toString();
    }

    public function setTaxIdAttribute(?string $value): void
    {
        if (blank($value)) {
            $this->attributes['tax_id'] = null;
            $this->attributes['normalized_tax_id'] = null;

            return;
        }

        $taxId = Str::of((string) $value)
            ->squish()
            ->upper()
            ->toString();

        $this->attributes['tax_id'] = $taxId;
        $this->attributes['normalized_tax_id'] =
            static::normalizeTaxId($taxId);
    }

    public function setEmailAttribute(?string $value): void
    {
        $this->attributes['email'] = filled($value)
            ? Str::of((string) $value)
                ->trim()
                ->lower()
                ->toString()
            : null;
    }

    public function setPhoneAttribute(?string $value): void
    {
        $this->attributes['phone'] = filled($value)
            ? Str::of((string) $value)
                ->squish()
                ->toString()
            : null;
    }

    public function setWebsiteAttribute(?string $value): void
    {
        if (blank($value)) {
            $this->attributes['website'] = null;

            return;
        }

        $website = trim((string) $value);

        if (! preg_match('#^https?://#i', $website)) {
            $website = 'https://'.$website;
        }

        $this->attributes['website'] = $website;
    }

    public static function normalizeName(string $value): string
    {
        $ascii = Str::lower(Str::ascii(trim($value)));

        return preg_replace('/[^a-z0-9]+/', '', $ascii) ?? '';
    }

    public static function normalizeTaxId(string $value): string
    {
        $ascii = Str::upper(Str::ascii(trim($value)));

        return preg_replace('/[^A-Z0-9]+/', '', $ascii) ?? '';
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'organization_memberships'
        )
            ->withPivot(['role', 'active'])
            ->withTimestamps();
    }

    public function businessParties(): HasMany
    {
        return $this->hasMany(BusinessParty::class);
    }

    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function supplierOffers(): HasMany
    {
        return $this->hasMany(SupplierOffer::class);
    }

    public function inventoryLocations(): HasMany
    {
        return $this->hasMany(InventoryLocation::class);
    }

    public function serviceAssets(): HasMany
    {
        return $this->hasMany(ServiceAsset::class);
    }

    public function serviceOrders(): HasMany
    {
        return $this->hasMany(ServiceOrder::class);
    }

    public function serviceWorkItems(): HasMany
    {
        return $this->hasMany(ServiceWorkItem::class);
    }

    public function servicePartRequirements(): HasMany
    {
        return $this->hasMany(ServicePartRequirement::class);
    }

    public function servicePartPurchases(): HasMany
    {
        return $this->hasMany(ServicePartPurchase::class);
    }

    public function serviceQualityInspections(): HasMany
    {
        return $this->hasMany(ServiceQualityInspection::class);
    }

    public function serviceDeliveries(): HasMany
    {
        return $this->hasMany(ServiceDelivery::class);
    }

    public function serviceWarrantyGrants(): HasMany
    {
        return $this->hasMany(ServiceWarrantyGrant::class);
    }

    public function commerceSales(): HasMany
    {
        return $this->hasMany(CommerceSale::class);
    }

    public function financialAccounts(): HasMany
    {
        return $this->hasMany(FinancialAccount::class);
    }

    public function financialExternalMovements(): HasMany
    {
        return $this->hasMany(FinancialExternalMovement::class);
    }

    public function paymentReconciliations(): HasMany
    {
        return $this->hasMany(PaymentReconciliation::class);
    }

    public function serviceCancellationRequests(): HasMany
    {
        return $this->hasMany(ServiceCancellationRequest::class);
    }

    public function serviceCancellationResolutions(): HasMany
    {
        return $this->hasMany(ServiceCancellationResolution::class);
    }

    public function serviceCancellationReturns(): HasMany
    {
        return $this->hasMany(ServiceCancellationReturn::class);
    }
}
