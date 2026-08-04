<?php

namespace App\Models;

use App\Enums\ServiceEvidenceContext;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ServiceEvidence extends Model
{
    use BelongsToOrganization;

    protected $table = 'service_evidences';

    protected $fillable = [
        'organization_id',
        'service_order_id',
        'public_id',
        'context',
        'service_order_intake_id',
        'service_diagnostic_id',
        'service_work_item_id',
        'service_part_requirement_id',
        'service_custody_event_id',
        'service_quality_inspection_id',
        'service_delivery_id',
        'service_cancellation_request_id',
        'service_cancellation_resolution_id',
        'service_cancellation_return_id',
        'service_warranty_claim_id',
        'service_warranty_claim_resolution_id',
        'service_warranty_claim_return_id',
        'original_filename',
        'stored_filename',
        'disk',
        'path',
        'path_hash',
        'mime_type',
        'extension',
        'size_bytes',
        'sha256',
        'description',
        'captured_at',
        'uploaded_by_user_id',
        'idempotency_key',
        'fingerprint',
    ];

    protected $hidden = [
        'stored_filename',
        'disk',
        'path',
        'path_hash',
    ];

    protected static function booted(): void
    {
        static::creating(function (ServiceEvidence $evidence): void {
            if (blank($evidence->public_id)) {
                $evidence->public_id = (string) Str::uuid();
            }
        });

        static::updating(fn () => throw new DomainException(
            'La evidencia confirmada es inmutable.'
        ));

        static::deleting(fn () => throw new DomainException(
            'La evidencia confirmada no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'context' => ServiceEvidenceContext::class,
            'size_bytes' => 'integer',
            'captured_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function referenceId(): ?int
    {
        $column = $this->context->referenceColumn();

        return $column === null
            ? null
            : (int) $this->getAttribute($column);
    }

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function orderIntake(): BelongsTo
    {
        return $this->belongsTo(
            ServiceOrderIntake::class,
            'service_order_intake_id'
        );
    }

    public function diagnostic(): BelongsTo
    {
        return $this->belongsTo(
            ServiceDiagnostic::class,
            'service_diagnostic_id'
        );
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(
            ServiceWorkItem::class,
            'service_work_item_id'
        );
    }

    public function partRequirement(): BelongsTo
    {
        return $this->belongsTo(
            ServicePartRequirement::class,
            'service_part_requirement_id'
        );
    }

    public function custodyEvent(): BelongsTo
    {
        return $this->belongsTo(
            ServiceCustodyEvent::class,
            'service_custody_event_id'
        );
    }

    public function qualityInspection(): BelongsTo
    {
        return $this->belongsTo(
            ServiceQualityInspection::class,
            'service_quality_inspection_id'
        );
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(
            ServiceDelivery::class,
            'service_delivery_id'
        );
    }

    public function cancellationRequest(): BelongsTo
    {
        return $this->belongsTo(
            ServiceCancellationRequest::class,
            'service_cancellation_request_id'
        );
    }

    public function cancellationResolution(): BelongsTo
    {
        return $this->belongsTo(
            ServiceCancellationResolution::class,
            'service_cancellation_resolution_id'
        );
    }

    public function cancellationReturn(): BelongsTo
    {
        return $this->belongsTo(
            ServiceCancellationReturn::class,
            'service_cancellation_return_id'
        );
    }

    public function warrantyClaim(): BelongsTo
    {
        return $this->belongsTo(
            ServiceWarrantyClaim::class,
            'service_warranty_claim_id'
        );
    }

    public function warrantyResolution(): BelongsTo
    {
        return $this->belongsTo(
            ServiceWarrantyClaimResolution::class,
            'service_warranty_claim_resolution_id'
        );
    }

    public function warrantyReturn(): BelongsTo
    {
        return $this->belongsTo(
            ServiceWarrantyClaimReturn::class,
            'service_warranty_claim_return_id'
        );
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
