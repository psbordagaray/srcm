<?php

namespace App\Models;

use App\Enums\FiscalEnvironment;
use App\Enums\FiscalIntegrationMode;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class FiscalPointOfSale extends Model
{
    use BelongsToOrganization;

    protected $table = 'fiscal_points_of_sale';

    protected $fillable = [
        'organization_id',
        'fiscal_organization_profile_id',
        'public_id',
        'environment',
        'point_number',
        'integration_mode',
        'active',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (FiscalPointOfSale $point): void {
            if (blank($point->public_id)) {
                $point->public_id = (string) Str::uuid();
            }
        });

        static::updating(function (FiscalPointOfSale $point): void {
            if ($point->isDirty([
                'organization_id',
                'fiscal_organization_profile_id',
                'public_id',
                'environment',
                'point_number',
                'integration_mode',
                'created_by_user_id',
            ])) {
                throw new DomainException(
                    'La identidad del punto de venta fiscal es inmutable.'
                );
            }
        });

        static::deleting(fn () => throw new DomainException(
            'Un punto de venta fiscal no puede eliminarse físicamente.'
        ));
    }

    protected function casts(): array
    {
        return [
            'environment' => FiscalEnvironment::class,
            'point_number' => 'integer',
            'integration_mode' => FiscalIntegrationMode::class,
            'active' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(
            FiscalOrganizationProfile::class,
            'fiscal_organization_profile_id'
        );
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(FiscalDocument::class);
    }
}
