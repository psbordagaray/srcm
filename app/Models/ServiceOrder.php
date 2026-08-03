<?php

namespace App\Models;

use App\Enums\ServiceOrderStatus;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class ServiceOrder extends Model
{
    use BelongsToOrganization;

    protected $attributes = [
        'status' => 'received',
    ];

    protected $fillable = [
        'organization_id',
        'public_id',
        'order_number',
        'service_asset_id',
        'customer_business_party_id',
        'owner_business_party_id',
        'intake_location_id',
        'status',
        'created_by_user_id',
        'received_at',
        'promised_at',
        'idempotency_key',
        'metadata',
    ];

    protected static function booted(): void
    {
        static::creating(function (ServiceOrder $order): void {
            if (blank($order->public_id)) {
                $order->public_id = (string) Str::uuid();
            }
        });

        static::updating(function (ServiceOrder $order): void {
            if ($order->isDirty([
                'organization_id',
                'public_id',
                'order_number',
                'service_asset_id',
                'customer_business_party_id',
                'owner_business_party_id',
                'intake_location_id',
                'created_by_user_id',
                'received_at',
                'promised_at',
                'idempotency_key',
                'metadata',
            ])) {
                throw new DomainException(
                    'La orden recibida es inmutable hasta registrar una transición.'
                );
            }

            if (
                $order->isDirty('status')
                && ! $order->allowsTransitionTo($order->status)
            ) {
                throw new DomainException(
                    'La transición solicitada no es válida para la orden.'
                );
            }
        });

        static::deleting(function (): void {
            throw new DomainException(
                'Una orden de servicio no puede eliminarse físicamente.'
            );
        });
    }

    protected function casts(): array
    {
        return [
            'status' => ServiceOrderStatus::class,
            'received_at' => 'immutable_datetime',
            'promised_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(
            ServiceAsset::class,
            'service_asset_id'
        );
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(
            BusinessParty::class,
            'customer_business_party_id'
        );
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(
            BusinessParty::class,
            'owner_business_party_id'
        );
    }

    public function intakeLocation(): BelongsTo
    {
        return $this->belongsTo(
            InventoryLocation::class,
            'intake_location_id'
        );
    }

    public function intake(): HasOne
    {
        return $this->hasOne(ServiceOrderIntake::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(ServiceOrderStatusHistory::class)
            ->orderBy('changed_at')
            ->orderBy('id');
    }

    public function custodyEvents(): HasMany
    {
        return $this->hasMany(ServiceCustodyEvent::class)
            ->orderBy('occurred_at')
            ->orderBy('id');
    }

    public function diagnostics(): HasMany
    {
        return $this->hasMany(ServiceDiagnostic::class)
            ->orderBy('revision');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(ServiceQuote::class)
            ->orderBy('revision');
    }

    public function workItems(): HasMany
    {
        return $this->hasMany(ServiceWorkItem::class)
            ->orderBy('sequence');
    }

    public function partRequirements(): HasMany
    {
        return $this->hasMany(ServicePartRequirement::class);
    }

    public function partPurchases(): HasMany
    {
        return $this->hasMany(ServicePartPurchase::class)
            ->orderBy('purchased_at')
            ->orderBy('id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function allowsTransitionTo(ServiceOrderStatus $target): bool
    {
        $original = ServiceOrderStatus::from(
            (string) $this->getRawOriginal('status')
        );

        return match ($original) {
            ServiceOrderStatus::Received =>
                $target === ServiceOrderStatus::Diagnosing,
            ServiceOrderStatus::Diagnosing =>
                $target === ServiceOrderStatus::AwaitingApproval,
            ServiceOrderStatus::AwaitingApproval => in_array(
                $target,
                [
                    ServiceOrderStatus::InProgress,
                    ServiceOrderStatus::Diagnosing,
                ],
                true
            ),
            ServiceOrderStatus::InProgress => in_array(
                $target,
                [
                    ServiceOrderStatus::AwaitingParts,
                    ServiceOrderStatus::WithExternalProvider,
                    ServiceOrderStatus::QualityControl,
                    ServiceOrderStatus::Diagnosing,
                ],
                true
            ),
            ServiceOrderStatus::AwaitingParts =>
                $target === ServiceOrderStatus::InProgress,
            ServiceOrderStatus::WithExternalProvider =>
                $target === ServiceOrderStatus::InProgress,
            default => false,
        };
    }
}
