<?php

namespace App\Domain\Service;

use App\Enums\ServiceCustodyEventType;
use App\Enums\ServiceOrderStatus;
use App\Enums\ServiceQuoteDecisionType;
use App\Enums\ServiceWorkCustodyDirection;
use App\Enums\ServiceWorkExecutionMode;
use App\Enums\ServiceWorkOutcome;
use App\Enums\ServiceWorkStatus;
use App\Models\BusinessParty;
use App\Models\OrganizationMembership;
use App\Models\ServiceCustodyEvent;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderStatusHistory;
use App\Models\ServiceQuoteDecision;
use App\Models\ServiceQuoteOption;
use App\Models\ServiceWorkCustodyLink;
use App\Models\ServiceWorkItem;
use App\Models\ServiceWorkReport;
use App\Models\ServiceWorkStatusHistory;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use JsonException;

final class ServiceWorkManager
{
    public function plan(
        ServiceWorkItemData $data,
        User $actor
    ): ServiceWorkItem {
        $normalized = $this->normalizeWorkItem($data);

        return DB::transaction(function () use (
            $data,
            $actor,
            $normalized
        ): ServiceWorkItem {
            $organizationId = $this->organizationId($actor);
            $this->guardActor($organizationId, $actor, 'plan');

            $existing = ServiceWorkItem::query()
                ->forOrganization($organizationId)
                ->where('idempotency_key', $normalized['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->guardFingerprint(
                    $existing->fingerprint,
                    $normalized['fingerprint'],
                    'trabajo'
                );

                return $existing->load('statusHistory');
            }

            $order = $this->lockedOrder(
                $organizationId,
                $data->serviceOrderId
            );

            if ($order->status !== ServiceOrderStatus::InProgress) {
                throw new DomainException(
                    'La orden debe tener un presupuesto aprobado para planificar trabajos.'
                );
            }

            $option = ServiceQuoteOption::query()
                ->forOrganization($organizationId)
                ->whereKey($data->serviceQuoteOptionId)
                ->lockForUpdate()
                ->first();

            if (! $option || ! $this->isApprovedOption(
                $organizationId,
                $order,
                $option
            )) {
                throw new DomainException(
                    'El trabajo debe pertenecer a la alternativa aprobada de la orden.'
                );
            }

            $this->guardAssignment(
                $organizationId,
                $data->executionMode,
                $data->providerBusinessPartyId,
                $data->assignedUserId
            );

            $plannedAt = CarbonImmutable::now();
            $sequence = ((int) ServiceWorkItem::query()
                ->forOrganization($organizationId)
                ->where('service_order_id', $order->id)
                ->max('sequence')) + 1;

            $item = ServiceWorkItem::query()->create([
                'organization_id' => $organizationId,
                'service_order_id' => $order->id,
                'service_quote_option_id' => $option->id,
                'sequence' => $sequence,
                'title' => $normalized['title'],
                'description' => $normalized['description'],
                'execution_mode' => $data->executionMode,
                'provider_business_party_id' =>
                    $data->providerBusinessPartyId,
                'assigned_user_id' => $data->assignedUserId,
                'status' => ServiceWorkStatus::Planned,
                'created_by_user_id' => $actor->id,
                'planned_at' => $plannedAt,
                'idempotency_key' => $normalized['idempotency_key'],
                'fingerprint' => $normalized['fingerprint'],
            ]);

            $this->appendWorkHistory(
                $item,
                null,
                ServiceWorkStatus::Planned,
                $actor,
                'Trabajo incorporado al alcance aprobado.',
                $plannedAt,
                $this->derivedKey(
                    $normalized['idempotency_key'],
                    'planned'
                )
            );

            return $item->load('statusHistory');
        }, 3);
    }

    public function startInternal(
        int $workItemId,
        string $idempotencyKey,
        User $actor
    ): ServiceWorkItem {
        $key = $this->idempotencyKey($idempotencyKey);

        return DB::transaction(function () use (
            $workItemId,
            $key,
            $actor
        ): ServiceWorkItem {
            $organizationId = $this->organizationId($actor);
            $this->guardActor($organizationId, $actor, 'execute');
            $item = $this->lockedWorkItem($organizationId, $workItemId);
            $expected = $this->historyFingerprint(
                $item,
                ServiceWorkStatus::Planned,
                ServiceWorkStatus::InProgress,
                $key
            );
            $existing = ServiceWorkStatusHistory::query()
                ->forOrganization($organizationId)
                ->where('idempotency_key', $key)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->guardFingerprint(
                    $existing->fingerprint,
                    $expected,
                    'inicio del trabajo'
                );

                return $item->fresh()->load('statusHistory');
            }

            if (
                $item->execution_mode
                    !== ServiceWorkExecutionMode::Internal
                || $item->status !== ServiceWorkStatus::Planned
            ) {
                throw new DomainException(
                    'Sólo un trabajo interno planificado puede iniciarse directamente.'
                );
            }

            $this->transitionWork(
                $item,
                ServiceWorkStatus::InProgress,
                $actor,
                'Comenzó la ejecución interna.',
                CarbonImmutable::now(),
                $key,
                $expected
            );

            return $item->fresh()->load('statusHistory');
        }, 3);
    }

    public function dispatchExternal(
        ServiceWorkCustodyData $data,
        User $actor
    ): ServiceWorkCustodyLink {
        $normalized = $this->normalizeCustody(
            $data,
            ServiceWorkCustodyDirection::Dispatch
        );

        return DB::transaction(function () use (
            $data,
            $actor,
            $normalized
        ): ServiceWorkCustodyLink {
            $organizationId = $this->organizationId($actor);
            $this->guardActor($organizationId, $actor, 'custody');

            $existing = $this->existingCustodyLink(
                $organizationId,
                $normalized
            );

            if ($existing) {
                return $existing;
            }

            $item = $this->lockedWorkItem(
                $organizationId,
                $data->serviceWorkItemId
            );
            $order = $this->lockedOrder(
                $organizationId,
                (int) $item->service_order_id
            );

            if (
                $item->execution_mode
                    !== ServiceWorkExecutionMode::External
                || $item->status !== ServiceWorkStatus::Planned
                || $order->status !== ServiceOrderStatus::InProgress
            ) {
                throw new DomainException(
                    'El trabajo externo no puede derivarse en su estado actual.'
                );
            }

            $this->guardOrganizationCustody($order);
            $provider = $item->provider()->lockForUpdate()->first();

            if (! $provider) {
                throw new DomainException(
                    'El trabajo externo no posee un especialista válido.'
                );
            }

            $organization = DB::table('organizations')
                ->where('id', $organizationId)
                ->lockForUpdate()
                ->first(['name', 'slug']);
            $occurredAt = CarbonImmutable::now();
            $event = ServiceCustodyEvent::query()->create([
                'organization_id' => $organizationId,
                'service_order_id' => $order->id,
                'event_type' => ServiceCustodyEventType::Transferred,
                'from_holder_type' => 'organization',
                'from_holder_reference' => (string) $organization->slug,
                'from_holder_name' => (string) $organization->name,
                'to_holder_type' => 'external_provider',
                'to_holder_reference' => 'business-party:'.$provider->id,
                'to_holder_name' => $provider->name,
                'location_id' => null,
                'condition_notes' => $normalized['condition_notes'],
                'accessories_snapshot' =>
                    $normalized['accessories_snapshot'],
                'recorded_by_user_id' => $actor->id,
                'occurred_at' => $occurredAt,
            ]);
            $link = ServiceWorkCustodyLink::query()->create([
                'organization_id' => $organizationId,
                'service_work_item_id' => $item->id,
                'service_custody_event_id' => $event->id,
                'direction' => ServiceWorkCustodyDirection::Dispatch,
                'idempotency_key' => $normalized['idempotency_key'],
                'fingerprint' => $normalized['fingerprint'],
            ]);

            $this->transitionWork(
                $item,
                ServiceWorkStatus::WithProvider,
                $actor,
                'El activo fue entregado al especialista externo.',
                $occurredAt,
                $this->derivedKey(
                    $normalized['idempotency_key'],
                    'status'
                )
            );
            $this->transitionOrder(
                $order,
                ServiceOrderStatus::WithExternalProvider,
                $actor,
                'Custodia transferida al especialista '.$provider->name.'.',
                $occurredAt
            );

            return $link->load('custodyEvent');
        }, 3);
    }

    public function returnExternal(
        ServiceWorkCustodyData $data,
        User $actor
    ): ServiceWorkCustodyLink {
        $normalized = $this->normalizeCustody(
            $data,
            ServiceWorkCustodyDirection::Return
        );

        return DB::transaction(function () use (
            $data,
            $actor,
            $normalized
        ): ServiceWorkCustodyLink {
            $organizationId = $this->organizationId($actor);
            $this->guardActor($organizationId, $actor, 'custody');

            $existing = $this->existingCustodyLink(
                $organizationId,
                $normalized
            );

            if ($existing) {
                return $existing;
            }

            $item = $this->lockedWorkItem(
                $organizationId,
                $data->serviceWorkItemId
            );
            $order = $this->lockedOrder(
                $organizationId,
                (int) $item->service_order_id
            );

            if (
                $item->execution_mode
                    !== ServiceWorkExecutionMode::External
                || $item->status !== ServiceWorkStatus::WithProvider
                || $order->status
                    !== ServiceOrderStatus::WithExternalProvider
            ) {
                throw new DomainException(
                    'El activo no figura bajo custodia del especialista.'
                );
            }

            $dispatch = $item->custodyLinks()
                ->where(
                    'direction',
                    ServiceWorkCustodyDirection::Dispatch->value
                )
                ->with('custodyEvent')
                ->latest('id')
                ->lockForUpdate()
                ->first();
            $provider = $item->provider()->lockForUpdate()->first();

            if (! $dispatch || ! $provider) {
                throw new DomainException(
                    'No existe una entrega externa que pueda retornarse.'
                );
            }

            $organization = DB::table('organizations')
                ->where('id', $organizationId)
                ->lockForUpdate()
                ->first(['name', 'slug']);
            $occurredAt = CarbonImmutable::now();
            $event = ServiceCustodyEvent::query()->create([
                'organization_id' => $organizationId,
                'service_order_id' => $order->id,
                'event_type' => ServiceCustodyEventType::Returned,
                'from_holder_type' => 'external_provider',
                'from_holder_reference' => 'business-party:'.$provider->id,
                'from_holder_name' => $provider->name,
                'to_holder_type' => 'organization',
                'to_holder_reference' => (string) $organization->slug,
                'to_holder_name' => (string) $organization->name,
                'location_id' => $order->intake_location_id,
                'condition_notes' => $normalized['condition_notes'],
                'accessories_snapshot' =>
                    $normalized['accessories_snapshot'],
                'recorded_by_user_id' => $actor->id,
                'occurred_at' => $occurredAt,
            ]);
            $link = ServiceWorkCustodyLink::query()->create([
                'organization_id' => $organizationId,
                'service_work_item_id' => $item->id,
                'service_custody_event_id' => $event->id,
                'direction' => ServiceWorkCustodyDirection::Return,
                'idempotency_key' => $normalized['idempotency_key'],
                'fingerprint' => $normalized['fingerprint'],
            ]);

            $this->transitionWork(
                $item,
                ServiceWorkStatus::InProgress,
                $actor,
                'El activo retornó del especialista externo.',
                $occurredAt,
                $this->derivedKey(
                    $normalized['idempotency_key'],
                    'status'
                )
            );
            $this->transitionOrder(
                $order,
                ServiceOrderStatus::InProgress,
                $actor,
                'El activo retornó del especialista '.$provider->name.'.',
                $occurredAt
            );

            return $link->load('custodyEvent');
        }, 3);
    }

    public function report(
        ServiceWorkReportData $data,
        User $actor
    ): ServiceWorkReport {
        $normalized = $this->normalizeReport($data);

        return DB::transaction(function () use (
            $data,
            $actor,
            $normalized
        ): ServiceWorkReport {
            $organizationId = $this->organizationId($actor);
            $this->guardActor($organizationId, $actor, 'execute');

            $existing = ServiceWorkReport::query()
                ->forOrganization($organizationId)
                ->where('idempotency_key', $normalized['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->guardFingerprint(
                    $existing->fingerprint,
                    $normalized['fingerprint'],
                    'resultado técnico'
                );

                return $existing;
            }

            $item = $this->lockedWorkItem(
                $organizationId,
                $data->serviceWorkItemId
            );
            $order = $this->lockedOrder(
                $organizationId,
                (int) $item->service_order_id
            );

            if (
                $item->status !== ServiceWorkStatus::InProgress
                || $order->status !== ServiceOrderStatus::InProgress
            ) {
                throw new DomainException(
                    'El trabajo no está disponible para registrar resultado.'
                );
            }

            if (
                $data->outcome === ServiceWorkOutcome::Unresolved
                && $order->workItems()
                    ->where('id', '<>', $item->id)
                    ->whereNotIn('status', [
                        ServiceWorkStatus::Completed->value,
                        ServiceWorkStatus::Unresolved->value,
                    ])
                    ->exists()
            ) {
                throw new DomainException(
                    'No puede reabrirse el diagnóstico mientras existan otros trabajos activos.'
                );
            }

            $recordedAt = CarbonImmutable::now();
            $report = ServiceWorkReport::query()->create([
                'organization_id' => $organizationId,
                'service_work_item_id' => $item->id,
                'outcome' => $data->outcome,
                'result_summary' => $normalized['result_summary'],
                'work_performed' => $normalized['work_performed'],
                'unresolved_reason' => $normalized['unresolved_reason'],
                'warranty_days' => $normalized['warranty_days'],
                'warranty_terms' => $normalized['warranty_terms'],
                'recorded_by_user_id' => $actor->id,
                'recorded_at' => $recordedAt,
                'idempotency_key' => $normalized['idempotency_key'],
                'fingerprint' => $normalized['fingerprint'],
            ]);
            $target = $data->outcome === ServiceWorkOutcome::Completed
                ? ServiceWorkStatus::Completed
                : ServiceWorkStatus::Unresolved;

            $this->transitionWork(
                $item,
                $target,
                $actor,
                $data->outcome === ServiceWorkOutcome::Completed
                    ? 'Trabajo completado con resultado técnico registrado.'
                    : 'Trabajo finalizado sin solución.',
                $recordedAt,
                $this->derivedKey(
                    $normalized['idempotency_key'],
                    'status'
                )
            );

            if ($data->outcome === ServiceWorkOutcome::Unresolved) {
                $this->transitionOrder(
                    $order,
                    ServiceOrderStatus::Diagnosing,
                    $actor,
                    'El trabajo no tuvo solución; se requiere nuevo diagnóstico.',
                    $recordedAt
                );
            } elseif (! $order->workItems()
                ->whereNotIn('status', [
                    ServiceWorkStatus::Completed->value,
                    ServiceWorkStatus::Unresolved->value,
                ])
                ->exists()) {
                $this->transitionOrder(
                    $order,
                    ServiceOrderStatus::QualityControl,
                    $actor,
                    'Todos los trabajos aprobados fueron completados.',
                    $recordedAt
                );
            }

            return $report;
        }, 3);
    }

    /** @return array<string, mixed> */
    private function normalizeWorkItem(ServiceWorkItemData $data): array
    {
        $normalized = [
            'service_order_id' => $data->serviceOrderId,
            'service_quote_option_id' => $data->serviceQuoteOptionId,
            'title' => $this->required($data->title, 'El título'),
            'description' => $this->required(
                $data->description,
                'La descripción'
            ),
            'execution_mode' => $data->executionMode->value,
            'provider_business_party_id' =>
                $data->providerBusinessPartyId,
            'assigned_user_id' => $data->assignedUserId,
            'idempotency_key' => $this->idempotencyKey(
                $data->idempotencyKey
            ),
        ];
        $normalized['fingerprint'] = $this->fingerprint($normalized);

        return $normalized;
    }

    /** @return array<string, mixed> */
    private function normalizeCustody(
        ServiceWorkCustodyData $data,
        ServiceWorkCustodyDirection $direction
    ): array {
        $normalized = [
            'service_work_item_id' => $data->serviceWorkItemId,
            'direction' => $direction->value,
            'condition_notes' => $this->required(
                $data->conditionNotes,
                'La condición del activo'
            ),
            'accessories_snapshot' => $this->required(
                $data->accessoriesSnapshot,
                'Los accesorios transferidos'
            ),
            'idempotency_key' => $this->idempotencyKey(
                $data->idempotencyKey
            ),
        ];
        $normalized['fingerprint'] = $this->fingerprint($normalized);

        return $normalized;
    }

    /** @return array<string, mixed> */
    private function normalizeReport(ServiceWorkReportData $data): array
    {
        $unresolved = $data->outcome === ServiceWorkOutcome::Unresolved;
        $unresolvedReason = $this->optional($data->unresolvedReason);
        $warrantyTerms = $this->optional($data->warrantyTerms);

        if ($unresolved && $unresolvedReason === null) {
            throw new DomainException(
                'Un trabajo sin solución debe registrar el motivo.'
            );
        }

        if (! $unresolved && $unresolvedReason !== null) {
            throw new DomainException(
                'Un trabajo completado no puede declarar motivo sin solución.'
            );
        }

        if ($data->warrantyDays !== null && $data->warrantyDays < 0) {
            throw new DomainException(
                'Los días de garantía no pueden ser negativos.'
            );
        }

        if (
            $unresolved
            && ($data->warrantyDays !== null || $warrantyTerms !== null)
        ) {
            throw new DomainException(
                'Un trabajo sin solución no puede otorgar garantía.'
            );
        }

        if (
            $data->warrantyDays !== null
            && $data->warrantyDays > 0
            && $warrantyTerms === null
        ) {
            throw new DomainException(
                'La garantía debe detallar sus condiciones.'
            );
        }

        $normalized = [
            'service_work_item_id' => $data->serviceWorkItemId,
            'outcome' => $data->outcome->value,
            'result_summary' => $this->required(
                $data->resultSummary,
                'El resumen del resultado'
            ),
            'work_performed' => $this->required(
                $data->workPerformed,
                'El trabajo realizado'
            ),
            'unresolved_reason' => $unresolvedReason,
            'warranty_days' => $data->warrantyDays,
            'warranty_terms' => $warrantyTerms,
            'idempotency_key' => $this->idempotencyKey(
                $data->idempotencyKey
            ),
        ];
        $normalized['fingerprint'] = $this->fingerprint($normalized);

        return $normalized;
    }

    private function isApprovedOption(
        int $organizationId,
        ServiceOrder $order,
        ServiceQuoteOption $option
    ): bool {
        return ServiceQuoteDecision::query()
            ->forOrganization($organizationId)
            ->where('service_quote_option_id', $option->id)
            ->where('decision', ServiceQuoteDecisionType::Approved->value)
            ->whereHas(
                'quote',
                fn ($query) => $query->where(
                    'service_order_id',
                    $order->id
                )
            )
            ->exists();
    }

    private function guardAssignment(
        int $organizationId,
        ServiceWorkExecutionMode $mode,
        ?int $providerId,
        ?int $assignedUserId
    ): void {
        if ($mode === ServiceWorkExecutionMode::External) {
            if ($providerId === null || $assignedUserId !== null) {
                throw new DomainException(
                    'El trabajo externo requiere especialista y no un usuario interno.'
                );
            }

            if (! BusinessParty::query()
                ->forOrganization($organizationId)
                ->whereKey($providerId)
                ->exists()) {
                throw new DomainException(
                    'El especialista no pertenece a la organización activa.'
                );
            }

            return;
        }

        if ($assignedUserId === null || $providerId !== null) {
            throw new DomainException(
                'El trabajo interno requiere un usuario responsable.'
            );
        }

        if (! OrganizationMembership::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $assignedUserId)
            ->where('active', true)
            ->exists()) {
            throw new DomainException(
                'El responsable interno no es un miembro activo.'
            );
        }
    }

    private function guardOrganizationCustody(ServiceOrder $order): void
    {
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
    }

    /** @param array<string, mixed> $normalized */
    private function existingCustodyLink(
        int $organizationId,
        array $normalized
    ): ?ServiceWorkCustodyLink {
        $existing = ServiceWorkCustodyLink::query()
            ->forOrganization($organizationId)
            ->where('idempotency_key', $normalized['idempotency_key'])
            ->with('custodyEvent')
            ->lockForUpdate()
            ->first();

        if ($existing) {
            $this->guardFingerprint(
                $existing->fingerprint,
                $normalized['fingerprint'],
                'transferencia de custodia'
            );
        }

        return $existing;
    }

    private function transitionWork(
        ServiceWorkItem $item,
        ServiceWorkStatus $target,
        User $actor,
        string $reason,
        CarbonImmutable $changedAt,
        string $idempotencyKey,
        ?string $knownFingerprint = null
    ): void {
        $from = $item->status;

        if (! $item->allowsTransitionTo($target)) {
            throw new DomainException(
                'La transición del trabajo no es válida.'
            );
        }

        $this->appendWorkHistory(
            $item,
            $from,
            $target,
            $actor,
            $reason,
            $changedAt,
            $idempotencyKey,
            $knownFingerprint
        );
        $item->status = $target;
        $item->save();
    }

    private function appendWorkHistory(
        ServiceWorkItem $item,
        ?ServiceWorkStatus $from,
        ServiceWorkStatus $target,
        User $actor,
        string $reason,
        CarbonImmutable $changedAt,
        string $idempotencyKey,
        ?string $knownFingerprint = null
    ): void {
        $fingerprint = $knownFingerprint ?? $this->historyFingerprint(
            $item,
            $from,
            $target,
            $idempotencyKey
        );

        ServiceWorkStatusHistory::query()->create([
            'organization_id' => $item->organization_id,
            'service_work_item_id' => $item->id,
            'from_status' => $from,
            'to_status' => $target,
            'changed_by_user_id' => $actor->id,
            'reason' => $reason,
            'changed_at' => $changedAt,
            'idempotency_key' => $idempotencyKey,
            'fingerprint' => $fingerprint,
        ]);
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

    private function historyFingerprint(
        ServiceWorkItem $item,
        ?ServiceWorkStatus $from,
        ServiceWorkStatus $target,
        string $idempotencyKey
    ): string {
        return $this->fingerprint([
            'service_work_item_id' => $item->id,
            'from_status' => $from?->value,
            'to_status' => $target->value,
            'idempotency_key' => $idempotencyKey,
        ]);
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
                'El usuario no puede registrar esta operación de trabajo.'
            );
        }

        $membership = OrganizationMembership::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $actor->id)
            ->where('active', true)
            ->lockForUpdate()
            ->first();
        $allowed = match ($action) {
            'plan' => $membership?->role->canPlanServiceWork(),
            'execute' => $membership?->role->canExecuteServiceWork(),
            'custody' =>
                $membership?->role->canTransferServiceCustody(),
            default => false,
        };

        if (! $allowed) {
            throw new DomainException(
                'El usuario no puede registrar esta operación de trabajo.'
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

    private function lockedWorkItem(
        int $organizationId,
        int $itemId
    ): ServiceWorkItem {
        $item = ServiceWorkItem::query()
            ->forOrganization($organizationId)
            ->whereKey($itemId)
            ->lockForUpdate()
            ->first();

        if (! $item) {
            throw new DomainException(
                'El trabajo no pertenece a la organización activa.'
            );
        }

        return $item;
    }

    private function required(string $value, string $label): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new DomainException($label.' es obligatorio.');
        }

        return $value;
    }

    private function derivedKey(string $base, string $suffix): string
    {
        $candidate = $base.':'.$suffix;

        if (strlen($candidate) <= 100) {
            return $candidate;
        }

        return substr($base, 0, 58)
            .':'
            .substr(hash('sha256', $candidate), 0, 32)
            .':'
            .$suffix;
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
                'No se pudo consolidar la operación de trabajo.',
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
