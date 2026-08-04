<?php

namespace App\Domain\Service;

use App\Enums\ServiceCustodyEventType;
use App\Enums\ServiceOrderStatus;
use App\Enums\ServiceQualityOutcome;
use App\Enums\ServiceWorkOutcome;
use App\Enums\ServiceWorkStatus;
use App\Models\BusinessParty;
use App\Models\OrganizationMembership;
use App\Models\ServiceCustodyEvent;
use App\Models\ServiceDelivery;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderStatusHistory;
use App\Models\ServiceQualityInspection;
use App\Models\ServiceWarrantyGrant;
use App\Models\ServiceWorkReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use JsonException;

final class ServiceCompletionManager
{
    public function __construct(
        private readonly ServiceWarrantyClaimManager $warrantyClaimManager
    ) {}

    public function inspect(
        ServiceQualityInspectionData $data,
        User $actor
    ): ServiceQualityInspection {
        $normalized = $this->normalizeInspection($data);

        return DB::transaction(function () use (
            $data,
            $actor,
            $normalized
        ): ServiceQualityInspection {
            $organizationId = $this->organizationId($actor);
            $this->guardActor($organizationId, $actor, 'quality');

            $existing = ServiceQualityInspection::query()
                ->forOrganization($organizationId)
                ->where('idempotency_key', $normalized['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->guardFingerprint(
                    $existing->fingerprint,
                    $normalized['fingerprint'],
                    'control de calidad'
                );

                return $existing;
            }

            $order = $this->lockedOrder(
                $organizationId,
                $data->serviceOrderId
            );

            if ($order->status !== ServiceOrderStatus::QualityControl) {
                throw new DomainException(
                    'La orden no está disponible para control de calidad.'
                );
            }

            $this->guardOrganizationCustody($order);

            if (
                ! $order->workItems()->exists()
                || $order->workItems()
                    ->where('status', '<>', ServiceWorkStatus::Completed->value)
                    ->exists()
            ) {
                throw new DomainException(
                    'Todos los trabajos deben estar completados antes del control de calidad.'
                );
            }

            $inspectedAt = CarbonImmutable::now();
            $revision = ((int) ServiceQualityInspection::query()
                ->forOrganization($organizationId)
                ->where('service_order_id', $order->id)
                ->max('revision')) + 1;
            $inspection = ServiceQualityInspection::query()->create([
                'organization_id' => $organizationId,
                'service_order_id' => $order->id,
                'revision' => $revision,
                'outcome' => $normalized['outcome'],
                'check_count' => $normalized['check_count'],
                'failed_check_count' => $normalized['failed_check_count'],
                'checks' => $normalized['checks'],
                'condition_notes' => $normalized['condition_notes'],
                'accessories_snapshot' => $normalized['accessories_snapshot'],
                'rework_reason' => $normalized['rework_reason'],
                'notes' => $normalized['notes'],
                'inspected_by_user_id' => $actor->id,
                'inspected_at' => $inspectedAt,
                'idempotency_key' => $normalized['idempotency_key'],
                'fingerprint' => $normalized['fingerprint'],
            ]);
            $target = $normalized['outcome']
                === ServiceQualityOutcome::Approved->value
                    ? ServiceOrderStatus::ReadyForDelivery
                    : ServiceOrderStatus::InProgress;

            $this->transitionOrder(
                $order,
                $target,
                $actor,
                $target === ServiceOrderStatus::ReadyForDelivery
                    ? 'El control de calidad fue aprobado.'
                    : 'El control de calidad requiere retrabajo: '
                        .$normalized['rework_reason'],
                $inspectedAt
            );

            return $inspection;
        }, 3);
    }

    public function deliver(
        ServiceDeliveryData $data,
        User $actor
    ): ServiceDelivery {
        $normalized = $this->normalizeDelivery($data);

        return DB::transaction(function () use (
            $data,
            $actor,
            $normalized
        ): ServiceDelivery {
            $organizationId = $this->organizationId($actor);
            $this->guardActor($organizationId, $actor, 'delivery');

            $existing = ServiceDelivery::query()
                ->forOrganization($organizationId)
                ->where('idempotency_key', $normalized['idempotency_key'])
                ->with(['custodyEvent', 'warranties.workReport'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->guardFingerprint(
                    $existing->fingerprint,
                    $normalized['fingerprint'],
                    'entrega de la orden'
                );

                return $existing;
            }

            $order = $this->lockedOrder(
                $organizationId,
                $data->serviceOrderId
            );

            if ($order->status !== ServiceOrderStatus::ReadyForDelivery) {
                throw new DomainException(
                    'La orden no está lista para entregar.'
                );
            }

            $inspection = ServiceQualityInspection::query()
                ->forOrganization($organizationId)
                ->whereKey($data->serviceQualityInspectionId)
                ->lockForUpdate()
                ->first();
            $latestInspectionId = ServiceQualityInspection::query()
                ->forOrganization($organizationId)
                ->where('service_order_id', $order->id)
                ->max('id');

            if (
                ! $inspection
                || (int) $inspection->service_order_id !== $order->id
                || $inspection->outcome !== ServiceQualityOutcome::Approved
                || (int) $latestInspectionId !== $inspection->id
            ) {
                throw new DomainException(
                    'La entrega requiere el último control de calidad aprobado.'
                );
            }

            $latestCustody = $this->guardOrganizationCustody($order);
            $recipient = $this->recipient(
                $organizationId,
                $data->recipientBusinessPartyId
            );
            $deliveredAt = $data->deliveredAt ?? CarbonImmutable::now();

            if ($deliveredAt->isAfter(CarbonImmutable::now()->addMinutes(5))) {
                throw new DomainException(
                    'La entrega no puede registrarse en una fecha futura.'
                );
            }

            if ($deliveredAt->isBefore($inspection->inspected_at)) {
                throw new DomainException(
                    'La entrega no puede ser anterior al control de calidad.'
                );
            }

            $custody = ServiceCustodyEvent::query()->create([
                'organization_id' => $organizationId,
                'service_order_id' => $order->id,
                'event_type' => ServiceCustodyEventType::Delivered,
                'from_holder_type' => 'organization',
                'from_holder_reference' => $latestCustody->to_holder_reference,
                'from_holder_name' => $latestCustody->to_holder_name,
                'to_holder_type' => $recipient
                    ? 'business_party'
                    : 'authorized_recipient',
                'to_holder_reference' => $recipient
                    ? 'business-party:'.$recipient->id
                    : $normalized['recipient_document'],
                'to_holder_name' => $normalized['recipient_name'],
                'location_id' => null,
                'condition_notes' => $normalized['condition_notes'],
                'accessories_snapshot' => $normalized['accessories_snapshot'],
                'recorded_by_user_id' => $actor->id,
                'occurred_at' => $deliveredAt,
            ]);
            $delivery = ServiceDelivery::query()->create([
                'organization_id' => $organizationId,
                'service_order_id' => $order->id,
                'service_quality_inspection_id' => $inspection->id,
                'service_custody_event_id' => $custody->id,
                'recipient_business_party_id' => $recipient?->id,
                'recipient_name' => $normalized['recipient_name'],
                'recipient_document' => $normalized['recipient_document'],
                'customer_conformity' => $normalized['customer_conformity'],
                'condition_notes' => $normalized['condition_notes'],
                'accessories_snapshot' => $normalized['accessories_snapshot'],
                'notes' => $normalized['notes'],
                'delivered_by_user_id' => $actor->id,
                'delivered_at' => $deliveredAt,
                'idempotency_key' => $normalized['idempotency_key'],
                'fingerprint' => $normalized['fingerprint'],
            ]);

            $this->grantWarranties(
                $organizationId,
                $order,
                $delivery,
                $deliveredAt
            );
            $this->warrantyClaimManager->closeAfterDelivery(
                $delivery,
                $actor
            );
            $this->transitionOrder(
                $order,
                ServiceOrderStatus::Delivered,
                $actor,
                'El activo fue entregado a '
                    .$normalized['recipient_name'].'.',
                $deliveredAt
            );

            return $delivery->load([
                'qualityInspection',
                'custodyEvent',
                'recipient',
                'warranties.workReport.workItem',
            ]);
        }, 3);
    }

    /** @return array<string, mixed> */
    private function normalizeInspection(
        ServiceQualityInspectionData $data
    ): array {
        if ($data->checks === []) {
            throw new DomainException(
                'El control de calidad requiere al menos una comprobación.'
            );
        }

        $checks = [];
        $codes = [];
        $failed = 0;

        foreach ($data->checks as $check) {
            if (! $check instanceof ServiceQualityCheckData) {
                throw new DomainException(
                    'Las comprobaciones de calidad no son válidas.'
                );
            }

            $code = strtolower(trim($check->code));

            if (! preg_match('/^[a-z0-9][a-z0-9._-]{0,49}$/', $code)) {
                throw new DomainException(
                    'El código de la comprobación no es válido.'
                );
            }

            if (isset($codes[$code])) {
                throw new DomainException(
                    'Una comprobación de calidad está repetida.'
                );
            }

            $codes[$code] = true;
            $notes = $this->optional($check->notes);
            $checks[] = [
                'code' => $code,
                'label' => $this->required(
                    $check->label,
                    'La descripción de la comprobación'
                ),
                'passed' => $check->passed,
                'notes' => $notes,
            ];

            if (! $check->passed) {
                $failed++;
            }
        }

        $reworkReason = $this->optional($data->reworkReason);

        if ($failed > 0 && $reworkReason === null) {
            throw new DomainException(
                'Un control rechazado debe indicar el retrabajo requerido.'
            );
        }

        if ($failed === 0 && $reworkReason !== null) {
            throw new DomainException(
                'Un control aprobado no puede declarar retrabajo.'
            );
        }

        $normalized = [
            'service_order_id' => $data->serviceOrderId,
            'outcome' => $failed === 0
                ? ServiceQualityOutcome::Approved->value
                : ServiceQualityOutcome::ReworkRequired->value,
            'check_count' => count($checks),
            'failed_check_count' => $failed,
            'checks' => $checks,
            'condition_notes' => $this->required(
                $data->conditionNotes,
                'La condición final del activo'
            ),
            'accessories_snapshot' => $this->required(
                $data->accessoriesSnapshot,
                'Los accesorios verificados'
            ),
            'rework_reason' => $reworkReason,
            'notes' => $this->optional($data->notes),
            'idempotency_key' => $this->idempotencyKey(
                $data->idempotencyKey
            ),
        ];
        $normalized['fingerprint'] = $this->fingerprint($normalized);

        return $normalized;
    }

    /** @return array<string, mixed> */
    private function normalizeDelivery(ServiceDeliveryData $data): array
    {
        $notes = $this->optional($data->notes);

        if (! $data->customerConformity && $notes === null) {
            throw new DomainException(
                'La entrega sin conformidad debe registrar observaciones.'
            );
        }

        $normalized = [
            'service_order_id' => $data->serviceOrderId,
            'service_quality_inspection_id' => $data->serviceQualityInspectionId,
            'recipient_business_party_id' => $data->recipientBusinessPartyId,
            'recipient_name' => $this->required(
                $data->recipientName,
                'El nombre del receptor'
            ),
            'recipient_document' => $this->optional(
                $data->recipientDocument
            ),
            'customer_conformity' => $data->customerConformity,
            'condition_notes' => $this->required(
                $data->conditionNotes,
                'La condición de entrega'
            ),
            'accessories_snapshot' => $this->required(
                $data->accessoriesSnapshot,
                'Los accesorios entregados'
            ),
            'notes' => $notes,
            'delivered_at' => $data->deliveredAt?->toIso8601String(),
            'idempotency_key' => $this->idempotencyKey(
                $data->idempotencyKey
            ),
        ];
        $normalized['fingerprint'] = $this->fingerprint($normalized);

        return $normalized;
    }

    private function grantWarranties(
        int $organizationId,
        ServiceOrder $order,
        ServiceDelivery $delivery,
        CarbonImmutable $startsAt
    ): void {
        $reports = ServiceWorkReport::query()
            ->forOrganization($organizationId)
            ->where('outcome', ServiceWorkOutcome::Completed->value)
            ->whereNotNull('warranty_days')
            ->where('warranty_days', '>', 0)
            ->whereHas(
                'workItem',
                fn ($query) => $query->where(
                    'service_order_id',
                    $order->id
                )
            )
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($reports as $report) {
            $days = (int) $report->warranty_days;
            $payload = [
                'service_delivery_id' => $delivery->id,
                'service_work_report_id' => $report->id,
                'warranty_days' => $days,
                'coverage_terms' => (string) $report->warranty_terms,
                'starts_at' => $startsAt->toIso8601String(),
                'expires_at' => $startsAt->addDays($days)->toIso8601String(),
            ];

            ServiceWarrantyGrant::query()->create([
                'organization_id' => $organizationId,
                ...$payload,
                'fingerprint' => $this->fingerprint($payload),
            ]);
        }
    }

    private function recipient(
        int $organizationId,
        ?int $recipientId
    ): ?BusinessParty {
        if ($recipientId === null) {
            return null;
        }

        $recipient = BusinessParty::query()
            ->forOrganization($organizationId)
            ->whereKey($recipientId)
            ->lockForUpdate()
            ->first();

        if (! $recipient) {
            throw new DomainException(
                'El receptor no pertenece a la organización activa.'
            );
        }

        return $recipient;
    }

    private function guardOrganizationCustody(
        ServiceOrder $order
    ): ServiceCustodyEvent {
        $latest = $order->custodyEvents()
            ->latest('occurred_at')
            ->latest('id')
            ->lockForUpdate()
            ->first();

        if (! $latest || $latest->to_holder_type !== 'organization') {
            throw new DomainException(
                'La organización no posee actualmente la custodia del activo.'
            );
        }

        return $latest;
    }

    private function transitionOrder(
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
                'El usuario no puede completar esta operación de servicio.'
            );
        }

        $membership = OrganizationMembership::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $actor->id)
            ->where('active', true)
            ->lockForUpdate()
            ->first();
        $allowed = match ($action) {
            'quality' => $membership?->role->canInspectServiceQuality(),
            'delivery' => $membership?->role->canDeliverServiceOrders(),
            default => false,
        };

        if (! $allowed) {
            throw new DomainException(
                'El usuario no puede completar esta operación de servicio.'
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

    /** @param array<string, mixed> $payload */
    private function fingerprint(array $payload): string
    {
        try {
            return hash('sha256', json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
            ));
        } catch (JsonException $exception) {
            throw new DomainException(
                'No se pudo consolidar la operación de cierre.',
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
                "La clave de idempotencia de {$operation} ya fue utilizada con otros datos."
            );
        }
    }
}
