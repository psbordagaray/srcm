<?php

namespace App\Models;

use App\Enums\ServiceWarrantyClaimStatus;
use App\Enums\ServiceWarrantyTemporalStatus;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class ServiceWarrantyClaim extends Model
{
    use BelongsToOrganization;

    protected $attributes = [
        'status' => 'pending_review',
    ];

    protected $fillable = [
        'organization_id',
        'public_id',
        'service_warranty_grant_id',
        'open_warranty_grant_id',
        'original_service_order_id',
        'original_service_delivery_id',
        'corrective_service_order_id',
        'claimant_business_party_id',
        'claimant_name',
        'channel',
        'customer_reference',
        'reported_issue',
        'reentry_condition_notes',
        'accessories_snapshot',
        'warranty_status_at_claim',
        'claimed_at',
        'received_at',
        'received_by_user_id',
        'intake_location_id',
        'status',
        'closed_at',
        'idempotency_key',
        'fingerprint',
    ];

    protected static function booted(): void
    {
        static::creating(function (ServiceWarrantyClaim $claim): void {
            if (blank($claim->public_id)) {
                $claim->public_id = (string) Str::uuid();
            }
        });

        static::updating(function (ServiceWarrantyClaim $claim): void {
            if ($claim->isDirty([
                'organization_id',
                'public_id',
                'service_warranty_grant_id',
                'original_service_order_id',
                'original_service_delivery_id',
                'corrective_service_order_id',
                'claimant_business_party_id',
                'claimant_name',
                'channel',
                'customer_reference',
                'reported_issue',
                'reentry_condition_notes',
                'accessories_snapshot',
                'warranty_status_at_claim',
                'claimed_at',
                'received_at',
                'received_by_user_id',
                'intake_location_id',
                'idempotency_key',
                'fingerprint',
            ])) {
                throw new DomainException(
                    'Los hechos de ingreso del reclamo de garantía son inmutables.'
                );
            }

            if (
                $claim->isDirty('status')
                && ! $claim->allowsTransitionTo($claim->status)
            ) {
                throw new DomainException(
                    'La transición del reclamo de garantía no es válida.'
                );
            }

            if ($claim->status === ServiceWarrantyClaimStatus::Closed) {
                if (
                    $claim->open_warranty_grant_id !== null
                    || $claim->closed_at === null
                ) {
                    throw new DomainException(
                        'El cierre del reclamo debe liberar la garantía abierta.'
                    );
                }

                return;
            }

            if (
                (int) $claim->open_warranty_grant_id
                    !== (int) $claim->service_warranty_grant_id
                || $claim->closed_at !== null
            ) {
                throw new DomainException(
                    'Un reclamo abierto debe conservar la garantía bloqueada.'
                );
            }
        });

        static::deleting(fn () => throw new DomainException(
            'Un reclamo de garantía no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'status' => ServiceWarrantyClaimStatus::class,
            'warranty_status_at_claim' => ServiceWarrantyTemporalStatus::class,
            'claimed_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function allowsTransitionTo(ServiceWarrantyClaimStatus $target): bool
    {
        $original = ServiceWarrantyClaimStatus::from(
            (string) $this->getRawOriginal('status')
        );

        return match ($original) {
            ServiceWarrantyClaimStatus::PendingReview => in_array(
                $target,
                [
                    ServiceWarrantyClaimStatus::Accepted,
                    ServiceWarrantyClaimStatus::PartiallyAccepted,
                    ServiceWarrantyClaimStatus::Rejected,
                ],
                true
            ),
            ServiceWarrantyClaimStatus::Accepted,
            ServiceWarrantyClaimStatus::PartiallyAccepted => $target === ServiceWarrantyClaimStatus::InCorrectiveWork,
            ServiceWarrantyClaimStatus::Rejected => $target === ServiceWarrantyClaimStatus::ReadyForReturn,
            ServiceWarrantyClaimStatus::InCorrectiveWork,
            ServiceWarrantyClaimStatus::ReadyForReturn => $target === ServiceWarrantyClaimStatus::Closed,
            default => false,
        };
    }

    public function warrantyGrant(): BelongsTo
    {
        return $this->belongsTo(
            ServiceWarrantyGrant::class,
            'service_warranty_grant_id'
        );
    }

    public function originalOrder(): BelongsTo
    {
        return $this->belongsTo(
            ServiceOrder::class,
            'original_service_order_id'
        );
    }

    public function originalDelivery(): BelongsTo
    {
        return $this->belongsTo(
            ServiceDelivery::class,
            'original_service_delivery_id'
        );
    }

    public function correctiveOrder(): BelongsTo
    {
        return $this->belongsTo(
            ServiceOrder::class,
            'corrective_service_order_id'
        );
    }

    public function claimant(): BelongsTo
    {
        return $this->belongsTo(
            BusinessParty::class,
            'claimant_business_party_id'
        );
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    public function intakeLocation(): BelongsTo
    {
        return $this->belongsTo(
            InventoryLocation::class,
            'intake_location_id'
        );
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(ServiceWarrantyClaimStatusHistory::class)
            ->orderBy('changed_at')
            ->orderBy('id');
    }

    public function resolution(): HasOne
    {
        return $this->hasOne(ServiceWarrantyClaimResolution::class);
    }

    public function returnRecord(): HasOne
    {
        return $this->hasOne(ServiceWarrantyClaimReturn::class);
    }
}
