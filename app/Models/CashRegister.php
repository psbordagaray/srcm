<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CashRegister extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'public_id',
        'financial_account_id',
        'name',
        'normalized_name',
        'active',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (CashRegister $register): void {
            if (blank($register->public_id)) {
                $register->public_id = (string) Str::uuid();
            }
        });

        static::deleting(fn () => throw new DomainException(
            'Una caja operativa no puede eliminarse físicamente.'
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

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(
            FinancialAccount::class,
            'financial_account_id'
        );
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(CashRegisterSession::class);
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
