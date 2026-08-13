<?php

namespace App\Domain\Attention;

use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CashSecurityDropRequestStatus;
use App\Enums\InventoryNegativeRequestStatus;
use App\Enums\PurchasePaymentRequestStatus;
use App\Models\CashSecurityDropRequest;
use App\Models\InventoryNegativeRequest;
use App\Models\OperationalAttentionReceipt;
use App\Models\PurchasePaymentRequest;
use App\Models\User;
use Illuminate\Support\Collection;

final class OperationalAttentionReader
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization
    ) {
    }

    /**
     * @return array{
     *   count: int,
     *   action_count: int,
     *   result_count: int,
     *   items: Collection<int, array<string, mixed>>
     * }
     */
    public function read(User $actor): array
    {
        return $this->build($actor, false);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findAcknowledgeable(
        User $actor,
        string $attentionKey
    ): ?array {
        return $this->build($actor, true)['items']
            ->first(
                fn (array $item): bool =>
                    $item['key'] === $attentionKey
                    && $item['acknowledgeable'] === true
            );
    }

    /**
     * @return array{
     *   count: int,
     *   action_count: int,
     *   result_count: int,
     *   items: Collection<int, array<string, mixed>>
     * }
     */
    private function build(
        User $actor,
        bool $includeAcknowledged
    ): array {
        $organizationId = $this->currentOrganization->idOrNull($actor);

        if ($organizationId === null) {
            return $this->result(collect());
        }

        $items = collect();

        $items = $items
            ->concat($this->cashSecurityDropItems($actor, $organizationId))
            ->concat($this->purchasePaymentItems($actor, $organizationId))
            ->concat($this->inventoryOverrideItems($actor, $organizationId));

        if (! $includeAcknowledged) {
            $acknowledgeableKeys = $items
                ->where('acknowledgeable', true)
                ->pluck('key')
                ->values();

            if ($acknowledgeableKeys->isNotEmpty()) {
                $acknowledged = OperationalAttentionReceipt::query()
                    ->where('organization_id', $organizationId)
                    ->where('user_id', $actor->id)
                    ->whereIn('attention_key', $acknowledgeableKeys)
                    ->pluck('attention_key')
                    ->all();

                $items = $items->reject(
                    fn (array $item): bool =>
                        $item['acknowledgeable'] === true
                        && in_array($item['key'], $acknowledged, true)
                );
            }
        }

        $items = $items
            ->sort(function (array $left, array $right): int {
                $priority = $left['priority'] <=> $right['priority'];

                if ($priority !== 0) {
                    return $priority;
                }

                return $right['occurred_at']->getTimestamp()
                    <=> $left['occurred_at']->getTimestamp();
            })
            ->values()
            ->take(20);

        return $this->result($items);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function cashSecurityDropItems(
        User $actor,
        int $organizationId
    ): Collection {
        $items = collect();

        if ($actor->can('approve-cash-security-drop')) {
            $pending = CashSecurityDropRequest::query()
                ->forOrganization($organizationId)
                ->where(
                    'status',
                    CashSecurityDropRequestStatus::Pending->value
                )
                ->where('requested_by_user_id', '!=', $actor->id)
                ->with([
                    'register:id,name',
                    'requestedBy:id,name',
                    'destinationFinancialAccount:id,name',
                ])
                ->orderBy('requested_at')
                ->orderBy('id')
                ->get();

            foreach ($pending as $request) {
                $items->push($this->item(
                    sourceType: 'cash_security_drop',
                    sourcePublicId: $request->public_id,
                    state: CashSecurityDropRequestStatus::Pending->value,
                    kind: 'action',
                    severity: 'warning',
                    title: 'Autorizar retiro de seguridad',
                    detail: ($request->requestedBy?->name ?? 'Usuario')
                        .' solicita '
                        .$this->money(
                            $request->amount_minor,
                            $request->currency_code
                        )
                        .' desde '
                        .($request->register?->name ?? 'Caja')
                        .' hacia '
                        .($request->destinationFinancialAccount?->name
                            ?? 'tesorería')
                        .'.',
                    url: route('cash-registers.index')
                        .'#approval-security-drop-'.$request->public_id,
                    occurredAt: $request->requested_at,
                    priority: 10,
                    acknowledgeable: false
                ));
            }
        }

        if (
            $actor->can('request-cash-security-drop')
            || $actor->can('execute-cash-security-drop')
        ) {
            $own = CashSecurityDropRequest::query()
                ->forOrganization($organizationId)
                ->where('requested_by_user_id', $actor->id)
                ->whereIn('status', [
                    CashSecurityDropRequestStatus::Approved->value,
                    CashSecurityDropRequestStatus::Rejected->value,
                    CashSecurityDropRequestStatus::Expired->value,
                ])
                ->with([
                    'register:id,name',
                    'approvedBy:id,name',
                    'resolvedBy:id,name',
                    'destinationFinancialAccount:id,name',
                ])
                ->latest('requested_at')
                ->latest('id')
                ->limit(20)
                ->get();

            foreach ($own as $request) {
                if (
                    $request->status
                    === CashSecurityDropRequestStatus::Approved
                    && $actor->can('execute-cash-security-drop')
                ) {
                    $items->push($this->item(
                        sourceType: 'cash_security_drop',
                        sourcePublicId: $request->public_id,
                        state: CashSecurityDropRequestStatus::Approved->value,
                        kind: 'action',
                        severity: 'success',
                        title: 'Retiro autorizado · ejecutá la extracción',
                        detail: ($request->approvedBy?->name
                            ?? 'Administración')
                            .' autorizó '
                            .$this->money(
                                $request->amount_minor,
                                $request->currency_code
                            )
                            .' hacia '
                            .($request->destinationFinancialAccount?->name
                                ?? 'tesorería')
                            .'.',
                        url: route(
                            'cash-registers.index',
                            ['operation' => 'security_drop']
                        ).'#own-security-drop-'.$request->public_id,
                        occurredAt: $request->approved_at
                            ?? $request->requested_at,
                        priority: 5,
                        acknowledgeable: false
                    ));

                    continue;
                }

                if (
                    $request->status
                    === CashSecurityDropRequestStatus::Rejected
                ) {
                    $detail = 'La solicitud de '
                        .$this->money(
                            $request->amount_minor,
                            $request->currency_code
                        )
                        .' fue rechazada.';

                    if (filled($request->resolution_note)) {
                        $detail .= ' Motivo: '.$request->resolution_note;
                    }

                    $items->push($this->item(
                        sourceType: 'cash_security_drop',
                        sourcePublicId: $request->public_id,
                        state: CashSecurityDropRequestStatus::Rejected->value,
                        kind: 'result',
                        severity: 'danger',
                        title: 'Retiro de seguridad rechazado',
                        detail: $detail,
                        url: route(
                            'cash-registers.index',
                            ['operation' => 'security_drop']
                        ),
                        occurredAt: $request->resolved_at
                            ?? $request->requested_at,
                        priority: 30,
                        acknowledgeable: true
                    ));

                    continue;
                }

                if (
                    $request->status
                    === CashSecurityDropRequestStatus::Expired
                ) {
                    $items->push($this->item(
                        sourceType: 'cash_security_drop',
                        sourcePublicId: $request->public_id,
                        state: CashSecurityDropRequestStatus::Expired->value,
                        kind: 'result',
                        severity: 'warning',
                        title: 'Autorización de retiro vencida',
                        detail: 'La solicitud de '
                            .$this->money(
                                $request->amount_minor,
                                $request->currency_code
                            )
                            .' ya no puede ejecutarse.',
                        url: route(
                            'cash-registers.index',
                            ['operation' => 'security_drop']
                        ),
                        occurredAt: $request->resolved_at
                            ?? $request->requested_at,
                        priority: 35,
                        acknowledgeable: true
                    ));
                }
            }
        }

        return $items;
    }


    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function purchasePaymentItems(
        User $actor,
        int $organizationId
    ): Collection {
        $items = collect();

        if ($actor->can('approve-purchase-payments')) {
            $pending = PurchasePaymentRequest::query()
                ->forOrganization($organizationId)
                ->where(
                    'status',
                    PurchasePaymentRequestStatus::Pending->value
                )
                ->where('requested_by_user_id', '!=', $actor->id)
                ->with([
                    'requestedBy:id,name',
                    'originFinancialAccount:id,name',
                    'obligation:id,public_id,purchase_order_id,beneficiary_business_party_id',
                    'obligation.order:id,public_id',
                    'obligation.beneficiary:id,name',
                ])
                ->orderBy('requested_at')
                ->orderBy('id')
                ->get();

            foreach ($pending as $request) {
                $items->push($this->item(
                    sourceType: 'purchase_payment_request',
                    sourcePublicId: $request->public_id,
                    state:
                        PurchasePaymentRequestStatus::Pending->value,
                    kind: 'action',
                    severity: 'warning',
                    title: 'Autorizar pago a proveedor',
                    detail: ($request->requestedBy?->name ?? 'Usuario')
                        .' solicita '
                        .$this->money(
                            $request->amount_minor,
                            $request->currency_code
                        )
                        .' para '
                        .($request->obligation?->beneficiary?->name
                            ?? 'beneficiario')
                        .' desde '
                        .($request->originFinancialAccount?->name
                            ?? 'cuenta propuesta')
                        .'. Autorizar no ejecuta el pago.',
                    url: route(
                        'purchase-orders.show',
                        $request->obligation->order
                    )
                        .'#payment-request-'.$request->public_id,
                    occurredAt: $request->requested_at,
                    priority: 9,
                    acknowledgeable: false
                ));
            }
        }

        if ($actor->can('request-purchase-payments')) {
            $own = PurchasePaymentRequest::query()
                ->forOrganization($organizationId)
                ->where('requested_by_user_id', $actor->id)
                ->whereIn('status', [
                    PurchasePaymentRequestStatus::Approved->value,
                    PurchasePaymentRequestStatus::Rejected->value,
                    PurchasePaymentRequestStatus::Cancelled->value,
                    PurchasePaymentRequestStatus::Expired->value,
                ])
                ->with([
                    'approvedBy:id,name',
                    'resolvedBy:id,name',
                    'originFinancialAccount:id,name',
                    'obligation:id,public_id,purchase_order_id,beneficiary_business_party_id',
                    'obligation.order:id,public_id',
                    'obligation.beneficiary:id,name',
                ])
                ->latest('requested_at')
                ->latest('id')
                ->limit(20)
                ->get();

            foreach ($own as $request) {
                $url = route(
                    'purchase-orders.show',
                    $request->obligation->order
                )
                    .'#payment-request-'.$request->public_id;

                if (
                    $request->status
                    === PurchasePaymentRequestStatus::Approved
                ) {
                    $items->push($this->item(
                        sourceType: 'purchase_payment_request',
                        sourcePublicId: $request->public_id,
                        state:
                            PurchasePaymentRequestStatus::Approved->value,
                        kind: 'action',
                        severity: 'success',
                        title: 'Pago autorizado · ejecución pendiente',
                        detail: ($request->approvedBy?->name
                            ?? 'Administración')
                            .' autorizó '
                            .$this->money(
                                $request->amount_minor,
                                $request->currency_code
                            )
                            .' para '
                            .($request->obligation?->beneficiary?->name
                                ?? 'beneficiario')
                            .'. P4F.3 deberá ejecutar el desembolso.',
                        url: $url,
                        occurredAt: $request->approved_at
                            ?? $request->requested_at,
                        priority: 6,
                        acknowledgeable: false
                    ));

                    continue;
                }

                $title = match ($request->status) {
                    PurchasePaymentRequestStatus::Rejected =>
                        'Solicitud de pago rechazada',
                    PurchasePaymentRequestStatus::Cancelled =>
                        'Solicitud de pago cancelada',
                    PurchasePaymentRequestStatus::Expired =>
                        'Autorización de pago vencida',
                    default => 'Solicitud de pago resuelta',
                };

                $severity = match ($request->status) {
                    PurchasePaymentRequestStatus::Rejected =>
                        'danger',
                    PurchasePaymentRequestStatus::Expired =>
                        'warning',
                    default => 'neutral',
                };

                $detail = 'La solicitud de '
                    .$this->money(
                        $request->amount_minor,
                        $request->currency_code
                    )
                    .' para '
                    .($request->obligation?->beneficiary?->name
                        ?? 'beneficiario')
                    .' quedó '
                    .mb_strtolower($request->status->label())
                    .'.';

                if (filled($request->resolution_note)) {
                    $detail .= ' Motivo: '
                        .$request->resolution_note;
                }

                $items->push($this->item(
                    sourceType: 'purchase_payment_request',
                    sourcePublicId: $request->public_id,
                    state: $request->status->value,
                    kind: 'result',
                    severity: $severity,
                    title: $title,
                    detail: $detail,
                    url: $url,
                    occurredAt: $request->resolved_at
                        ?? $request->requested_at,
                    priority: 30,
                    acknowledgeable: true
                ));
            }
        }

        return $items;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function inventoryOverrideItems(
        User $actor,
        int $organizationId
    ): Collection {
        $items = collect();

        if ($actor->can('override-inventory-negative')) {
            $pending = InventoryNegativeRequest::query()
                ->forOrganization($organizationId)
                ->where(
                    'status',
                    InventoryNegativeRequestStatus::Pending->value
                )
                ->with([
                    'requestedBy:id,name',
                    'movement:id,public_id',
                ])
                ->orderBy('requested_at')
                ->orderBy('id')
                ->get();

            foreach ($pending as $request) {
                $items->push($this->item(
                    sourceType: 'inventory_negative_request',
                    sourcePublicId: $request->public_id,
                    state: InventoryNegativeRequestStatus::Pending->value,
                    kind: 'action',
                    severity: 'warning',
                    title: 'Revisar Override de stock',
                    detail: ($request->requestedBy?->name ?? 'Usuario')
                        .' solicita autorización para un movimiento '
                        .'que produciría stock negativo.',
                    url: route(
                        'inventory-negative-authorizations.index',
                        ['status' => 'pending']
                    ),
                    occurredAt: $request->requested_at,
                    priority: 15,
                    acknowledgeable: false
                ));
            }
        }

        if ($actor->can('request-inventory-negative')) {
            $own = InventoryNegativeRequest::query()
                ->forOrganization($organizationId)
                ->where('requested_by_user_id', $actor->id)
                ->whereIn('status', [
                    InventoryNegativeRequestStatus::Approved->value,
                    InventoryNegativeRequestStatus::Rejected->value,
                ])
                ->with([
                    'approvedBy:id,name',
                    'rejectedBy:id,name',
                    'override',
                ])
                ->latest('requested_at')
                ->latest('id')
                ->limit(20)
                ->get();

            foreach ($own as $request) {
                if (
                    $request->status
                    === InventoryNegativeRequestStatus::Approved
                ) {
                    $items->push($this->item(
                        sourceType: 'inventory_negative_request',
                        sourcePublicId: $request->public_id,
                        state: InventoryNegativeRequestStatus::Approved->value,
                        kind: 'action',
                        severity: 'success',
                        title: 'Override autorizado · continuá el movimiento',
                        detail: ($request->approvedBy?->name
                            ?? 'Administración')
                            .' autorizó la excepción de stock negativo.',
                        url: route(
                            'inventory-negative-authorizations.index',
                            ['status' => 'approved']
                        ),
                        occurredAt: $request->approved_at
                            ?? $request->requested_at,
                        priority: 8,
                        acknowledgeable: false
                    ));

                    continue;
                }

                if (
                    $request->status
                    === InventoryNegativeRequestStatus::Rejected
                ) {
                    $detail = 'La solicitud de Override fue rechazada.';

                    if (filled($request->rejection_reason)) {
                        $detail .= ' Motivo: '.$request->rejection_reason;
                    }

                    $items->push($this->item(
                        sourceType: 'inventory_negative_request',
                        sourcePublicId: $request->public_id,
                        state: InventoryNegativeRequestStatus::Rejected->value,
                        kind: 'result',
                        severity: 'danger',
                        title: 'Override de stock rechazado',
                        detail: $detail,
                        url: route(
                            'inventory-negative-authorizations.index',
                            ['status' => 'rejected']
                        ),
                        occurredAt: $request->rejected_at
                            ?? $request->requested_at,
                        priority: 30,
                        acknowledgeable: true
                    ));
                }
            }
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function item(
        string $sourceType,
        string $sourcePublicId,
        string $state,
        string $kind,
        string $severity,
        string $title,
        string $detail,
        string $url,
        mixed $occurredAt,
        int $priority,
        bool $acknowledgeable
    ): array {
        return [
            'key' => hash(
                'sha256',
                implode('|', [
                    $sourceType,
                    $sourcePublicId,
                    $state,
                ])
            ),
            'source_type' => $sourceType,
            'source_public_id' => $sourcePublicId,
            'state' => $state,
            'kind' => $kind,
            'severity' => $severity,
            'title' => $title,
            'detail' => $detail,
            'url' => $url,
            'occurred_at' => $occurredAt,
            'priority' => $priority,
            'acknowledgeable' => $acknowledgeable,
        ];
    }

    /**
     * @param Collection<int, array<string, mixed>> $items
     * @return array{
     *   count: int,
     *   action_count: int,
     *   result_count: int,
     *   items: Collection<int, array<string, mixed>>
     * }
     */
    private function result(Collection $items): array
    {
        return [
            'count' => $items->count(),
            'action_count' => $items->where('kind', 'action')->count(),
            'result_count' => $items->where('kind', 'result')->count(),
            'items' => $items,
        ];
    }

    private function money(int $minor, string $currency): string
    {
        return $currency.' '.number_format(
            $minor / 100,
            2,
            ',',
            '.'
        );
    }
}
