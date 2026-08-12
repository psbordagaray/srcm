<?php

namespace App\Models;

use App\Enums\CashRegisterSessionStatus;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CashRegisterSession extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'public_id',
        'cash_register_id',
        'opened_by_user_id',
        'status',
        'currency_code',
        'opening_amount_minor',
        'idempotency_key',
        'fingerprint',
        'opened_at',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (CashRegisterSession $session): void {
            if (blank($session->public_id)) {
                $session->public_id = (string) Str::uuid();
            }
        });

        static::deleting(fn () => throw new DomainException(
            'Un turno de caja no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'status' => CashRegisterSessionStatus::class,
            'opening_amount_minor' => 'integer',
            'opened_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class, 'cash_register_id');
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(
            CashMovement::class,
            'cash_register_session_id'
        );
    }
}
