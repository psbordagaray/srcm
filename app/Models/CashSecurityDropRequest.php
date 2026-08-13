<?php

namespace App\Models;

use App\Enums\CashSecurityDropReason;
use App\Enums\CashSecurityDropRequestStatus;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class CashSecurityDropRequest extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'public_id',
        'cash_register_session_id',
        'cash_register_id',
        'origin_financial_account_id',
        'destination_financial_account_id',
        'requested_by_user_id',
        'approved_by_user_id',
        'executed_by_user_id',
        'resolved_by_user_id',
        'amount_minor',
        'currency_code',
        'reason_code',
        'note',
        'status',
        'request_idempotency_key',
        'fingerprint',
        'approval_idempotency_key',
        'approval_fingerprint',
        'approval_note',
        'execution_idempotency_key',
        'resolution_idempotency_key',
        'resolution_note',
        'requested_at',
        'approved_at',
        'executed_at',
        'resolved_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (CashSecurityDropRequest $request): void {
            if (blank($request->public_id)) {
                $request->public_id = (string) Str::uuid();
            }
        });

        static::updating(function (CashSecurityDropRequest $request): void {
            foreach ([
                'organization_id',
                'public_id',
                'cash_register_session_id',
                'cash_register_id',
                'origin_financial_account_id',
                'destination_financial_account_id',
                'requested_by_user_id',
                'amount_minor',
                'currency_code',
                'reason_code',
                'note',
                'request_idempotency_key',
                'fingerprint',
                'requested_at',
            ] as $attribute) {
                if ($request->isDirty($attribute)) {
                    throw new DomainException(
                        'Los datos autorizables de un retiro son inmutables.'
                    );
                }
            }

            $original = CashSecurityDropRequestStatus::from(
                (string) $request->getRawOriginal('status')
            );

            if ($original->isTerminal()) {
                throw new DomainException(
                    'Una solicitud de retiro resuelta es inmutable.'
                );
            }
        });

        static::deleting(fn () => throw new DomainException(
            'Una solicitud de retiro de seguridad no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'reason_code' => CashSecurityDropReason::class,
            'status' => CashSecurityDropRequestStatus::class,
            'requested_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'executed_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
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

    public function originFinancialAccount(): BelongsTo
    {
        return $this->belongsTo(
            FinancialAccount::class,
            'origin_financial_account_id'
        );
    }

    public function destinationFinancialAccount(): BelongsTo
    {
        return $this->belongsTo(
            FinancialAccount::class,
            'destination_financial_account_id'
        );
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function executedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by_user_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function movement(): HasOne
    {
        return $this->hasOne(
            CashMovement::class,
            'cash_security_drop_request_id'
        );
    }
}
