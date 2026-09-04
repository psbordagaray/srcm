<?php

namespace App\Models;

use App\Enums\CommerceSettlementReviewResolutionOutcome;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CommerceSettlementReviewResolution extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'public_id',
        'commerce_settlement_review_id',
        'outcome',
        'reason',
        'notes',
        'resolved_by_user_id',
        'resolved_at',
        'idempotency_key',
        'fingerprint',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::creating(
            function (
                CommerceSettlementReviewResolution $resolution
            ): void {
                if (blank($resolution->public_id)) {
                    $resolution->public_id = (string) Str::uuid();
                }

                $resolution->guardCreation();
            }
        );

        static::updating(fn () => throw new DomainException(
            'Una resolución de revisión de liquidación confirmada es inmutable.'
        ));

        static::deleting(fn () => throw new DomainException(
            'Una resolución de revisión de liquidación no puede eliminarse.'
        ));
    }

    protected function casts(): array
    {
        return [
            'outcome' => CommerceSettlementReviewResolutionOutcome::class,
            'resolved_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(
            CommerceSettlementReview::class,
            'commerce_settlement_review_id'
        );
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'resolved_by_user_id'
        );
    }

    private function guardCreation(): void
    {
        $reason = (string) $this->reason;
        $notes = $this->notes;
        $idempotencyKey = (string) $this->idempotency_key;
        $fingerprint = (string) $this->fingerprint;
        $outcome = $this->outcome;

        $review = CommerceSettlementReview::query()
            ->forOrganization((int) $this->organization_id)
            ->whereKey((int) $this->commerce_settlement_review_id)
            ->first();

        $membership = OrganizationMembership::query()
            ->where(
                'organization_id',
                $this->organization_id
            )
            ->where(
                'user_id',
                $this->resolved_by_user_id
            )
            ->where('active', true)
            ->first();

        if (
            (int) $this->organization_id <= 0
            || (int) $this->commerce_settlement_review_id <= 0
            || ! $review
            || ! $outcome
                instanceof CommerceSettlementReviewResolutionOutcome
            || trim($reason) === ''
            || trim($reason) !== $reason
            || strlen($reason) < 10
            || strlen($reason) > 1000
            || preg_match('/[\x00-\x1F\x7F]/', $reason) === 1
            || (
                $notes !== null
                && (
                    trim((string) $notes) !== (string) $notes
                    || strlen((string) $notes) > 2000
                    || preg_match(
                        '/[\x00-\x1F\x7F]/',
                        (string) $notes
                    ) === 1
                )
            )
            || trim($idempotencyKey) === ''
            || trim($idempotencyKey) !== $idempotencyKey
            || strlen($idempotencyKey) > 180
            || preg_match(
                '/[\x00-\x1F\x7F]/',
                $idempotencyKey
            ) === 1
            || preg_match('/\A[a-f0-9]{64}\z/', $fingerprint) !== 1
            || (int) $this->resolved_by_user_id <= 0
            || $this->resolved_at === null
            || $this->created_at === null
            || ! $membership
            || ! ($membership->role
                ?->canResolveCommerceSettlementReview() ?? false)
        ) {
            throw new DomainException(
                'La resolución de revisión de liquidación no conserva identidad, autorización y trazabilidad válidas.'
            );
        }
    }
}
