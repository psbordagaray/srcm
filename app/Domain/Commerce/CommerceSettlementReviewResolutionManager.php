<?php

namespace App\Domain\Commerce;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CommerceSettlementReviewResolutionOutcome;
use App\Models\CommerceSettlementReview;
use App\Models\CommerceSettlementReviewResolution;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

final class CommerceSettlementReviewResolutionManager
{
    public const FOUNDATION_VERSION = 1;

    public const FOUNDATION_STATUS =
        'SEPARATE_IMMUTABLE_ADMIN_ONLY_NO_BUSINESS_EFFECT';

    public const AUDIT_EVENT =
        'commerce_settlement_review.resolved';

    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly AuditRecorder $audit,
    ) {
    }

    public function resolve(
        CommerceSettlementReviewResolutionData $data,
        User $actor,
    ): CommerceSettlementReviewResolution {
        $organizationId = $this->currentOrganization->id($actor);
        $role = $this->currentOrganization->roleFor($actor);

        if (
            ! ($role
                ?->canResolveCommerceSettlementReview() ?? false)
        ) {
            throw new DomainException(
                'No posee permiso para resolver una revisión de liquidación.'
            );
        }

        $normalized = $this->normalize(
            $data,
            $organizationId
        );

        try {
            return DB::transaction(function () use (
                $normalized,
                $organizationId,
                $actor,
            ): CommerceSettlementReviewResolution {
                $review = CommerceSettlementReview::query()
                    ->forOrganization($organizationId)
                    ->whereKey($normalized['review_id'])
                    ->lockForUpdate()
                    ->first();

                if (! $review) {
                    throw new DomainException(
                        'La revisión de liquidación no pertenece a la organización activa.'
                    );
                }

                $byKey =
                    CommerceSettlementReviewResolution::query()
                        ->forOrganization($organizationId)
                        ->where(
                            'idempotency_key',
                            $normalized['idempotency_key']
                        )
                        ->lockForUpdate()
                        ->first();

                if ($byKey) {
                    return $this->reconcileIdempotent(
                        $byKey,
                        $review,
                        $normalized['fingerprint']
                    );
                }

                $existing =
                    CommerceSettlementReviewResolution::query()
                        ->forOrganization($organizationId)
                        ->where(
                            'commerce_settlement_review_id',
                            $review->id
                        )
                        ->lockForUpdate()
                        ->first();

                if ($existing) {
                    throw new DomainException(
                        'La revisión de liquidación ya posee una resolución final.'
                    );
                }

                $now = CarbonImmutable::now();

                $resolution =
                    CommerceSettlementReviewResolution::query()
                        ->create([
                            'organization_id' => $organizationId,
                            'commerce_settlement_review_id' =>
                                $review->id,
                            'outcome' =>
                                $normalized['outcome'],
                            'reason' =>
                                $normalized['reason'],
                            'notes' =>
                                $normalized['notes'],
                            'resolved_by_user_id' =>
                                $actor->id,
                            'resolved_at' => $now,
                            'idempotency_key' =>
                                $normalized['idempotency_key'],
                            'fingerprint' =>
                                $normalized['fingerprint'],
                            'created_at' => $now,
                        ]);

                $this->audit->record(
                    model: $resolution,
                    event: self::AUDIT_EVENT,
                    oldValues: null,
                    newValues: [
                        'public_id' =>
                            (string) $resolution->public_id,
                        'commerce_settlement_review_id' =>
                            (int) $review->id,
                        'review_public_id' =>
                            (string) $review->public_id,
                        'outcome' =>
                            $normalized['outcome'],
                        'resolved_by_user_id' =>
                            (int) $actor->id,
                        'resolved_at' => $now,
                        'idempotency_key' =>
                            $normalized['idempotency_key'],
                        'fingerprint' =>
                            $normalized['fingerprint'],
                    ],
                );

                return $resolution->refresh()->load([
                    'review',
                    'resolvedBy',
                ]);
            }, 3);
        } catch (QueryException $queryException) {
            $byKey = CommerceSettlementReviewResolution::query()
                ->forOrganization($organizationId)
                ->where(
                    'idempotency_key',
                    $normalized['idempotency_key']
                )
                ->first();

            if ($byKey) {
                $review = CommerceSettlementReview::query()
                    ->forOrganization($organizationId)
                    ->whereKey($normalized['review_id'])
                    ->first();

                if (! $review) {
                    throw $queryException;
                }

                return $this->reconcileIdempotent(
                    $byKey,
                    $review,
                    $normalized['fingerprint']
                );
            }

            $existing = CommerceSettlementReviewResolution::query()
                ->forOrganization($organizationId)
                ->where(
                    'commerce_settlement_review_id',
                    $normalized['review_id']
                )
                ->first();

            if ($existing) {
                throw new DomainException(
                    'La revisión de liquidación ya posee una resolución final.',
                    previous: $queryException
                );
            }

            throw $queryException;
        }
    }

    /**
     * @return array{
     *   review_id:int,
     *   outcome:CommerceSettlementReviewResolutionOutcome,
     *   reason:string,
     *   notes:?string,
     *   idempotency_key:string,
     *   fingerprint:string
     * }
     */
    private function normalize(
        CommerceSettlementReviewResolutionData $data,
        int $organizationId,
    ): array {
        if ($data->commerceSettlementReviewId <= 0) {
            throw new DomainException(
                'La resolución requiere una revisión de liquidación válida.'
            );
        }

        $reason = Str::of($data->reason)
            ->squish()
            ->toString();

        if (
            strlen($reason) < 10
            || strlen($reason) > 1000
            || preg_match('/[\x00-\x1F\x7F]/', $reason) === 1
        ) {
            throw new DomainException(
                'La resolución requiere un motivo canónico de 10 a 1000 caracteres.'
            );
        }

        $notes = filled($data->notes)
            ? trim((string) $data->notes)
            : null;

        if (
            $notes !== null
            && (
                strlen($notes) > 2000
                || preg_match('/[\x00-\x1F\x7F]/', $notes) === 1
            )
        ) {
            throw new DomainException(
                'La nota de resolución supera la longitud o formato admitidos.'
            );
        }

        $idempotencyKey = trim($data->idempotencyKey);

        if (
            $idempotencyKey === ''
            || $idempotencyKey !== $data->idempotencyKey
            || strlen($idempotencyKey) > 180
            || preg_match(
                '/[\x00-\x1F\x7F]/',
                $idempotencyKey
            ) === 1
        ) {
            throw new DomainException(
                'La clave idempotente de resolución no es válida.'
            );
        }

        $payload = [
            'organization_id' => $organizationId,
            'commerce_settlement_review_id' =>
                $data->commerceSettlementReviewId,
            'outcome' => $data->outcome->value,
            'reason' => $reason,
            'notes' => $notes,
            'idempotency_key' => $idempotencyKey,
        ];

        return [
            'review_id' =>
                $data->commerceSettlementReviewId,
            'outcome' => $data->outcome,
            'reason' => $reason,
            'notes' => $notes,
            'idempotency_key' => $idempotencyKey,
            'fingerprint' => $this->fingerprint($payload),
        ];
    }

    private function reconcileIdempotent(
        CommerceSettlementReviewResolution $existing,
        CommerceSettlementReview $review,
        string $fingerprint,
    ): CommerceSettlementReviewResolution {
        if (
            (int) $existing->commerce_settlement_review_id
                !== (int) $review->id
            || ! hash_equals(
                (string) $existing->fingerprint,
                $fingerprint
            )
        ) {
            throw new DomainException(
                'La clave idempotente de resolución ya fue utilizada con otro contenido.'
            );
        }

        return $existing->load([
            'review',
            'resolvedBy',
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function fingerprint(array $payload): string
    {
        return hash(
            'sha256',
            $this->canonicalJson($payload)
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function canonicalJson(array $payload): string
    {
        try {
            return json_encode(
                $this->canonicalize($payload),
                JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $exception) {
            throw new DomainException(
                'No pudo consolidarse la resolución de revisión de liquidación.',
                previous: $exception
            );
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed =>
                    $this->canonicalize($item),
                $value
            );
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
