<?php

namespace App\Domain\Commerce;

use App\Domain\Numerics\NumericalDiscrepancyDecision;
use App\Models\CommerceSettlementReview;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use JsonException;

final class CommerceSettlementReviewRecorder
{
    public const FOUNDATION_VERSION = 1;

    public const RUNTIME_WIRING_STATUS =
        'CONTROLLER_POST_ROLLBACK_REVIEW_PERSISTENCE_WIRED_HARD_FAIL_PRESERVED';

    public function record(
        CommerceSettlementDiscrepancyDecisionException $exception,
        string $checkoutIdempotencyKey,
        int $organizationId,
        User $actor,
    ): CommerceSettlementReview {
        $this->guardCheckoutKey($checkoutIdempotencyKey);

        $runtime = $exception->runtimeEvidence->toArray();
        $decision = $exception->decisionEvidence->toArray();

        if (
            $exception->decisionEvidence->decision
                !== NumericalDiscrepancyDecision::KeepReference
            || $exception->decisionEvidence->finalValueMinor
                !== $exception->runtimeEvidence->systemTotalMinor
        ) {
            throw new DomainException(
                'La revisión de liquidación sólo admite KEEP_REFERENCE preservando el total del sistema.'
            );
        }

        $fingerprint = $this->fingerprint([
            'organization_id' => $organizationId,
            'checkout_idempotency_key' => $checkoutIdempotencyKey,
            'system_total_minor' =>
                $exception->runtimeEvidence->systemTotalMinor,
            'settled_total_minor' =>
                $exception->runtimeEvidence->settledTotalMinor,
            'decision' =>
                $exception->decisionEvidence->decision->value,
            'final_value_minor' =>
                $exception->decisionEvidence->finalValueMinor,
            'reason' => $exception->decisionEvidence->reason,
            'warning_code' =>
                CommerceSettlementDiscrepancyDecisionEvidence::WARNING_CODE,
            'runtime_evidence_snapshot' => $runtime,
            'decision_evidence_snapshot' => $decision,
            'requested_by_user_id' => (int) $actor->id,
        ]);

        $existing = CommerceSettlementReview::query()
            ->forOrganization($organizationId)
            ->where(
                'checkout_idempotency_key',
                $checkoutIdempotencyKey
            )
            ->first();

        if ($existing) {
            return $this->reconcileExisting(
                $existing,
                $fingerprint
            );
        }

        $now = CarbonImmutable::now();

        try {
            return CommerceSettlementReview::query()->create([
                'organization_id' => $organizationId,
                'checkout_idempotency_key' =>
                    $checkoutIdempotencyKey,
                'review_fingerprint' => $fingerprint,
                'system_total_minor' =>
                    $exception->runtimeEvidence->systemTotalMinor,
                'settled_total_minor' =>
                    $exception->runtimeEvidence->settledTotalMinor,
                'decision' =>
                    $exception->decisionEvidence->decision->value,
                'final_value_minor' =>
                    $exception->decisionEvidence->finalValueMinor,
                'reason' =>
                    $exception->decisionEvidence->reason,
                'warning_code' =>
                    CommerceSettlementDiscrepancyDecisionEvidence::
                        WARNING_CODE,
                'runtime_evidence_snapshot' => $runtime,
                'decision_evidence_snapshot' => $decision,
                'requested_by_user_id' => $actor->id,
                'requested_at' => $now,
                'created_at' => $now,
            ])->refresh();
        } catch (QueryException $queryException) {
            $existing = CommerceSettlementReview::query()
                ->forOrganization($organizationId)
                ->where(
                    'checkout_idempotency_key',
                    $checkoutIdempotencyKey
                )
                ->first();

            if (! $existing) {
                throw $queryException;
            }

            return $this->reconcileExisting(
                $existing,
                $fingerprint
            );
        }
    }

    private function reconcileExisting(
        CommerceSettlementReview $existing,
        string $fingerprint,
    ): CommerceSettlementReview {
        if (
            ! hash_equals(
                (string) $existing->review_fingerprint,
                $fingerprint
            )
        ) {
            throw new DomainException(
                'La liquidación ya posee otra revisión para la misma clave de checkout.'
            );
        }

        return $existing;
    }

    private function guardCheckoutKey(
        string $checkoutIdempotencyKey
    ): void {
        if (
            trim($checkoutIdempotencyKey) === ''
            || trim($checkoutIdempotencyKey)
                !== $checkoutIdempotencyKey
            || strlen($checkoutIdempotencyKey) > 255
            || preg_match(
                '/[\x00-\x1F\x7F]/',
                $checkoutIdempotencyKey
            ) === 1
        ) {
            throw new DomainException(
                'La revisión de liquidación requiere una clave de checkout canónica.'
            );
        }
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
                'No pudo consolidarse la evidencia de revisión de liquidación.',
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
