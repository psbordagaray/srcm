<?php

namespace App\Domain\Service;

use App\Enums\ServiceCustodyEventType;
use App\Enums\ServiceOrderStatus;
use App\Enums\ServiceWarrantyClaimOutcome;
use App\Enums\ServiceWarrantyClaimStatus;
use App\Enums\ServiceWarrantyTemporalStatus;
use App\Models\BusinessParty;
use App\Models\InventoryLocation;
use App\Models\OrganizationMembership;
use App\Models\ServiceCustodyEvent;
use App\Models\ServiceDelivery;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderStatusHistory;
use App\Models\ServiceWarrantyClaim;
use App\Models\ServiceWarrantyClaimResolution;
use App\Models\ServiceWarrantyClaimReturn;
use App\Models\ServiceWarrantyClaimStatusHistory;
use App\Models\ServiceWarrantyGrant;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

final class ServiceWarrantyClaimManager
{
    public function __construct(
        private readonly ServiceOrderIntakeManager $intakeManager
    ) {}

    public function register(
        ServiceWarrantyClaimData $data,
        User $actor
    ): ServiceWarrantyClaim {
        $normalized = $this->normalizeClaim($data);

        return DB::transaction(function () use (
            $data,
            $actor,
            $normalized
        ): ServiceWarrantyClaim {
            $organizationId = $this->organizationId($actor);
            $this->guardActor($organizationId, $actor, 'register');

            $existing = ServiceWarrantyClaim::query()
                ->forOrganization($organizationId)
                ->where('idempotency_key', $normalized['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->guardFingerprint(
                    $existing->fingerprint,
                    $normalized['fingerprint'],
                    'reclamo de garantía'
                );

                return $this->loadClaim($existing);
            }

            $warranty = ServiceWarrantyGrant::query()
                ->forOrganization($organizationId)
                ->whereKey($data->serviceWarrantyGrantId)
                ->lockForUpdate()
                ->first();

            if (! $warranty) {
                throw new DomainException(
                    'La garantía no pertenece a la organización activa.'
                );
            }

            $delivery = ServiceDelivery::query()
                ->forOrganization($organizationId)
                ->whereKey($warranty->service_delivery_id)
                ->lockForUpdate()
                ->first();

            if (! $delivery) {
                throw new DomainException(
                    'La garantía no posee una entrega de origen válida.'
                );
            }

            $originalOrder = ServiceOrder::query()
                ->forOrganization($organizationId)
                ->with(['asset.identifiers', 'intake'])
                ->whereKey($delivery->service_order_id)
                ->lockForUpdate()
                ->first();

            if (
                ! $originalOrder
                || $originalOrder->status !== ServiceOrderStatus::Delivered
                || ! $originalOrder->intake
            ) {
                throw new DomainException(
                    'La garantía sólo puede reclamarse sobre una orden entregada.'
                );
            }

            if ($normalized['claimed_at']->isBefore($warranty->starts_at)) {
                throw new DomainException(
                    'El reclamo no puede ser anterior al inicio de la garantía.'
                );
            }

            $openClaim = ServiceWarrantyClaim::query()
                ->forOrganization($organizationId)
                ->where('open_warranty_grant_id', $warranty->id)
                ->lockForUpdate()
                ->first(['id']);

            if ($openClaim) {
                throw new DomainException(
                    'La garantía ya posee un reclamo abierto.'
                );
            }

            $location = InventoryLocation::query()
                ->forOrganization($organizationId)
                ->whereKey($data->intakeLocationId)
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if (! $location) {
                throw new DomainException(
                    'La ubicación de reingreso no pertenece a la organización o está inactiva.'
                );
            }

            $claimant = $this->party(
                $organizationId,
                $data->claimantBusinessPartyId,
                'reclamante'
            );
            $statusAtClaim = $normalized['claimed_at']
                ->lessThanOrEqualTo($warranty->expires_at)
                    ? ServiceWarrantyTemporalStatus::Active
                    : ServiceWarrantyTemporalStatus::Expired;

            $identifiers = $originalOrder->asset->identifiers
                ->map(fn ($identifier): ServiceAssetIdentifierData => new ServiceAssetIdentifierData(
                    $identifier->identifier_type,
                    $identifier->value
                )
                )
                ->values()
                ->all();

            $correctiveOrder = $this->intakeManager->create(
                new ServiceOrderIntakeData(
                    assetType: $originalOrder->asset->asset_type,
                    brandName: $originalOrder->asset->brand_name,
                    modelName: $originalOrder->asset->model_name,
                    identifiers: $identifiers,
                    intakeLocationId: $location->id,
                    customerReportedIssue: $normalized['reported_issue'],
                    idempotencyKey: $this->derivedKey(
                        $normalized['idempotency_key'],
                        'corrective-order'
                    ),
                    customerBusinessPartyId: $originalOrder->customer_business_party_id,
                    customerName: $originalOrder->intake->customer_name_snapshot,
                    ownerBusinessPartyId: $originalOrder->owner_business_party_id,
                    ownerName: $originalOrder->intake->owner_name_snapshot,
                    color: $originalOrder->asset->color,
                    intakeObservations: $normalized['reentry_condition_notes'],
                    receivedAccessories: $normalized['accessories_snapshot'],
                    contactAvailable: (bool) $originalOrder->intake->contact_available,
                    contactReference: $originalOrder->intake->contact_reference,
                    metadata: [
                        'warranty_corrective_order' => true,
                        'original_service_order_id' => $originalOrder->id,
                        'original_service_delivery_id' => $delivery->id,
                        'service_warranty_grant_id' => $warranty->id,
                    ],
                    serviceAssetId: $originalOrder->service_asset_id
                ),
                $actor
            );

            if ((int) $correctiveOrder->service_asset_id
                !== (int) $originalOrder->service_asset_id) {
                throw new DomainException(
                    'El reingreso correctivo debe conservar el activo original.'
                );
            }

            $receivedAt = CarbonImmutable::now();
            $claim = ServiceWarrantyClaim::query()->create([
                'organization_id' => $organizationId,
                'service_warranty_grant_id' => $warranty->id,
                'open_warranty_grant_id' => $warranty->id,
                'original_service_order_id' => $originalOrder->id,
                'original_service_delivery_id' => $delivery->id,
                'corrective_service_order_id' => $correctiveOrder->id,
                'claimant_business_party_id' => $claimant?->id,
                'claimant_name' => $normalized['claimant_name'],
                'channel' => $normalized['channel'],
                'customer_reference' => $normalized['customer_reference'],
                'reported_issue' => $normalized['reported_issue'],
                'reentry_condition_notes' => $normalized['reentry_condition_notes'],
                'accessories_snapshot' => $normalized['accessories_snapshot'],
                'warranty_status_at_claim' => $statusAtClaim,
                'claimed_at' => $normalized['claimed_at'],
                'received_at' => $receivedAt,
                'received_by_user_id' => $actor->id,
                'intake_location_id' => $location->id,
                'status' => ServiceWarrantyClaimStatus::PendingReview,
                'closed_at' => null,
                'idempotency_key' => $normalized['idempotency_key'],
                'fingerprint' => $normalized['fingerprint'],
            ]);

            $this->appendClaimHistory(
                $claim,
                null,
                ServiceWarrantyClaimStatus::PendingReview,
                $actor,
                'Reclamo de garantía registrado y activo reingresado.',
                $receivedAt,
                $this->derivedKey(
                    $normalized['idempotency_key'],
                    'registered'
                )
            );

            return $this->loadClaim($claim);
        }, 3);
    }

    public function resolve(
        ServiceWarrantyClaimResolutionData $data,
        User $actor
    ): ServiceWarrantyClaimResolution {
        $normalized = $this->normalizeResolution($data);

        return DB::transaction(function () use (
            $data,
            $actor,
            $normalized
        ): ServiceWarrantyClaimResolution {
            $organizationId = $this->organizationId($actor);
            $this->guardActor($organizationId, $actor, 'resolve');

            $existing = ServiceWarrantyClaimResolution::query()
                ->forOrganization($organizationId)
                ->where('idempotency_key', $normalized['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->guardFingerprint(
                    $existing->fingerprint,
                    $normalized['fingerprint'],
                    'resolución de garantía'
                );

                return $existing->load(['claim', 'resolvedBy']);
            }

            $claim = ServiceWarrantyClaim::query()
                ->forOrganization($organizationId)
                ->with('warrantyGrant')
                ->whereKey($data->serviceWarrantyClaimId)
                ->lockForUpdate()
                ->first();

            if (! $claim) {
                throw new DomainException(
                    'El reclamo no pertenece a la organización activa.'
                );
            }

            if ($claim->status !== ServiceWarrantyClaimStatus::PendingReview) {
                throw new DomainException(
                    'El reclamo ya fue resuelto o no admite evaluación.'
                );
            }

            $order = ServiceOrder::query()
                ->forOrganization($organizationId)
                ->whereKey($claim->corrective_service_order_id)
                ->lockForUpdate()
                ->first();

            if (! $order || $order->status !== ServiceOrderStatus::Received) {
                throw new DomainException(
                    'La orden correctiva no está en estado de evaluación.'
                );
            }

            $resolvedAt = CarbonImmutable::now();
            $statusAtResolution = $resolvedAt
                ->lessThanOrEqualTo($claim->warrantyGrant->expires_at)
                    ? ServiceWarrantyTemporalStatus::Active
                    : ServiceWarrantyTemporalStatus::Expired;
            $exception = $claim->warranty_status_at_claim
                    === ServiceWarrantyTemporalStatus::Expired
                && $data->outcome->authorizesCorrectiveWork();

            if ($exception && $normalized['exception_reason'] === null) {
                throw new DomainException(
                    'Aceptar una garantía reclamada fuera de término requiere un motivo administrativo.'
                );
            }

            if (! $exception && $normalized['exception_reason'] !== null) {
                throw new DomainException(
                    'El motivo de excepción sólo corresponde a una aceptación fuera de término.'
                );
            }

            $resolution = ServiceWarrantyClaimResolution::query()->create([
                'organization_id' => $organizationId,
                'service_warranty_claim_id' => $claim->id,
                'outcome' => $data->outcome,
                'technical_basis' => $normalized['technical_basis'],
                'covered_scope' => $normalized['covered_scope'],
                'excluded_scope' => $normalized['excluded_scope'],
                'warranty_status_at_resolution' => $statusAtResolution,
                'administrative_exception' => $exception,
                'exception_reason' => $normalized['exception_reason'],
                'notes' => $normalized['notes'],
                'resolved_by_user_id' => $actor->id,
                'resolved_at' => $resolvedAt,
                'idempotency_key' => $normalized['idempotency_key'],
                'fingerprint' => $normalized['fingerprint'],
            ]);

            if ($data->outcome->authorizesCorrectiveWork()) {
                $decisionStatus = $data->outcome
                    === ServiceWarrantyClaimOutcome::Accepted
                        ? ServiceWarrantyClaimStatus::Accepted
                        : ServiceWarrantyClaimStatus::PartiallyAccepted;

                $this->transitionClaim(
                    $claim,
                    $decisionStatus,
                    $actor,
                    'Cobertura de garantía resuelta: '
                        .$data->outcome->label().'.',
                    $resolvedAt,
                    $this->derivedKey(
                        $normalized['idempotency_key'],
                        'decision'
                    )
                );
                $this->transitionClaim(
                    $claim,
                    ServiceWarrantyClaimStatus::InCorrectiveWork,
                    $actor,
                    'La orden correctiva quedó autorizada para ejecución.',
                    $resolvedAt,
                    $this->derivedKey(
                        $normalized['idempotency_key'],
                        'corrective-work'
                    )
                );
                $this->transitionOrder(
                    $order,
                    ServiceOrderStatus::InProgress,
                    $actor,
                    'Trabajo correctivo autorizado por garantía.',
                    $resolvedAt
                );
            } else {
                $this->transitionClaim(
                    $claim,
                    ServiceWarrantyClaimStatus::Rejected,
                    $actor,
                    'El reclamo no posee cobertura de garantía.',
                    $resolvedAt,
                    $this->derivedKey(
                        $normalized['idempotency_key'],
                        'rejected'
                    )
                );
                $this->transitionClaim(
                    $claim,
                    ServiceWarrantyClaimStatus::ReadyForReturn,
                    $actor,
                    'El activo quedó listo para devolución sin trabajo correctivo.',
                    $resolvedAt,
                    $this->derivedKey(
                        $normalized['idempotency_key'],
                        'ready-return'
                    )
                );
                $this->transitionOrder(
                    $order,
                    ServiceOrderStatus::ReadyForReturn,
                    $actor,
                    'Garantía rechazada; activo listo para devolver.',
                    $resolvedAt
                );
            }

            return $resolution->load(['claim.statusHistory', 'resolvedBy']);
        }, 3);
    }

    public function returnAsset(
        ServiceWarrantyClaimReturnData $data,
        User $actor
    ): ServiceWarrantyClaimReturn {
        $normalized = $this->normalizeReturn($data);

        return DB::transaction(function () use (
            $data,
            $actor,
            $normalized
        ): ServiceWarrantyClaimReturn {
            $organizationId = $this->organizationId($actor);
            $this->guardActor($organizationId, $actor, 'return');

            $existing = ServiceWarrantyClaimReturn::query()
                ->forOrganization($organizationId)
                ->where('idempotency_key', $normalized['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->guardFingerprint(
                    $existing->fingerprint,
                    $normalized['fingerprint'],
                    'devolución de garantía'
                );

                return $existing->load([
                    'claim',
                    'resolution',
                    'custodyEvent',
                    'recipient',
                    'returnedBy',
                ]);
            }

            $claim = ServiceWarrantyClaim::query()
                ->forOrganization($organizationId)
                ->with('resolution')
                ->whereKey($data->serviceWarrantyClaimId)
                ->lockForUpdate()
                ->first();

            if (
                ! $claim
                || $claim->status
                    !== ServiceWarrantyClaimStatus::ReadyForReturn
                || ! $claim->resolution
                || $claim->resolution->outcome
                    !== ServiceWarrantyClaimOutcome::Rejected
            ) {
                throw new DomainException(
                    'El reclamo no está listo para devolución.'
                );
            }

            $order = ServiceOrder::query()
                ->forOrganization($organizationId)
                ->whereKey($claim->corrective_service_order_id)
                ->lockForUpdate()
                ->first();

            if (! $order || $order->status !== ServiceOrderStatus::ReadyForReturn) {
                throw new DomainException(
                    'La orden correctiva no está lista para devolución.'
                );
            }

            $recipient = $this->party(
                $organizationId,
                $data->recipientBusinessPartyId,
                'receptor'
            );
            $latestCustody = $this->guardOrganizationCustody($order);
            $returnedAt = $data->returnedAt
                ? CarbonImmutable::instance($data->returnedAt)
                : CarbonImmutable::now();

            if ($returnedAt->isAfter(CarbonImmutable::now()->addMinutes(5))) {
                throw new DomainException(
                    'La devolución no puede registrarse en una fecha futura.'
                );
            }

            if ($returnedAt->isBefore($claim->received_at)) {
                throw new DomainException(
                    'La devolución no puede ser anterior al reingreso.'
                );
            }

            $custody = ServiceCustodyEvent::query()->create([
                'organization_id' => $organizationId,
                'service_order_id' => $order->id,
                'event_type' => ServiceCustodyEventType::WarrantyReturned,
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
                'occurred_at' => $returnedAt,
            ]);

            $return = ServiceWarrantyClaimReturn::query()->create([
                'organization_id' => $organizationId,
                'service_warranty_claim_id' => $claim->id,
                'service_warranty_claim_resolution_id' => $claim->resolution->id,
                'corrective_service_order_id' => $order->id,
                'service_custody_event_id' => $custody->id,
                'recipient_business_party_id' => $recipient?->id,
                'recipient_name' => $normalized['recipient_name'],
                'recipient_document' => $normalized['recipient_document'],
                'condition_notes' => $normalized['condition_notes'],
                'accessories_snapshot' => $normalized['accessories_snapshot'],
                'notes' => $normalized['notes'],
                'returned_by_user_id' => $actor->id,
                'returned_at' => $returnedAt,
                'idempotency_key' => $normalized['idempotency_key'],
                'fingerprint' => $normalized['fingerprint'],
            ]);

            $this->transitionClaim(
                $claim,
                ServiceWarrantyClaimStatus::Closed,
                $actor,
                'Activo devuelto luego del rechazo de garantía.',
                $returnedAt,
                $this->derivedKey(
                    $normalized['idempotency_key'],
                    'closed'
                )
            );
            $this->transitionOrder(
                $order,
                ServiceOrderStatus::Cancelled,
                $actor,
                'Activo devuelto sin intervención por garantía.',
                $returnedAt
            );

            return $return->load([
                'claim.statusHistory',
                'resolution',
                'custodyEvent',
                'recipient',
                'returnedBy',
            ]);
        }, 3);
    }

    public function closeAfterDelivery(
        ServiceDelivery $delivery,
        User $actor
    ): ?ServiceWarrantyClaim {
        return DB::transaction(function () use (
            $delivery,
            $actor
        ): ?ServiceWarrantyClaim {
            $organizationId = $this->organizationId($actor);

            if ((int) $delivery->organization_id !== $organizationId) {
                throw new DomainException(
                    'La entrega no pertenece a la organización activa.'
                );
            }

            $claim = ServiceWarrantyClaim::query()
                ->forOrganization($organizationId)
                ->where(
                    'corrective_service_order_id',
                    $delivery->service_order_id
                )
                ->lockForUpdate()
                ->first();

            if (! $claim) {
                return null;
            }

            if ($claim->status === ServiceWarrantyClaimStatus::Closed) {
                return $claim;
            }

            if ($claim->status !== ServiceWarrantyClaimStatus::InCorrectiveWork) {
                throw new DomainException(
                    'El reclamo correctivo no admite cierre por entrega.'
                );
            }

            $order = ServiceOrder::query()
                ->forOrganization($organizationId)
                ->whereKey($claim->corrective_service_order_id)
                ->lockForUpdate()
                ->first();

            if (
                ! $order
                || $order->status !== ServiceOrderStatus::ReadyForDelivery
                || (int) $delivery->service_order_id !== $order->id
            ) {
                throw new DomainException(
                    'La entrega no corresponde a la orden correctiva lista.'
                );
            }

            $this->transitionClaim(
                $claim,
                ServiceWarrantyClaimStatus::Closed,
                $actor,
                'Trabajo correctivo entregado y reclamo de garantía cerrado.',
                CarbonImmutable::instance($delivery->delivered_at),
                $this->derivedKey(
                    $delivery->idempotency_key,
                    'warranty-claim-closed'
                )
            );

            return $claim->fresh();
        }, 3);
    }

    /** @return array<string, mixed> */
    private function normalizeClaim(ServiceWarrantyClaimData $data): array
    {
        $claimantName = $this->required($data->claimantName, 'reclamante');
        $reportedIssue = $this->requiredMultiline(
            $data->reportedIssue,
            'falla reclamada'
        );
        $condition = $this->requiredMultiline(
            $data->reentryConditionNotes,
            'condición de reingreso'
        );
        $accessories = $this->requiredMultiline(
            $data->accessoriesSnapshot,
            'accesorios de reingreso'
        );
        $channel = $this->required($data->channel, 'canal');
        $customerReference = $this->optional($data->customerReference);
        $key = $this->idempotencyKey($data->idempotencyKey);
        $claimedAt = CarbonImmutable::instance($data->claimedAt)->utc();

        if ($claimedAt->isAfter(CarbonImmutable::now())) {
            throw new DomainException(
                'La fecha del reclamo no puede estar en el futuro.'
            );
        }

        if ($data->serviceWarrantyGrantId <= 0 || $data->intakeLocationId <= 0) {
            throw new DomainException(
                'La garantía o la ubicación de reingreso es inválida.'
            );
        }

        $payload = [
            'service_warranty_grant_id' => $data->serviceWarrantyGrantId,
            'intake_location_id' => $data->intakeLocationId,
            'claimant_business_party_id' => $data->claimantBusinessPartyId,
            'claimant_name' => $claimantName,
            'reported_issue' => $reportedIssue,
            'reentry_condition_notes' => $condition,
            'accessories_snapshot' => $accessories,
            'channel' => $channel,
            'customer_reference' => $customerReference,
            'claimed_at' => $claimedAt->format('Y-m-d\TH:i:s.u\Z'),
            'idempotency_key' => $key,
        ];

        $payload['fingerprint'] = $this->fingerprint($payload);
        $payload['claimed_at'] = $claimedAt;

        return $payload;
    }

    /** @return array<string, mixed> */
    private function normalizeResolution(
        ServiceWarrantyClaimResolutionData $data
    ): array {
        if ($data->serviceWarrantyClaimId <= 0) {
            throw new DomainException('El reclamo de garantía es inválido.');
        }

        $basis = $this->requiredMultiline(
            $data->technicalBasis,
            'fundamento técnico'
        );
        $covered = $this->optionalMultiline($data->coveredScope);
        $excluded = $this->optionalMultiline($data->excludedScope);
        $exception = $this->optionalMultiline($data->exceptionReason);
        $notes = $this->optionalMultiline($data->notes);
        $key = $this->idempotencyKey($data->idempotencyKey);

        match ($data->outcome) {
            ServiceWarrantyClaimOutcome::Accepted => ($covered !== null && $excluded === null)
                    ?: throw new DomainException(
                        'La aceptación total requiere alcance cubierto y no admite exclusiones.'
                    ),
            ServiceWarrantyClaimOutcome::PartiallyAccepted => ($covered !== null && $excluded !== null)
                    ?: throw new DomainException(
                        'La aceptación parcial requiere alcance cubierto y excluido.'
                    ),
            ServiceWarrantyClaimOutcome::Rejected => ($covered === null && $excluded !== null)
                    ?: throw new DomainException(
                        'El rechazo requiere alcance excluido y no admite cobertura.'
                    ),
        };

        $payload = [
            'service_warranty_claim_id' => $data->serviceWarrantyClaimId,
            'outcome' => $data->outcome->value,
            'technical_basis' => $basis,
            'covered_scope' => $covered,
            'excluded_scope' => $excluded,
            'exception_reason' => $exception,
            'notes' => $notes,
            'idempotency_key' => $key,
        ];
        $payload['fingerprint'] = $this->fingerprint($payload);

        return $payload;
    }

    /** @return array<string, mixed> */
    private function normalizeReturn(
        ServiceWarrantyClaimReturnData $data
    ): array {
        if ($data->serviceWarrantyClaimId <= 0) {
            throw new DomainException('El reclamo de garantía es inválido.');
        }

        $payload = [
            'service_warranty_claim_id' => $data->serviceWarrantyClaimId,
            'recipient_business_party_id' => $data->recipientBusinessPartyId,
            'recipient_name' => $this->required(
                $data->recipientName,
                'receptor'
            ),
            'recipient_document' => $this->optional(
                $data->recipientDocument
            ),
            'condition_notes' => $this->requiredMultiline(
                $data->conditionNotes,
                'condición de devolución'
            ),
            'accessories_snapshot' => $this->requiredMultiline(
                $data->accessoriesSnapshot,
                'accesorios devueltos'
            ),
            'notes' => $this->optionalMultiline($data->notes),
            'returned_at' => $data->returnedAt
                ? CarbonImmutable::instance($data->returnedAt)
                    ->utc()
                    ->format('Y-m-d\TH:i:s.u\Z')
                : null,
            'idempotency_key' => $this->idempotencyKey($data->idempotencyKey),
        ];
        $payload['fingerprint'] = $this->fingerprint($payload);

        return $payload;
    }

    private function transitionClaim(
        ServiceWarrantyClaim $claim,
        ServiceWarrantyClaimStatus $target,
        User $actor,
        string $reason,
        CarbonImmutable $changedAt,
        string $idempotencyKey
    ): void {
        $from = $claim->status;
        $this->appendClaimHistory(
            $claim,
            $from,
            $target,
            $actor,
            $reason,
            $changedAt,
            $idempotencyKey
        );

        $claim->status = $target;

        if ($target === ServiceWarrantyClaimStatus::Closed) {
            $claim->open_warranty_grant_id = null;
            $claim->closed_at = $changedAt;
        }

        $claim->save();
    }

    private function appendClaimHistory(
        ServiceWarrantyClaim $claim,
        ?ServiceWarrantyClaimStatus $from,
        ServiceWarrantyClaimStatus $to,
        User $actor,
        string $reason,
        CarbonImmutable $changedAt,
        string $idempotencyKey
    ): ServiceWarrantyClaimStatusHistory {
        $key = $this->idempotencyKey($idempotencyKey);
        $payload = [
            'service_warranty_claim_id' => $claim->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'changed_by_user_id' => $actor->id,
            'reason' => $reason,
            'changed_at' => $changedAt->utc()
                ->format('Y-m-d\TH:i:s.u\Z'),
            'idempotency_key' => $key,
        ];
        $fingerprint = $this->fingerprint($payload);
        $existing = ServiceWarrantyClaimStatusHistory::query()
            ->forOrganization($claim->organization_id)
            ->where('idempotency_key', $key)
            ->lockForUpdate()
            ->first();

        if ($existing) {
            $this->guardFingerprint(
                $existing->fingerprint,
                $fingerprint,
                'historia del reclamo'
            );

            return $existing;
        }

        return ServiceWarrantyClaimStatusHistory::query()->create([
            'organization_id' => $claim->organization_id,
            'service_warranty_claim_id' => $claim->id,
            'from_status' => $from,
            'to_status' => $to,
            'changed_by_user_id' => $actor->id,
            'reason' => $reason,
            'changed_at' => $changedAt,
            'idempotency_key' => $key,
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

    private function loadClaim(ServiceWarrantyClaim $claim): ServiceWarrantyClaim
    {
        return $claim->load([
            'warrantyGrant.workReport.workItem',
            'originalOrder',
            'originalDelivery',
            'correctiveOrder.intake',
            'claimant',
            'receivedBy',
            'intakeLocation',
            'statusHistory.changedBy',
            'resolution.resolvedBy',
            'returnRecord.custodyEvent',
        ]);
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
                'La organización no posee la custodia actual del activo.'
            );
        }

        return $latest;
    }

    private function party(
        int $organizationId,
        ?int $partyId,
        string $role
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
                "El {$role} no pertenece a la organización activa."
            );
        }

        return $party;
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
        string $operation
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

        $allowed = match ($operation) {
            'register' => $membership?->role->canRegisterServiceWarrantyClaims(),
            'resolve' => $membership?->role->canResolveServiceWarrantyClaims(),
            'return' => $membership?->role->canReturnServiceWarrantyClaims(),
            default => false,
        };

        if (! $allowed) {
            throw new DomainException(
                'El rol del usuario no puede ejecutar esta operación de garantía.'
            );
        }
    }

    private function required(string $value, string $label): string
    {
        $value = Str::of($value)->squish()->toString();

        if ($value === '' || Str::length($value) > 255) {
            throw new DomainException("El dato {$label} es inválido.");
        }

        return $value;
    }

    private function requiredMultiline(string $value, string $label): string
    {
        $value = trim($value);

        if ($value === '' || Str::length($value) > 10000) {
            throw new DomainException("El dato {$label} es inválido.");
        }

        return $value;
    }

    private function optional(?string $value): ?string
    {
        $value = Str::of((string) $value)->squish()->toString();

        if ($value !== '' && Str::length($value) > 255) {
            throw new DomainException(
                'Un dato breve del reclamo excede la longitud permitida.'
            );
        }

        return $value === '' ? null : $value;
    }

    private function optionalMultiline(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value !== '' && Str::length($value) > 10000) {
            throw new DomainException(
                'Una observación del reclamo excede la longitud permitida.'
            );
        }

        return $value === '' ? null : $value;
    }

    private function idempotencyKey(string $value): string
    {
        $value = trim($value);

        if ($value === '' || Str::length($value) > 100) {
            throw new DomainException(
                'La clave de idempotencia de garantía es inválida.'
            );
        }

        return $value;
    }

    private function derivedKey(string $base, string $suffix): string
    {
        return 'warranty:'.hash('sha256', $base.':'.$suffix);
    }

    /** @param array<string, mixed> $payload */
    private function fingerprint(array $payload): string
    {
        ksort($payload, SORT_STRING);

        try {
            return hash(
                'sha256',
                json_encode(
                    $payload,
                    JSON_THROW_ON_ERROR
                        | JSON_UNESCAPED_SLASHES
                        | JSON_UNESCAPED_UNICODE
                )
            );
        } catch (JsonException $exception) {
            throw new DomainException(
                'Los datos del reclamo no son serializables.',
                previous: $exception
            );
        }
    }

    private function guardFingerprint(
        string $actual,
        string $expected,
        string $operation
    ): void {
        if (! hash_equals($actual, $expected)) {
            throw new DomainException(
                "La clave de idempotencia de {$operation} ya fue utilizada con otros datos."
            );
        }
    }
}
