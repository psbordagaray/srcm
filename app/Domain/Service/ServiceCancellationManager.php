<?php

namespace App\Domain\Service;

use App\Enums\ServiceCancellationFinancialOutcome;
use App\Enums\ServiceCustodyEventType;
use App\Enums\ServiceOrderStatus;
use App\Enums\ServiceWorkCustodyDirection;
use App\Enums\ServiceWorkExecutionMode;
use App\Enums\ServiceWorkStatus;
use App\Models\BusinessParty;
use App\Models\CommercePayment;
use App\Models\OrganizationMembership;
use App\Models\ServiceCancellationRequest;
use App\Models\ServiceCancellationResolution;
use App\Models\ServiceCancellationReturn;
use App\Models\ServiceCustodyEvent;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderStatusHistory;
use App\Models\ServicePartConsumption;
use App\Models\ServicePartPurchase;
use App\Models\ServicePartRequirement;
use App\Models\ServiceWorkCustodyLink;
use App\Models\ServiceWorkItem;
use App\Models\ServiceWorkStatusHistory;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use JsonException;

final class ServiceCancellationManager
{
    public function request(
        ServiceCancellationRequestData $data,
        User $actor
    ): ServiceCancellationRequest {
        $normalized = $this->normalizeRequest($data);

        return DB::transaction(function () use (
            $data,
            $actor,
            $normalized
        ): ServiceCancellationRequest {
            $organizationId = $this->organizationId($actor);
            $this->guardActor($organizationId, $actor, 'request');

            $existing = ServiceCancellationRequest::query()
                ->forOrganization($organizationId)
                ->where('idempotency_key', $normalized['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->guardFingerprint(
                    $existing->fingerprint,
                    $normalized['fingerprint'],
                    'solicitud de cancelación'
                );

                return $existing->load('resolution.returnRecord');
            }

            $order = $this->lockedOrder(
                $organizationId,
                $data->serviceOrderId
            );

            if (! $order->canRequestCancellation()) {
                throw new DomainException(
                    'La orden no admite una nueva solicitud de cancelación.'
                );
            }

            if ($order->cancellationRequest()->exists()) {
                throw new DomainException(
                    'La orden ya posee una solicitud de cancelación.'
                );
            }

            $requester = $this->businessParty(
                $organizationId,
                $data->requesterBusinessPartyId,
                'solicitante'
            );
            $snapshot = $this->exposureSnapshot($organizationId, $order);
            $requestedAt = CarbonImmutable::now();
            $request = ServiceCancellationRequest::query()->create([
                'organization_id' => $organizationId,
                'service_order_id' => $order->id,
                'reason' => $data->reason,
                'requester_business_party_id' => $requester?->id,
                'requester_name' => $normalized['requester_name'],
                'customer_reference' =>
                    $normalized['customer_reference'],
                'channel' => $normalized['channel'],
                'details' => $normalized['details'],
                'order_status_snapshot' => $order->status,
                'has_started_work' => $snapshot['has_started_work'],
                'has_part_purchases' => $snapshot['has_part_purchases'],
                'has_part_consumptions' =>
                    $snapshot['has_part_consumptions'],
                'has_external_custody' =>
                    $snapshot['has_external_custody'],
                'has_registered_payments' =>
                    $snapshot['has_registered_payments'],
                'exposure_snapshot' => $snapshot['details'],
                'requested_by_user_id' => $actor->id,
                'requested_at' => $requestedAt,
                'idempotency_key' => $normalized['idempotency_key'],
                'fingerprint' => $normalized['fingerprint'],
            ]);

            $this->transitionOrder(
                $order,
                ServiceOrderStatus::CancellationPending,
                $actor,
                'Se registró la revocación solicitada por '
                    .$normalized['requester_name'].'.',
                $requestedAt
            );
            $this->cancelLocalWork($order, $request, $actor, $requestedAt);

            return $request->load('resolution.returnRecord');
        }, 3);
    }

    public function recallExternal(
        ServiceWorkCustodyData $data,
        User $actor
    ): ServiceWorkCustodyLink {
        $normalized = $this->normalizeCustody($data);

        return DB::transaction(function () use (
            $data,
            $actor,
            $normalized
        ): ServiceWorkCustodyLink {
            $organizationId = $this->organizationId($actor);
            $this->guardActor($organizationId, $actor, 'custody');

            $existing = ServiceWorkCustodyLink::query()
                ->forOrganization($organizationId)
                ->where('idempotency_key', $normalized['idempotency_key'])
                ->where(
                    'direction',
                    ServiceWorkCustodyDirection::Return->value
                )
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->guardFingerprint(
                    $existing->fingerprint,
                    $normalized['fingerprint'],
                    'retorno por cancelación'
                );

                return $existing->load('custodyEvent');
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
                $order->status !== ServiceOrderStatus::CancellationPending
                || $item->execution_mode
                    !== ServiceWorkExecutionMode::External
                || $item->status !== ServiceWorkStatus::WithProvider
            ) {
                throw new DomainException(
                    'No existe una custodia externa pendiente por cancelación.'
                );
            }

            $request = $order->cancellationRequest()
                ->lockForUpdate()
                ->first();
            $provider = $item->provider()->lockForUpdate()->first();
            $latestCustody = $this->latestCustody($order);

            if (
                ! $request
                || ! $provider
                || ! $latestCustody
                || $latestCustody->to_holder_type !== 'external_provider'
                || $latestCustody->to_holder_reference
                    !== 'business-party:'.$provider->id
            ) {
                throw new DomainException(
                    'La cadena de custodia externa no coincide con el trabajo.'
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

            $statusKey = $this->derivedKey(
                $normalized['idempotency_key'],
                'cancelled'
            );
            $this->transitionWork(
                $item,
                ServiceWorkStatus::Cancelled,
                $actor,
                'El trabajo externo se detuvo por la cancelación solicitada.',
                $occurredAt,
                $statusKey,
                $this->workFingerprint(
                    $item,
                    ServiceWorkStatus::Cancelled,
                    $statusKey
                )
            );

            return $link->load('custodyEvent');
        }, 3);
    }

    public function resolve(
        ServiceCancellationResolutionData $data,
        User $actor
    ): ServiceCancellationResolution {
        $normalized = $this->normalizeResolution($data);

        return DB::transaction(function () use (
            $data,
            $actor,
            $normalized
        ): ServiceCancellationResolution {
            $organizationId = $this->organizationId($actor);
            $this->guardActor($organizationId, $actor, 'resolve');

            $existing = ServiceCancellationResolution::query()
                ->forOrganization($organizationId)
                ->where('idempotency_key', $normalized['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->guardFingerprint(
                    $existing->fingerprint,
                    $normalized['fingerprint'],
                    'resolución de cancelación'
                );

                return $existing->load('returnRecord');
            }

            $request = ServiceCancellationRequest::query()
                ->forOrganization($organizationId)
                ->whereKey($data->serviceCancellationRequestId)
                ->lockForUpdate()
                ->first();

            if (! $request) {
                throw new DomainException(
                    'La solicitud no pertenece a la organización activa.'
                );
            }

            if ($request->resolution()->exists()) {
                throw new DomainException(
                    'La solicitud ya posee una resolución inmutable.'
                );
            }

            $order = $this->lockedOrder(
                $organizationId,
                (int) $request->service_order_id
            );

            if ($order->status !== ServiceOrderStatus::CancellationPending) {
                throw new DomainException(
                    'La orden no está pendiente de resolución de cancelación.'
                );
            }

            if ($order->commerceSale()->exists()) {
                throw new DomainException(
                    'La orden posee una venta y requiere una reversión comercial.'
                );
            }

            if ($order->workItems()
                ->whereNotIn('status', [
                    ServiceWorkStatus::Completed->value,
                    ServiceWorkStatus::Unresolved->value,
                    ServiceWorkStatus::Cancelled->value,
                ])
                ->exists()
            ) {
                throw new DomainException(
                    'Aún existen trabajos activos que deben detenerse o retornar.'
                );
            }

            $this->guardOrganizationCustody($order);
            $resolvedAt = CarbonImmutable::now();
            $resolution = ServiceCancellationResolution::query()->create([
                'organization_id' => $organizationId,
                'service_cancellation_request_id' => $request->id,
                'financial_outcome' => $data->financialOutcome,
                'currency_code' => $normalized['currency_code'],
                'customer_charge_minor' =>
                    $normalized['customer_charge_minor'],
                'customer_acceptance_reference' =>
                    $normalized['customer_acceptance_reference'],
                'work_disposition' => $normalized['work_disposition'],
                'parts_disposition' => $normalized['parts_disposition'],
                'financial_disposition' =>
                    $normalized['financial_disposition'],
                'return_condition_notes' =>
                    $normalized['return_condition_notes'],
                'accessories_snapshot' =>
                    $normalized['accessories_snapshot'],
                'notes' => $normalized['notes'],
                'resolved_by_user_id' => $actor->id,
                'resolved_at' => $resolvedAt,
                'idempotency_key' => $normalized['idempotency_key'],
                'fingerprint' => $normalized['fingerprint'],
            ]);

            $this->transitionOrder(
                $order,
                ServiceOrderStatus::ReadyForReturn,
                $actor,
                'Los compromisos de la cancelación fueron resueltos.',
                $resolvedAt
            );

            return $resolution->load('request');
        }, 3);
    }

    public function returnAsset(
        ServiceCancellationReturnData $data,
        User $actor
    ): ServiceCancellationReturn {
        $normalized = $this->normalizeReturn($data);

        return DB::transaction(function () use (
            $data,
            $actor,
            $normalized
        ): ServiceCancellationReturn {
            $organizationId = $this->organizationId($actor);
            $this->guardActor($organizationId, $actor, 'return');

            $existing = ServiceCancellationReturn::query()
                ->forOrganization($organizationId)
                ->where('idempotency_key', $normalized['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->guardFingerprint(
                    $existing->fingerprint,
                    $normalized['fingerprint'],
                    'devolución cancelada'
                );

                return $existing->load('custodyEvent');
            }

            $resolution = ServiceCancellationResolution::query()
                ->forOrganization($organizationId)
                ->whereKey($data->serviceCancellationResolutionId)
                ->with('request')
                ->lockForUpdate()
                ->first();

            if (! $resolution || ! $resolution->request) {
                throw new DomainException(
                    'La resolución no pertenece a la organización activa.'
                );
            }

            if ($resolution->returnRecord()->exists()) {
                throw new DomainException(
                    'La cancelación ya posee una devolución inmutable.'
                );
            }

            $order = $this->lockedOrder(
                $organizationId,
                (int) $resolution->request->service_order_id
            );

            if ($order->status !== ServiceOrderStatus::ReadyForReturn) {
                throw new DomainException(
                    'La orden todavía no está lista para devolver.'
                );
            }

            $latestCustody = $this->guardOrganizationCustody($order);
            $recipient = $this->businessParty(
                $organizationId,
                $data->recipientBusinessPartyId,
                'destinatario'
            );
            $returnedAt = $data->returnedAt ?? CarbonImmutable::now();

            if ($returnedAt->isAfter(CarbonImmutable::now()->addMinutes(5))) {
                throw new DomainException(
                    'La devolución no puede registrarse en una fecha futura.'
                );
            }

            if ($returnedAt->isBefore($resolution->resolved_at)) {
                throw new DomainException(
                    'La devolución no puede ser anterior a su resolución.'
                );
            }

            $custody = ServiceCustodyEvent::query()->create([
                'organization_id' => $organizationId,
                'service_order_id' => $order->id,
                'event_type' => ServiceCustodyEventType::Delivered,
                'from_holder_type' => 'organization',
                'from_holder_reference' =>
                    $latestCustody->to_holder_reference,
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
                'accessories_snapshot' =>
                    $normalized['accessories_snapshot'],
                'recorded_by_user_id' => $actor->id,
                'occurred_at' => $returnedAt,
            ]);
            $return = ServiceCancellationReturn::query()->create([
                'organization_id' => $organizationId,
                'service_cancellation_resolution_id' => $resolution->id,
                'service_order_id' => $order->id,
                'service_custody_event_id' => $custody->id,
                'recipient_business_party_id' => $recipient?->id,
                'recipient_name' => $normalized['recipient_name'],
                'recipient_document' => $normalized['recipient_document'],
                'condition_notes' => $normalized['condition_notes'],
                'accessories_snapshot' =>
                    $normalized['accessories_snapshot'],
                'notes' => $normalized['notes'],
                'returned_by_user_id' => $actor->id,
                'returned_at' => $returnedAt,
                'idempotency_key' => $normalized['idempotency_key'],
                'fingerprint' => $normalized['fingerprint'],
            ]);

            $this->transitionOrder(
                $order,
                ServiceOrderStatus::Cancelled,
                $actor,
                'El equipo cancelado fue devuelto a '
                    .$normalized['recipient_name'].'.',
                $returnedAt
            );

            return $return->load(['resolution.request', 'custodyEvent']);
        }, 3);
    }

    /** @return array<string, mixed> */
    private function normalizeRequest(
        ServiceCancellationRequestData $data
    ): array {
        $normalized = [
            'service_order_id' => $data->serviceOrderId,
            'reason' => $data->reason->value,
            'requester_business_party_id' =>
                $data->requesterBusinessPartyId,
            'requester_name' => $this->required(
                $data->requesterName,
                'La persona solicitante'
            ),
            'customer_reference' =>
                $this->optional($data->customerReference),
            'channel' => $this->required($data->channel, 'El canal'),
            'details' => $this->optional($data->details),
            'idempotency_key' => $this->idempotencyKey(
                $data->idempotencyKey
            ),
        ];

        if (
            $data->reason->value === 'other'
            && $normalized['details'] === null
        ) {
            throw new DomainException(
                'El motivo alternativo requiere una explicación.'
            );
        }

        $normalized['fingerprint'] = $this->fingerprint($normalized);

        return $normalized;
    }

    /** @return array<string, mixed> */
    private function normalizeResolution(
        ServiceCancellationResolutionData $data
    ): array {
        $currency = strtoupper(trim($data->currencyCode));

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new DomainException('La moneda debe utilizar tres letras.');
        }

        if ($data->customerChargeMinor < 0) {
            throw new DomainException('El cargo no puede ser negativo.');
        }

        $reference = $this->optional(
            $data->customerAcceptanceReference
        );

        if (
            $data->financialOutcome
                === ServiceCancellationFinancialOutcome::CustomerCharge
            && ($data->customerChargeMinor < 1 || $reference === null)
        ) {
            throw new DomainException(
                'El cargo requiere importe y aceptación del cliente.'
            );
        }

        if (
            $data->financialOutcome
                !== ServiceCancellationFinancialOutcome::CustomerCharge
            && ($data->customerChargeMinor !== 0 || $reference !== null)
        ) {
            throw new DomainException(
                'Una cancelación sin cargo no admite importe ni aceptación de cobro.'
            );
        }

        $normalized = [
            'service_cancellation_request_id' =>
                $data->serviceCancellationRequestId,
            'financial_outcome' => $data->financialOutcome->value,
            'currency_code' => $currency,
            'customer_charge_minor' => $data->customerChargeMinor,
            'customer_acceptance_reference' => $reference,
            'work_disposition' => $this->required(
                $data->workDisposition,
                'La resolución del trabajo'
            ),
            'parts_disposition' => $this->required(
                $data->partsDisposition,
                'La resolución de repuestos'
            ),
            'financial_disposition' => $this->required(
                $data->financialDisposition,
                'La resolución económica'
            ),
            'return_condition_notes' => $this->required(
                $data->returnConditionNotes,
                'La condición para devolución'
            ),
            'accessories_snapshot' => $this->required(
                $data->accessoriesSnapshot,
                'Los accesorios para devolución'
            ),
            'notes' => $this->optional($data->notes),
            'idempotency_key' => $this->idempotencyKey(
                $data->idempotencyKey
            ),
        ];
        $normalized['fingerprint'] = $this->fingerprint($normalized);

        return $normalized;
    }

    /** @return array<string, mixed> */
    private function normalizeReturn(
        ServiceCancellationReturnData $data
    ): array {
        $normalized = [
            'service_cancellation_resolution_id' =>
                $data->serviceCancellationResolutionId,
            'recipient_business_party_id' =>
                $data->recipientBusinessPartyId,
            'recipient_name' => $this->required(
                $data->recipientName,
                'La persona que recibe'
            ),
            'recipient_document' =>
                $this->optional($data->recipientDocument),
            'condition_notes' => $this->required(
                $data->conditionNotes,
                'La condición de devolución'
            ),
            'accessories_snapshot' => $this->required(
                $data->accessoriesSnapshot,
                'Los accesorios devueltos'
            ),
            'notes' => $this->optional($data->notes),
            'returned_at' => $data->returnedAt?->format('c'),
            'idempotency_key' => $this->idempotencyKey(
                $data->idempotencyKey
            ),
        ];
        $normalized['fingerprint'] = $this->fingerprint($normalized);

        return $normalized;
    }

    /** @return array<string, mixed> */
    private function normalizeCustody(ServiceWorkCustodyData $data): array
    {
        $normalized = [
            'service_work_item_id' => $data->serviceWorkItemId,
            'direction' => ServiceWorkCustodyDirection::Return->value,
            'condition_notes' => $this->required(
                $data->conditionNotes,
                'La condición del activo'
            ),
            'accessories_snapshot' => $this->required(
                $data->accessoriesSnapshot,
                'Los accesorios retornados'
            ),
            'idempotency_key' => $this->idempotencyKey(
                $data->idempotencyKey
            ),
        ];
        $normalized['fingerprint'] = $this->fingerprint($normalized);

        return $normalized;
    }

    /** @return array<string, mixed> */
    private function exposureSnapshot(
        int $organizationId,
        ServiceOrder $order
    ): array {
        $workCounts = ServiceWorkItem::query()
            ->forOrganization($organizationId)
            ->where('service_order_id', $order->id)
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count): int => (int) $count)
            ->all();
        $purchaseTotals = ServicePartPurchase::query()
            ->forOrganization($organizationId)
            ->where('service_order_id', $order->id)
            ->selectRaw('currency_code, SUM(grand_total_minor) AS total')
            ->groupBy('currency_code')
            ->pluck('total', 'currency_code')
            ->map(fn ($total): int => (int) $total)
            ->all();
        $paymentTotals = CommercePayment::query()
            ->forOrganization($organizationId)
            ->whereHas('sale', fn ($query) => $query
                ->where('service_order_id', $order->id))
            ->selectRaw('SUM(amount_minor) AS total')
            ->value('total');
        $requirements = ServicePartRequirement::query()
            ->forOrganization($organizationId)
            ->where('service_order_id', $order->id)
            ->count();
        $consumptions = ServicePartConsumption::query()
            ->forOrganization($organizationId)
            ->whereHas('requirement', fn ($query) => $query
                ->where('service_order_id', $order->id))
            ->count();
        $latestCustody = $this->latestCustody($order);
        $startedStatuses = [
            ServiceWorkStatus::InProgress->value,
            ServiceWorkStatus::WithProvider->value,
            ServiceWorkStatus::Completed->value,
            ServiceWorkStatus::Unresolved->value,
        ];
        $startedWork = collect($workCounts)
            ->only($startedStatuses)
            ->sum();

        return [
            'has_started_work' => $startedWork > 0,
            'has_part_purchases' => $purchaseTotals !== [],
            'has_part_consumptions' => $consumptions > 0,
            'has_external_custody' =>
                $latestCustody?->to_holder_type === 'external_provider',
            'has_registered_payments' => (int) $paymentTotals > 0,
            'details' => [
                'order_status' => $order->status->value,
                'work_counts' => $workCounts,
                'part_requirement_count' => $requirements,
                'part_purchase_totals_minor' => $purchaseTotals,
                'part_consumption_count' => $consumptions,
                'registered_payment_total_minor' => (int) $paymentTotals,
                'latest_custody' => $latestCustody ? [
                    'event_id' => $latestCustody->id,
                    'holder_type' => $latestCustody->to_holder_type,
                    'holder_reference' =>
                        $latestCustody->to_holder_reference,
                    'holder_name' => $latestCustody->to_holder_name,
                ] : null,
            ],
        ];
    }

    private function cancelLocalWork(
        ServiceOrder $order,
        ServiceCancellationRequest $request,
        User $actor,
        CarbonImmutable $changedAt
    ): void {
        $items = $order->workItems()
            ->whereIn('status', [
                ServiceWorkStatus::Planned->value,
                ServiceWorkStatus::InProgress->value,
            ])
            ->lockForUpdate()
            ->get();

        foreach ($items as $item) {
            $key = $this->derivedKey(
                $request->idempotency_key,
                'work-'.$item->id
            );
            $this->transitionWork(
                $item,
                ServiceWorkStatus::Cancelled,
                $actor,
                'El trabajo se detuvo por la cancelación solicitada.',
                $changedAt,
                $key,
                $this->workFingerprint(
                    $item,
                    ServiceWorkStatus::Cancelled,
                    $key
                )
            );
        }
    }

    private function transitionWork(
        ServiceWorkItem $item,
        ServiceWorkStatus $target,
        User $actor,
        string $reason,
        CarbonImmutable $changedAt,
        string $idempotencyKey,
        string $fingerprint
    ): void {
        $from = $item->status;

        if (! $item->allowsTransitionTo($target)) {
            throw new DomainException(
                'La transición solicitada no es válida para el trabajo.'
            );
        }

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
        $item->status = $target;
        $item->save();
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

    private function guardOrganizationCustody(
        ServiceOrder $order
    ): ServiceCustodyEvent {
        $latest = $this->latestCustody($order);

        if (! $latest || $latest->to_holder_type !== 'organization') {
            throw new DomainException(
                'El equipo debe retornar al comercio antes de resolver.'
            );
        }

        return $latest;
    }

    private function latestCustody(
        ServiceOrder $order
    ): ?ServiceCustodyEvent {
        return ServiceCustodyEvent::query()
            ->forOrganization((int) $order->organization_id)
            ->where('service_order_id', $order->id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();
    }

    private function businessParty(
        int $organizationId,
        ?int $partyId,
        string $label
    ): ?BusinessParty {
        if ($partyId === null) {
            return null;
        }

        $party = BusinessParty::query()
            ->forOrganization($organizationId)
            ->whereKey($partyId)
            ->lockForUpdate()
            ->first();

        if (! $party) {
            throw new DomainException(
                "La ficha del {$label} no pertenece a la organización activa."
            );
        }

        return $party;
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
                'El usuario no puede registrar esta cancelación.'
            );
        }

        $membership = OrganizationMembership::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $actor->id)
            ->where('active', true)
            ->lockForUpdate()
            ->first();
        $allowed = match ($action) {
            'request' =>
                $membership?->role->canRequestServiceCancellation(),
            'resolve' =>
                $membership?->role->canResolveServiceCancellation(),
            'custody' =>
                $membership?->role->canTransferServiceCustody(),
            'return' =>
                $membership?->role->canReturnCancelledServiceOrder(),
            default => false,
        };

        if (! $allowed) {
            throw new DomainException(
                'El rol del usuario no puede registrar esta cancelación.'
            );
        }
    }

    private function workFingerprint(
        ServiceWorkItem $item,
        ServiceWorkStatus $target,
        string $idempotencyKey
    ): string {
        return $this->fingerprint([
            'service_work_item_id' => $item->id,
            'from_status' => $item->status->value,
            'to_status' => $target->value,
            'idempotency_key' => $idempotencyKey,
        ]);
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
                'No se pudo consolidar la cancelación.',
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
                "La clave de {$operation} ya fue utilizada con otros datos."
            );
        }
    }
}
