<?php

namespace App\Models;

use App\Enums\ServiceAssetType;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ServiceAsset extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'public_id',
        'asset_type',
        'brand_name',
        'model_name',
        'color',
        'notes',
        'created_by_user_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (ServiceAsset $asset): void {
            if (blank($asset->public_id)) {
                $asset->public_id = (string) Str::uuid();
            }
        });

        static::updating(function (ServiceAsset $asset): void {
            if ($asset->isDirty(['organization_id', 'public_id'])) {
                throw new DomainException(
                    'La identidad organizacional del activo es inmutable.'
                );
            }
        });

        static::deleting(function (): void {
            throw new DomainException(
                'Un activo de servicio no puede eliminarse físicamente.'
            );
        });
    }

    protected function casts(): array
    {
        return [
            'asset_type' => ServiceAssetType::class,
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function setBrandNameAttribute(string $value): void
    {
        $name = Str::of($value)->squish()->toString();

        $this->attributes['brand_name'] = $name;
        $this->attributes['normalized_brand_name'] =
            static::normalizeName($name);
    }

    public function setModelNameAttribute(string $value): void
    {
        $name = Str::of($value)->squish()->toString();

        $this->attributes['model_name'] = $name;
        $this->attributes['normalized_model_name'] =
            static::normalizeName($name);
    }

    public static function normalizeName(string $value): string
    {
        $ascii = Str::lower(Str::ascii(trim($value)));

        return preg_replace('/[^a-z0-9]+/', '', $ascii) ?? '';
    }

    public function identifiers(): HasMany
    {
        return $this->hasMany(ServiceAssetIdentifier::class)
            ->orderBy('identifier_type')
            ->orderBy('id');
    }

    public function serviceOrders(): HasMany
    {
        return $this->hasMany(ServiceOrder::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
