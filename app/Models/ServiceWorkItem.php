<?php

namespace App\Models;

use App\Enums\ServiceWorkExecutionMode;
use App\Enums\ServiceWorkStatus;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ServiceWorkItem extends Model
{
    use BelongsToOrganization;

    protected $attributes = ['status' => 'planned'];

    protected $fillable = [
        'organization_id',
        'service_order_id',
        'service_quote_option_id',
        'sequence',
        'title',
        'description',
        'execution_mode',
        'provider_business_party_id',
        'assigned_user_id',
        'status',
        'created_by_user_id',
        'planned_at',
        'idempotency_key',
        'fingerprint',
    ];

    protected static function booted(): void
    {
        static::updating(function (ServiceWorkItem $item): void {
            if ($item->isDirty([
                'organization_id',
                'service_order_id',
                'service_quote_option_id',
                'sequence',
                'title',
                'description',
                'execution_mode',
                'provider_business_party_id',
                'assigned_user_id',
                'created_by_user_id',
                'planned_at',
                'idempotency_key',
                'fingerprint',
            ])) {
                throw new DomainException(
                    'El alcance planificado del trabajo es inmutable.'
                );
            }

            if (
                $item->isDirty('status')
                && ! $item->allowsTransitionTo($item->status)
            ) {
                throw new DomainException(
                    'La transición del trabajo no es válida.'
                );
            }
        });

        static::deleting(fn () => throw new DomainException(
            'Un trabajo planificado no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'execution_mode' => ServiceWorkExecutionMode::class,
            'status' => ServiceWorkStatus::class,
            'planned_at' => 'immutable_datetime',
        ];
    }

    public function allowsTransitionTo(ServiceWorkStatus $target): bool
    {
        $original = ServiceWorkStatus::from(
            (string) $this->getRawOriginal('status')
        );

        return match ($original) {
            ServiceWorkStatus::Planned => in_array(
                $target,
                [
                    ServiceWorkStatus::InProgress,
                    ServiceWorkStatus::WithProvider,
                ],
                true
            ),
            ServiceWorkStatus::WithProvider =>
                $target === ServiceWorkStatus::InProgress,
            ServiceWorkStatus::InProgress => in_array(
                $target,
                [
                    ServiceWorkStatus::Completed,
                    ServiceWorkStatus::Unresolved,
                ],
                true
            ),
            default => false,
        };
    }

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function approvedOption(): BelongsTo
    {
        return $this->belongsTo(
            ServiceQuoteOption::class,
            'service_quote_option_id'
        );
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(
            BusinessParty::class,
            'provider_business_party_id'
        );
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(ServiceWorkStatusHistory::class)
            ->orderBy('changed_at')
            ->orderBy('id');
    }

    public function custodyLinks(): HasMany
    {
        return $this->hasMany(ServiceWorkCustodyLink::class)
            ->orderBy('id');
    }

    public function report(): HasOne
    {
        return $this->hasOne(ServiceWorkReport::class);
    }

    public function partRequirements(): HasMany
    {
        return $this->hasMany(ServicePartRequirement::class);
    }
}
