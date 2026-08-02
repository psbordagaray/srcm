<?php

namespace App\Domain\Service;

use App\Enums\ServiceOrderStatus;
use App\Enums\ServiceQuoteDecisionType;
use App\Models\OrganizationMembership;
use App\Models\ServiceDiagnostic;
use App\Models\ServiceDiagnosticFinding;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderStatusHistory;
use App\Models\ServiceQuote;
use App\Models\ServiceQuoteDecision;
use App\Models\ServiceQuoteLine;
use App\Models\ServiceQuoteOption;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use JsonException;
use Throwable;

final class ServiceAssessmentManager
{
    public function recordDiagnostic(
        ServiceDiagnosticData $data,
        User $actor
    ): ServiceDiagnostic {
        $normalized = $this->normalizeDiagnostic($data);

        return DB::transaction(function () use (
            $data,
            $actor,
            $normalized
        ): ServiceDiagnostic {
            $organizationId = $this->organizationId($actor);
            $this->guardActor($organizationId, $actor, 'diagnostic');

            $existing = ServiceDiagnostic::query()
                ->forOrganization($organizationId)
                ->where('idempotency_key', $normalized['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->guardFingerprint(
                    $existing->fingerprint,
                    $normalized['fingerprint'],
                    'diagnóstico'
                );

                return $existing->load('findings');
            }

            $order = $this->lockedOrder(
                $organizationId,
                $data->serviceOrderId
            );

            if (! in_array(
                $order->status,
                [
                    ServiceOrderStatus::Received,
                    ServiceOrderStatus::Diagnosing,
                ],
                true
            )) {
                throw new DomainException(
                    'La orden no admite un diagnóstico en su estado actual.'
                );
            }

            $diagnosedAt = CarbonImmutable::now();
            $revision = ((int) ServiceDiagnostic::query()
                ->forOrganization($organizationId)
                ->where('service_order_id', $order->id)
                ->max('revision')) + 1;

            $diagnostic = ServiceDiagnostic::query()->create([
                'organization_id' => $organizationId,
                'service_order_id' => $order->id,
                'revision' => $revision,
                'summary' => $normalized['summary'],
                'recommendation' => $normalized['recommendation'],
                'data_risk_notes' => $normalized['data_risk_notes'],
                'diagnosed_by_user_id' => $actor->id,
                'diagnosed_at' => $diagnosedAt,
                'idempotency_key' => $normalized['idempotency_key'],
                'fingerprint' => $normalized['fingerprint'],
            ]);

            foreach ($normalized['findings'] as $index => $finding) {
                ServiceDiagnosticFinding::query()->create([
                    'organization_id' => $organizationId,
                    'service_diagnostic_id' => $diagnostic->id,
                    'position' => $index + 1,
                    'severity' => $finding['severity'],
                    'category' => $finding['category'],
                    'description' => $finding['description'],
                    'evidence_notes' => $finding['evidence_notes'],
                ]);
            }

            if ($order->status === ServiceOrderStatus::Received) {
                $this->transition(
                    $order,
                    ServiceOrderStatus::Diagnosing,
                    $actor,
                    'Se registró el primer diagnóstico técnico.',
                    $diagnosedAt
                );
            }

            return $diagnostic->load('findings');
        }, 3);
    }

    public function issueQuote(
        ServiceQuoteData $data,
        User $actor
    ): ServiceQuote {
        $normalized = $this->normalizeQuote($data);

        return DB::transaction(function () use (
            $data,
            $actor,
            $normalized
        ): ServiceQuote {
            $organizationId = $this->organizationId($actor);
            $this->guardActor($organizationId, $actor, 'quote');

            $existing = ServiceQuote::query()
                ->forOrganization($organizationId)
                ->where('idempotency_key', $normalized['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->guardFingerprint(
                    $existing->fingerprint,
                    $normalized['fingerprint'],
                    'presupuesto'
                );

                return $existing->load('options.lines');
            }

            $order = $this->lockedOrder(
                $organizationId,
                $data->serviceOrderId
            );

            if ($order->status !== ServiceOrderStatus::Diagnosing) {
                throw new DomainException(
                    'La orden debe estar en diagnóstico para emitir un presupuesto.'
                );
            }

            $diagnostic = ServiceDiagnostic::query()
                ->forOrganization($organizationId)
                ->where('service_order_id', $order->id)
                ->latest('revision')
                ->lockForUpdate()
                ->first();

            if (! $diagnostic) {
                throw new DomainException(
                    'No se puede presupuestar sin un diagnóstico técnico.'
                );
            }

            $issuedAt = CarbonImmutable::now();
            $revision = ((int) ServiceQuote::query()
                ->forOrganization($organizationId)
                ->where('service_order_id', $order->id)
                ->max('revision')) + 1;

            $quote = ServiceQuote::query()->create([
                'organization_id' => $organizationId,
                'service_order_id' => $order->id,
                'service_diagnostic_id' => $diagnostic->id,
                'revision' => $revision,
                'currency_code' => $normalized['currency_code'],
                'valid_until' => $normalized['valid_until'],
                'terms' => $normalized['terms'],
                'issued_by_user_id' => $actor->id,
                'issued_at' => $issuedAt,
                'idempotency_key' => $normalized['idempotency_key'],
                'fingerprint' => $normalized['fingerprint'],
            ]);

            foreach ($normalized['options'] as $optionIndex => $option) {
                $quoteOption = ServiceQuoteOption::query()->create([
                    'organization_id' => $organizationId,
                    'service_quote_id' => $quote->id,
                    'option_number' => $optionIndex + 1,
                    'label' => $option['label'],
                    'description' => $option['description'],
                    'recommended' => $option['recommended'],
                    'total_minor' => $option['total_minor'],
                ]);

                foreach ($option['lines'] as $lineIndex => $line) {
                    ServiceQuoteLine::query()->create([
                        'organization_id' => $organizationId,
                        'service_quote_option_id' => $quoteOption->id,
                        'position' => $lineIndex + 1,
                        'line_type' => $line['line_type'],
                        'description' => $line['description'],
                        'quantity' => $line['quantity'],
                        'unit_price_minor' => $line['unit_price_minor'],
                        'line_total_minor' => $line['line_total_minor'],
                    ]);
                }
            }

            $this->transition(
                $order,
                ServiceOrderStatus::AwaitingApproval,
                $actor,
                "Se emitió el presupuesto revisión {$revision}.",
                $issuedAt
            );

            return $quote->load('options.lines');
        }, 3);
    }

    public function recordDecision(
        ServiceQuoteDecisionData $data,
        User $actor
    ): ServiceQuoteDecision {
        $normalized = $this->normalizeDecision($data);

        return DB::transaction(function () use (
            $data,
            $actor,
            $normalized
        ): ServiceQuoteDecision {
            $organizationId = $this->organizationId($actor);
            $this->guardActor($organizationId, $actor, 'decision');

            $existing = ServiceQuoteDecision::query()
                ->forOrganization($organizationId)
                ->where('idempotency_key', $normalized['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->guardFingerprint(
                    $existing->fingerprint,
                    $normalized['fingerprint'],
                    'decisión'
                );

                return $existing->load('selectedOption');
            }

            $quote = ServiceQuote::query()
                ->forOrganization($organizationId)
                ->whereKey($data->serviceQuoteId)
                ->with('options')
                ->lockForUpdate()
                ->first();

            if (! $quote) {
                throw new DomainException(
                    'El presupuesto no pertenece a la organización activa.'
                );
            }

            $order = $this->lockedOrder(
                $organizationId,
                (int) $quote->service_order_id
            );

            if ($order->status !== ServiceOrderStatus::AwaitingApproval) {
                throw new DomainException(
                    'La orden no está esperando una decisión del cliente.'
                );
            }

            $latestQuoteId = ServiceQuote::query()
                ->forOrganization($organizationId)
                ->where('service_order_id', $order->id)
                ->latest('revision')
                ->value('id');

            if ((int) $latestQuoteId !== (int) $quote->id) {
                throw new DomainException(
                    'No puede decidirse sobre una revisión superada.'
                );
            }

            if ($quote->decision()->exists()) {
                throw new DomainException(
                    'El presupuesto ya posee una decisión registrada.'
                );
            }

            $selectedOptionId = $normalized['service_quote_option_id'];

            if ($data->decision === ServiceQuoteDecisionType::Approved) {
                $belongs = $quote->options->contains(
                    fn (ServiceQuoteOption $option): bool =>
                        $option->id === $selectedOptionId
                );

                if (! $belongs) {
                    throw new DomainException(
                        'La alternativa aprobada no pertenece al presupuesto.'
                    );
                }
            } elseif ($selectedOptionId !== null) {
                throw new DomainException(
                    'Un rechazo no debe seleccionar una alternativa.'
                );
            }

            $decidedAt = CarbonImmutable::now();
            $decision = ServiceQuoteDecision::query()->create([
                'organization_id' => $organizationId,
                'service_quote_id' => $quote->id,
                'service_quote_option_id' => $selectedOptionId,
                'decision' => $data->decision,
                'customer_name' => $normalized['customer_name'],
                'customer_reference' =>
                    $normalized['customer_reference'],
                'channel' => $normalized['channel'],
                'reason' => $normalized['reason'],
                'recorded_by_user_id' => $actor->id,
                'decided_at' => $decidedAt,
                'idempotency_key' => $normalized['idempotency_key'],
                'fingerprint' => $normalized['fingerprint'],
            ]);

            $approved = $data->decision
                === ServiceQuoteDecisionType::Approved;
            $target = $approved
                ? ServiceOrderStatus::InProgress
                : ServiceOrderStatus::Diagnosing;
            $reason = $approved
                ? 'El cliente aprobó una alternativa del presupuesto.'
                : 'El cliente rechazó el presupuesto; la orden vuelve a diagnóstico.';

            $this->transition(
                $order,
                $target,
                $actor,
                $reason,
                $decidedAt
            );

            return $decision->load('selectedOption');
        }, 3);
    }

    /** @return array<string, mixed> */
    private function normalizeDiagnostic(
        ServiceDiagnosticData $data
    ): array {
        if ($data->findings === []) {
            throw new DomainException(
                'El diagnóstico requiere al menos un hallazgo técnico.'
            );
        }

        $findings = [];

        foreach ($data->findings as $finding) {
            if (! $finding instanceof ServiceDiagnosticFindingData) {
                throw new DomainException(
                    'Los hallazgos del diagnóstico no son válidos.'
                );
            }

            $findings[] = [
                'severity' => $finding->severity->value,
                'category' => $this->required($finding->category, 'La categoría'),
                'description' => $this->required(
                    $finding->description,
                    'El hallazgo'
                ),
                'evidence_notes' => $this->optional($finding->evidenceNotes),
            ];
        }

        $normalized = [
            'service_order_id' => $data->serviceOrderId,
            'summary' => $this->required($data->summary, 'El resumen'),
            'recommendation' => $this->required(
                $data->recommendation,
                'La recomendación'
            ),
            'data_risk_notes' => $this->optional($data->dataRiskNotes),
            'findings' => $findings,
            'idempotency_key' => $this->idempotencyKey(
                $data->idempotencyKey
            ),
        ];
        $normalized['fingerprint'] = $this->fingerprint($normalized);

        return $normalized;
    }

    /** @return array<string, mixed> */
    private function normalizeQuote(ServiceQuoteData $data): array
    {
        if ($data->options === []) {
            throw new DomainException(
                'El presupuesto requiere al menos una alternativa.'
            );
        }

        $currency = strtoupper(trim($data->currencyCode));

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new DomainException(
                'La moneda debe expresarse con un código ISO de tres letras.'
            );
        }

        $options = [];
        $recommendedCount = 0;

        foreach ($data->options as $option) {
            if (! $option instanceof ServiceQuoteOptionData) {
                throw new DomainException(
                    'Las alternativas del presupuesto no son válidas.'
                );
            }

            if ($option->lines === []) {
                throw new DomainException(
                    'Cada alternativa debe contener al menos una línea.'
                );
            }

            $lines = [];
            $totalMinor = 0;

            foreach ($option->lines as $line) {
                if (! $line instanceof ServiceQuoteLineData) {
                    throw new DomainException(
                        'Las líneas del presupuesto no son válidas.'
                    );
                }

                $quantity = $this->quantity($line->quantity);
                $lineTotal = $this->lineTotalMinor(
                    $quantity,
                    $line->unitPriceMinor
                );

                if ($totalMinor > PHP_INT_MAX - $lineTotal) {
                    throw new DomainException(
                        'El total de la alternativa supera el importe admitido.'
                    );
                }

                $totalMinor += $lineTotal;
                $lines[] = [
                    'line_type' => $line->type->value,
                    'description' => $this->required(
                        $line->description,
                        'La descripción de la línea'
                    ),
                    'quantity' => $quantity,
                    'unit_price_minor' => $line->unitPriceMinor,
                    'line_total_minor' => $lineTotal,
                ];
            }

            if ($option->recommended) {
                $recommendedCount++;
            }

            $options[] = [
                'label' => $this->required(
                    $option->label,
                    'El nombre de la alternativa'
                ),
                'description' => $this->optional($option->description),
                'recommended' => $option->recommended,
                'total_minor' => $totalMinor,
                'lines' => $lines,
            ];
        }

        if ($recommendedCount > 1) {
            throw new DomainException(
                'Sólo una alternativa puede marcarse como recomendada.'
            );
        }

        $normalized = [
            'service_order_id' => $data->serviceOrderId,
            'currency_code' => $currency,
            'valid_until' => $data->validUntil?->toIso8601String(),
            'terms' => $this->optional($data->terms),
            'options' => $options,
            'idempotency_key' => $this->idempotencyKey(
                $data->idempotencyKey
            ),
        ];
        $normalized['fingerprint'] = $this->fingerprint($normalized);

        return $normalized;
    }

    /** @return array<string, mixed> */
    private function normalizeDecision(
        ServiceQuoteDecisionData $data
    ): array {
        if (
            $data->decision === ServiceQuoteDecisionType::Approved
            && $data->serviceQuoteOptionId === null
        ) {
            throw new DomainException(
                'La aprobación debe identificar la alternativa elegida.'
            );
        }

        $normalized = [
            'service_quote_id' => $data->serviceQuoteId,
            'service_quote_option_id' => $data->serviceQuoteOptionId,
            'decision' => $data->decision->value,
            'customer_name' => $this->required(
                $data->customerName,
                'El nombre del cliente'
            ),
            'customer_reference' =>
                $this->optional($data->customerReference),
            'channel' => $this->required(
                $data->channel,
                'El canal de la decisión'
            ),
            'reason' => $this->optional($data->reason),
            'idempotency_key' => $this->idempotencyKey(
                $data->idempotencyKey
            ),
        ];

        if (
            $data->decision === ServiceQuoteDecisionType::Rejected
            && $normalized['reason'] === null
        ) {
            throw new DomainException(
                'El rechazo debe registrar el motivo informado.'
            );
        }

        $normalized['fingerprint'] = $this->fingerprint($normalized);

        return $normalized;
    }

    private function organizationId(User $actor): int
    {
        $organizationId = (int) $actor->current_organization_id;

        if ($organizationId <= 0) {
            throw new DomainException(
                'El usuario no posee una organización activa.'
            );
        }

        return $organizationId;
    }

    private function guardActor(
        int $organizationId,
        User $actor,
        string $action
    ): void {
        if (! $actor->exists || $actor->trashed()) {
            throw new DomainException(
                'El usuario responsable no está activo.'
            );
        }

        $membership = OrganizationMembership::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $actor->id)
            ->where('active', true)
            ->lockForUpdate()
            ->first();

        $allowed = match ($action) {
            'diagnostic' =>
                $membership?->role->canRecordServiceDiagnostics(),
            'quote' => $membership?->role->canIssueServiceQuotes(),
            'decision' =>
                $membership?->role->canRecordServiceQuoteDecisions(),
            default => false,
        };

        if (! $allowed) {
            throw new DomainException(
                'El rol del usuario no puede registrar esta operación técnica.'
            );
        }
    }

    private function lockedOrder(
        int $organizationId,
        int $orderId
    ): ServiceOrder {
        $order = ServiceOrder::query()
            ->forOrganization($organizationId)
            ->whereKey($orderId)
            ->lockForUpdate()
            ->first();

        if (! $order) {
            throw new DomainException(
                'La orden no pertenece a la organización activa.'
            );
        }

        return $order;
    }

    private function transition(
        ServiceOrder $order,
        ServiceOrderStatus $target,
        User $actor,
        string $reason,
        CarbonImmutable $changedAt
    ): void {
        $from = $order->status;

        if (! $order->allowsTransitionTo($target)) {
            throw new DomainException(
                'La transición solicitada no es válida para la orden.'
            );
        }

        ServiceOrderStatusHistory::query()->create([
            'organization_id' => $order->organization_id,
            'service_order_id' => $order->id,
            'from_status' => $from,
            'to_status' => $target,
            'changed_by_user_id' => $actor->id,
            'reason' => $reason,
            'changed_at' => $changedAt,
        ]);

        $order->status = $target;
        $order->save();
    }

    private function quantity(string $value): string
    {
        $value = trim($value);

        if (preg_match('/^\d+(?:\.\d+)?$/', $value) !== 1) {
            throw new DomainException(
                'La cantidad debe expresarse como decimal positivo con punto.'
            );
        }

        try {
            $quantity = BigDecimal::of($value)->toScale(
                6,
                RoundingMode::Unnecessary
            );

            if (! $quantity->isPositive()) {
                throw new DomainException(
                    'La cantidad presupuestada debe ser mayor que cero.'
                );
            }

            return (string) $quantity;
        } catch (DomainException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new DomainException(
                'La cantidad presupuestada supera la precisión admitida.',
                previous: $exception
            );
        }
    }

    private function lineTotalMinor(
        string $quantity,
        int $unitPriceMinor
    ): int {
        if ($unitPriceMinor < 0) {
            throw new DomainException(
                'El precio unitario no puede ser negativo.'
            );
        }

        try {
            $total = BigDecimal::of($quantity)
                ->multipliedBy($unitPriceMinor)
                ->toScale(0, RoundingMode::Unnecessary)
                ->toBigInteger();

            if ($total->isGreaterThan(BigInteger::of(PHP_INT_MAX))) {
                throw new DomainException(
                    'El total de la línea supera el importe admitido.'
                );
            }

            return (int) (string) $total;
        } catch (DomainException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new DomainException(
                'La cantidad y el precio producen una fracción de centavo.',
                previous: $exception
            );
        }
    }

    private function required(string $value, string $label): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new DomainException($label.' es obligatorio.');
        }

        return $value;
    }

    private function optional(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }

    private function idempotencyKey(string $value): string
    {
        $value = $this->required($value, 'La clave de idempotencia');

        if (mb_strlen($value) > 100) {
            throw new DomainException(
                'La clave de idempotencia supera los 100 caracteres.'
            );
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function fingerprint(array $payload): string
    {
        try {
            return hash('sha256', json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
            ));
        } catch (JsonException $exception) {
            throw new DomainException(
                'No se pudo consolidar la operación técnica.',
                previous: $exception
            );
        }
    }

    private function guardFingerprint(
        string $stored,
        string $expected,
        string $operation
    ): void {
        if (! hash_equals($stored, $expected)) {
            throw new DomainException(
                "La clave de idempotencia del {$operation} ya fue utilizada con otros datos."
            );
        }
    }
}
