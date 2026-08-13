<?php

namespace App\Models;

use App\Enums\CashCountDifferenceReason;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CashRegisterClosure extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'public_id',
        'cash_register_session_id',
        'cash_register_id',
        'financial_account_id',
        'opened_by_user_id',
        'closed_by_user_id',
        'expected_amount_minor',
        'counted_amount_minor',
        'difference_minor',
        'currency_code',
        'difference_reason',
        'note',
        'idempotency_key',
        'fingerprint',
        'closed_at',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (CashRegisterClosure $closure): void {
            if (blank($closure->public_id)) {
                $closure->public_id = (string) Str::uuid();
            }
        });

        static::updating(fn () => throw new DomainException(
            'Un arqueo cerrado no puede modificarse.'
        ));

        static::deleting(fn () => throw new DomainException(
            'Un arqueo cerrado no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'expected_amount_minor' => 'integer',
            'counted_amount_minor' => 'integer',
            'difference_minor' => 'integer',
            'difference_reason' => CashCountDifferenceReason::class,
            'closed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(
            CashRegisterSession::class,
            'cash_register_session_id'
        );
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class, 'cash_register_id');
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(
            FinancialAccount::class,
            'financial_account_id'
        );
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }
}
