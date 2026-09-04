<?php

namespace App\Models;

use App\Domain\Commerce\CommerceSettlementDiscrepancyDecisionEvidence;
use App\Domain\Commerce\CommerceSettlementDiscrepancyException;
use App\Domain\Numerics\NumericalDiscrepancyDecision;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class CommerceSettlementReview extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'public_id',
        'checkout_idempotency_key',
        'review_fingerprint',
        'system_total_minor',
        'settled_total_minor',
        'decision',
        'final_value_minor',
        'reason',
        'warning_code',
        'runtime_evidence_snapshot',
        'decision_evidence_snapshot',
        'requested_by_user_id',
        'requested_at',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (CommerceSettlementReview $review): void {
            if (blank($review->public_id)) {
                $review->public_id = (string) Str::uuid();
            }

            $review->guardCreation();
        });

        static::updating(fn () => throw new DomainException(
            'Una revisión de liquidación registrada es inmutable.'
        ));

        static::deleting(fn () => throw new DomainException(
            'Una revisión de liquidación registrada no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'system_total_minor' => 'integer',
            'settled_total_minor' => 'integer',
            'final_value_minor' => 'integer',
            'runtime_evidence_snapshot' => 'array',
            'decision_evidence_snapshot' => 'array',
            'requested_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'requested_by_user_id'
        );
    }

    public function resolution(): HasOne
    {
        return $this->hasOne(
            CommerceSettlementReviewResolution::class,
            'commerce_settlement_review_id'
        );
    }

    private function guardCreation(): void
    {
        $checkoutKey = (string) $this->checkout_idempotency_key;
        $fingerprint = (string) $this->review_fingerprint;
        $reason = (string) $this->reason;
        $runtime = $this->runtime_evidence_snapshot;
        $decisionEvidence = $this->decision_evidence_snapshot;

        $membership = OrganizationMembership::query()
            ->where(
                'organization_id',
                $this->organization_id
            )
            ->where(
                'user_id',
                $this->requested_by_user_id
            )
            ->where('active', true)
            ->first();

        if (
            (int) $this->organization_id <= 0
            || trim($checkoutKey) === ''
            || trim($checkoutKey) !== $checkoutKey
            || strlen($checkoutKey) > 255
            || preg_match('/[\x00-\x1F\x7F]/', $checkoutKey) === 1
            || preg_match('/\A[a-f0-9]{64}\z/', $fingerprint) !== 1
            || (int) $this->system_total_minor <= 0
            || (int) $this->settled_total_minor <= 0
            || (int) $this->system_total_minor
                === (int) $this->settled_total_minor
            || (string) $this->decision
                !== NumericalDiscrepancyDecision::KeepReference->value
            || (int) $this->final_value_minor
                !== (int) $this->system_total_minor
            || trim($reason) === ''
            || trim($reason) !== $reason
            || strlen($reason) > 2048
            || preg_match('/[\x00-\x1F\x7F]/', $reason) === 1
            || (string) $this->warning_code
                !== CommerceSettlementDiscrepancyDecisionEvidence::WARNING_CODE
            || ! is_array($runtime)
            || ! is_array($decisionEvidence)
            || ($runtime['schema'] ?? null)
                !== CommerceSettlementDiscrepancyException::SCHEMA
            || ($runtime['system_total_minor'] ?? null)
                !== (int) $this->system_total_minor
            || ($runtime['settled_total_minor'] ?? null)
                !== (int) $this->settled_total_minor
            || ($decisionEvidence['schema'] ?? null)
                !== CommerceSettlementDiscrepancyDecisionEvidence::SCHEMA
            || ($decisionEvidence['reference_value_minor'] ?? null)
                !== (int) $this->system_total_minor
            || ($decisionEvidence['observed_value_minor'] ?? null)
                !== (int) $this->settled_total_minor
            || ($decisionEvidence['decision'] ?? null)
                !== NumericalDiscrepancyDecision::KeepReference->value
            || ($decisionEvidence['final_value_minor'] ?? null)
                !== (int) $this->final_value_minor
            || ($decisionEvidence['reason'] ?? null)
                !== $reason
            || ($decisionEvidence['warning_code'] ?? null)
                !== (string) $this->warning_code
            || (int) $this->requested_by_user_id <= 0
            || $this->requested_at === null
            || $this->created_at === null
            || ! $membership
        ) {
            throw new DomainException(
                'La revisión de liquidación no conserva identidad, evidencia y trazabilidad válidas.'
            );
        }
    }
}
