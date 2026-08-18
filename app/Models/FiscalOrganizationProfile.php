<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FiscalOrganizationProfile extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'legal_name',
        'tax_id',
        'vat_condition_code',
        'gross_income_number',
        'activity_started_on',
        'address_line',
        'city',
        'province_code',
        'postal_code',
        'country_code',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected static function booted(): void
    {
        static::updating(function (
            FiscalOrganizationProfile $profile
        ): void {
            if ($profile->isDirty([
                'organization_id',
                'created_by_user_id',
            ])) {
                throw new DomainException(
                    'La pertenencia del perfil fiscal es inmutable.'
                );
            }
        });

        static::deleting(fn () => throw new DomainException(
            'Un perfil fiscal no puede eliminarse físicamente.'
        ));
    }

    protected function casts(): array
    {
        return [
            'activity_started_on' => 'immutable_date',
        ];
    }

    public function pointsOfSale(): HasMany
    {
        return $this->hasMany(
            FiscalPointOfSale::class,
            'fiscal_organization_profile_id'
        )->orderBy('environment')->orderBy('point_number');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(FiscalDocument::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
