<?php

namespace App\Models;

use App\Enums\FinancialAccountType;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class FinancialAccount extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'public_id',
        'name',
        'normalized_name',
        'type',
        'provider',
        'currency_code',
        'external_label',
        'active',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (FinancialAccount $account): void {
            if (blank($account->public_id)) {
                $account->public_id = (string) Str::uuid();
            }
        });

        static::deleting(fn () => throw new DomainException(
            'Una cuenta financiera no puede eliminarse físicamente.'
        ));
    }

    protected function casts(): array
    {
        return [
            'type' => FinancialAccountType::class,
            'active' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function externalMovements(): HasMany
    {
        return $this->hasMany(FinancialExternalMovement::class);
    }

    public function providerConnection(): HasOne
    {
        return $this->hasOne(
            FinancialProviderConnection::class,
            'financial_account_id'
        );
    }
}
