<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class FinancialProviderConnection extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'financial_account_id',
        'public_id',
        'provider_key',
        'external_account_id',
        'active',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (
            FinancialProviderConnection $connection
        ): void {
            if (blank($connection->public_id)) {
                $connection->public_id = (string) Str::uuid();
            }
        });

        static::updating(function (
            FinancialProviderConnection $connection
        ): void {
            if (
                $connection->isDirty([
                    'organization_id',
                    'financial_account_id',
                    'public_id',
                    'provider_key',
                    'external_account_id',
                    'created_by_user_id',
                ])
            ) {
                throw new DomainException(
                    'La identidad de la conexión financiera es inmutable.'
                );
            }
        });

        static::deleting(fn () => throw new DomainException(
            'Una conexión financiera no puede eliminarse físicamente.'
        ));
    }

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(
            FinancialAccount::class,
            'financial_account_id'
        );
    }

    public function compatibilityBindings(): HasMany
    {
        return $this->hasMany(
            FinancialProviderConnectionCompatibilityBinding::class,
            'financial_provider_connection_id'
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
}
