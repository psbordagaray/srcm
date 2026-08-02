<?php

namespace App\Models;

use App\Enums\ServiceIdentifierType;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ServiceAssetIdentifier extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'service_asset_id',
        'identifier_type',
        'value',
        'created_by_user_id',
    ];

    protected static function booted(): void
    {
        static::saving(function (ServiceAssetIdentifier $identifier): void {
            $type = $identifier->identifier_type;

            if (! $type instanceof ServiceIdentifierType) {
                $type = ServiceIdentifierType::from((string) $type);
            }

            $normalized = $type->normalize((string) $identifier->value);

            if ($normalized === '') {
                throw new DomainException(
                    'El identificador técnico no puede quedar vacío.'
                );
            }

            $identifier->normalized_value = $normalized;

            if ($identifier->exists && $identifier->isDirty()) {
                throw new DomainException(
                    'Los identificadores técnicos son inmutables.'
                );
            }
        });

        static::deleting(function (): void {
            throw new DomainException(
                'Un identificador técnico no puede eliminarse.'
            );
        });
    }

    protected function casts(): array
    {
        return [
            'identifier_type' => ServiceIdentifierType::class,
        ];
    }

    public function setValueAttribute(string $value): void
    {
        $this->attributes['value'] = Str::of($value)
            ->squish()
            ->toString();
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(
            ServiceAsset::class,
            'service_asset_id'
        );
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
