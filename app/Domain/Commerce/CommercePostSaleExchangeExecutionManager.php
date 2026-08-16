<?php

namespace App\Domain\Commerce;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Finance\CashRegisterSessionManager;
use App\Domain\Inventory\InventoryMovementConfirmer;
use App\Domain\Inventory\InventoryMovementCreator;
use App\Domain\Inventory\InventoryMovementDraftData;
use App\Domain\Inventory\InventoryMovementLineData;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\CashMovementDirection;
use App\Enums\CashMovementType;
use App\Enums\CommercePaymentMethod;
use App\Enums\CommercePostSaleResolutionOutcome;
use App\Enums\CommerceSaleStatus;
use App\Enums\FinancialAccountType;
use App\Enums\InventoryMovementStatus;
use App\Enums\InventoryMovementType;
use App\Models\BusinessParty;
use App\Models\CashMovement;
use App\Models\CashRegisterSession;
use App\Models\CommercePostSaleExchangeCreditGrant;
use App\Models\CommercePostSaleExchangeExecution;
use App\Models\CommercePostSaleExchangeExecutionLine;
use App\Models\CommercePostSaleExchangePayment;
use App\Models\CommercePostSaleExchangeSelection;
use App\Models\CommercePostSaleExchangeSelectionLine;
use App\Models\FinancialAccount;
use App\Models\InventoryLocation;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

final class CommercePostSaleExchangeExecutionManager
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly InventoryMovementCreator $movementCreator,
        private readonly InventoryMovementConfirmer $movementConfirmer,
        private readonly CashRegisterSessionManager $cashSessions,
        private readonly AuditRecorder $audit,
        private readonly CustomerCreditConsumer $creditConsumer
    ) {
    }

    public function execute(
        CommercePostSaleExchangeSelection $selection,
        CommercePostSaleExchangeExecutionData $data,
        User $actor
    ): CommercePostSaleExchangeExecution {
        $organizationId =
            $this->currentOrganization->id($actor);

        $role =
            $this->currentOrganization->roleFor($actor);

        if (
            ! (
                $role
                    ?->canExecuteCommercePostSaleExchange()
                ?? false
            )
        ) {
            throw new DomainException(
                'No posee permiso para ejecutar cambios de posventa.'
            );
        }

        $normalized = $this->normalize($data);

        return DB::transaction(function () use (
            $selection,
            $normalized,
            $actor,
            $organizationId
        ): CommercePostSaleExchangeExecution {
            $locked =
                CommercePostSaleExchangeSelection::query()
                    ->forOrganization($organizationId)
                    ->whereKey($selection->id)
                    ->lockForUpdate()
                    ->first();

            if (! $locked) {
                throw new DomainException(
                    'La selección de cambio no pertenece a la organización activa.'
                );
            }

            $locked->loadMissing(
                'resolution.request.sale',
                'lines.product'
            );

            $resolution = $locked->resolution;
            $sale = $resolution?->request?->sale;

            if (
                ! $resolution
                || $resolution->outcome
                    !== CommercePostSaleResolutionOutcome::Exchange
                || ! $sale
                || $sale->status
                    !== CommerceSaleStatus::Confirmed
                || $sale->currency_code
                    !== $locked->currency_code
            ) {
                throw new DomainException(
                    'La ejecución requiere una selección ligada a una resolución de cambio y venta confirmada.'
                );
            }

            if (
                $resolution->resolved_by_user_id === null
                || (int) $resolution->resolved_by_user_id
                    === (int) $actor->id
                || $locked->selected_by_user_id === null
                || (int) $locked->selected_by_user_id
                    === (int) $actor->id
            ) {
                throw new DomainException(
                    'Quien autorizó o seleccionó económicamente el cambio no puede ejecutarlo.'
                );
            }

            $selectionLines =
                CommercePostSaleExchangeSelectionLine::query()
                    ->where(
                        'organization_id',
                        $organizationId
                    )
                    ->where(
                        'commerce_post_sale_exchange_selection_id',
                        $locked->id
                    )
                    ->orderBy('sequence')
                    ->lockForUpdate()
                    ->get();

            $selectionLines->load('product');

            if ($selectionLines->isEmpty()) {
                throw new DomainException(
                    'La selección de cambio no posee líneas ejecutables.'
                );
            }

            $replacementAmountMinor = 0;

            foreach ($selectionLines as $line) {
                $amount = (int) $line->line_amount_minor;

                if (
                    $amount <= 0
                    || $replacementAmountMinor
                        > PHP_INT_MAX - $amount
                ) {
                    throw new DomainException(
                        'El valor del reemplazo supera el importe admitido.'
                    );
                }

                $replacementAmountMinor += $amount;
            }

            $recognizedAmountMinor =
                (int) $locked->recognized_amount_minor;

            if ($recognizedAmountMinor <= 0) {
                throw new DomainException(
                    'La selección no conserva valor reconocido válido.'
                );
            }

            $differenceAmountMinor =
                $replacementAmountMinor
                - $recognizedAmountMinor;

            if (
                $differenceAmountMinor < 0
                && $sale->customer_business_party_id === null
            ) {
                throw new DomainException(
                    'Una diferencia a favor del cliente requiere cliente identificado en la venta original.'
                );
            }

            $fingerprint =
                $this->fingerprint([
                    'organization_id' =>
                        $organizationId,
                    'commerce_post_sale_exchange_selection_id' =>
                        (int) $locked->id,
                    'selection_fingerprint' =>
                        (string) $locked->fingerprint,
                    'recognized_amount_minor' =>
                        $recognizedAmountMinor,
                    'replacement_amount_minor' =>
                        $replacementAmountMinor,
                    'difference_amount_minor' =>
                        $differenceAmountMinor,
                    'currency_code' =>
                        $locked->currency_code,
                    'executed_by_user_id' =>
                        (int) $actor->id,
                    'notes' =>
                        $normalized['notes'],
                    'lines' =>
                        $normalized['lines'],
                    'payments' =>
                        $normalized['payments'],
                ]);

            $existingByKey =
                CommercePostSaleExchangeExecution::query()
                    ->forOrganization($organizationId)
                    ->where(
                        'idempotency_key',
                        $normalized['idempotency_key']
                    )
                    ->lockForUpdate()
                    ->first();

            if ($existingByKey) {
                if (
                    ! hash_equals(
                        (string) $existingByKey->fingerprint,
                        $fingerprint
                    )
                ) {
                    throw new DomainException(
                        'La clave idempotente de ejecución de cambio ya fue utilizada con otros hechos.'
                    );
                }

                return $this->loadExecution(
                    $existingByKey
                );
            }

            $existingBySelection =
                CommercePostSaleExchangeExecution::query()
                    ->forOrganization($organizationId)
                    ->where(
                        'commerce_post_sale_exchange_selection_id',
                        $locked->id
                    )
                    ->lockForUpdate()
                    ->first();

            if ($existingBySelection) {
                throw new DomainException(
                    'La selección de cambio ya fue ejecutada con otra operación.'
                );
            }

            $preparedLines =
                $this->prepareLines(
                    $normalized['lines'],
                    $selectionLines,
                    $organizationId
                );

            $preparedPayments =
                $this->preparePayments(
                    $normalized['payments'],
                    $differenceAmountMinor,
                    $locked->currency_code,
                    $actor,
                    $organizationId
                );

            $executedAt =
                CarbonImmutable::now('UTC');

            $movement =
                $this->movementCreator->create(
                    new InventoryMovementDraftData(
                        type:
                            InventoryMovementType::Issue,
                        effectiveAt:
                            $executedAt,
                        reason:
                            'Entrega por cambio posventa '
                            .$locked->public_id.'.',
                        idempotencyKey:
                            'p845:exchange:'
                            .hash(
                                'sha256',
                                $normalized[
                                    'idempotency_key'
                                ]
                            ),
                        lines: array_map(
                            static fn (
                                array $line
                            ): InventoryMovementLineData =>
                                new InventoryMovementLineData(
                                    catalogProductId:
                                        $line[
                                            'catalog_product_id'
                                        ],
                                    condition:
                                        $line['condition'],
                                    enteredQuantity:
                                        $line['quantity'],
                                    enteredUnitCode:
                                        $line[
                                            'base_unit_code'
                                        ],
                                    sourceLocationId:
                                        $line[
                                            'source_location_id'
                                        ],
                                    notes:
                                        'Reemplazo seleccionado en '
                                        .$locked->public_id.'.'
                                ),
                            $preparedLines
                        ),
                        sourceType:
                            'commerce_post_sale_exchange_selection',
                        sourceId:
                            $locked->public_id,
                        sourceReference:
                            'Cambio posventa '
                            .$locked->public_id,
                        metadata: [
                            'commerce_post_sale_exchange_selection_public_id' =>
                                $locked->public_id,
                            'difference_amount_minor' =>
                                $differenceAmountMinor,
                            'currency_code' =>
                                $locked->currency_code,
                        ]
                    ),
                    $actor
                );

            $movement =
                $this->movementConfirmer
                    ->confirm(
                        $movement,
                        $actor
                    )
                    ->load('lines');

            if (
                $movement->status
                    !== InventoryMovementStatus::Confirmed
                || $movement->lines->count()
                    !== count($preparedLines)
            ) {
                throw new DomainException(
                    'La salida de inventario del cambio no quedó confirmada de forma íntegra.'
                );
            }

            $execution =
                CommercePostSaleExchangeExecution::query()
                    ->create([
                        'organization_id' =>
                            $organizationId,
                        'commerce_post_sale_exchange_selection_id' =>
                            $locked->id,
                        'inventory_movement_id' =>
                            $movement->id,
                        'recognized_amount_minor' =>
                            $recognizedAmountMinor,
                        'replacement_amount_minor' =>
                            $replacementAmountMinor,
                        'difference_amount_minor' =>
                            $differenceAmountMinor,
                        'currency_code' =>
                            $locked->currency_code,
                        'executed_by_user_id' =>
                            $actor->id,
                        'executed_at' =>
                            $executedAt,
                        'notes' =>
                            $normalized['notes'],
                        'idempotency_key' =>
                            $normalized['idempotency_key'],
                        'fingerprint' =>
                            $fingerprint,
                        'created_at' =>
                            $executedAt,
                    ]);

            $movementLines =
                $movement->lines
                    ->sortBy('sequence')
                    ->values();

            foreach (
                array_values($preparedLines)
                as $index => $line
            ) {
                $movementLine =
                    $movementLines->get($index);

                if (! $movementLine) {
                    throw new DomainException(
                        'No pudo vincularse una línea de reemplazo con su salida de inventario.'
                    );
                }

                CommercePostSaleExchangeExecutionLine::query()
                    ->create([
                        'organization_id' =>
                            $organizationId,
                        'commerce_post_sale_exchange_execution_id' =>
                            $execution->id,
                        'commerce_post_sale_exchange_selection_line_id' =>
                            $line[
                                'selection_line_id'
                            ],
                        'inventory_movement_line_id' =>
                            $movementLine->id,
                        'sequence' =>
                            $line['sequence'],
                        'source_location_id' =>
                            $line[
                                'source_location_id'
                            ],
                        'condition' =>
                            $line[
                                'condition'
                            ],
                        'created_at' =>
                            $executedAt,
                    ]);
            }

            if ($differenceAmountMinor > 0) {
                $this->recordPositiveDifference(
                    $execution,
                    $preparedPayments,
                    $actor,
                    $executedAt
                );
            } elseif ($differenceAmountMinor < 0) {
                $this->grantNegativeDifference(
                    $execution,
                    (int) $sale
                        ->customer_business_party_id,
                    -$differenceAmountMinor,
                    $actor,
                    $executedAt
                );
            }

            $this->audit->record(
                $execution,
                'commerce_post_sale_exchange_executed',
                null,
                [
                    'commerce_post_sale_exchange_selection_id' =>
                        (int) $locked->id,
                    'commerce_post_sale_resolution_id' =>
                        (int) $resolution->id,
                    'commerce_sale_id' =>
                        (int) $sale->id,
                    'inventory_movement_id' =>
                        (int) $movement->id,
                    'recognized_amount_minor' =>
                        $recognizedAmountMinor,
                    'replacement_amount_minor' =>
                        $replacementAmountMinor,
                    'difference_amount_minor' =>
                        $differenceAmountMinor,
                    'currency_code' =>
                        $locked->currency_code,
                    'payment_count' =>
                        count($preparedPayments),
                    'account_credit_consumption_count' =>
                        collect($preparedPayments)
                            ->filter(
                                fn (array $payment): bool =>
                                    $payment['method']
                                        === CommercePaymentMethod::AccountCredit
                            )
                            ->count(),
                    'credit_amount_minor' =>
                        $differenceAmountMinor < 0
                            ? -$differenceAmountMinor
                            : 0,
                ]
            );

            return $this->loadExecution(
                $execution->refresh()
            );
        }, 3);
    }

    private function prepareLines(
        array $normalizedLines,
        $selectionLines,
        int $organizationId
    ): array {
        if (
            count($normalizedLines)
                !== $selectionLines->count()
        ) {
            throw new DomainException(
                'La ejecución debe indicar origen y condición para todas las líneas seleccionadas.'
            );
        }

        $byId =
            $selectionLines->keyBy('id');

        $locationIds =
            collect($normalizedLines)
                ->pluck('source_location_id')
                ->unique()
                ->values();

        $locations =
            InventoryLocation::query()
                ->forOrganization($organizationId)
                ->whereIn(
                    'id',
                    $locationIds->all()
                )
                ->where('active', true)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

        if (
            $locations->count()
                !== $locationIds->count()
        ) {
            throw new DomainException(
                'Una ubicación de origen no está activa o no pertenece a la organización.'
            );
        }

        $prepared = [];

        foreach ($normalizedLines as $line) {
            $selected =
                $byId->get(
                    $line['selection_line_id']
                );

            $product = $selected?->product;

            if (
                ! $selected
                || ! $product
                || ! $product->active
            ) {
                throw new DomainException(
                    'Una línea ejecutada no pertenece a la selección o su producto ya no está activo.'
                );
            }

            $prepared[] = [
                'selection_line_id' =>
                    (int) $selected->id,
                'sequence' =>
                    (int) $selected->sequence,
                'catalog_product_id' =>
                    (int) $selected
                        ->catalog_product_id,
                'source_location_id' =>
                    $line['source_location_id'],
                'condition' =>
                    $line['condition'],
                'quantity' =>
                    (string) $selected->quantity,
                'base_unit_code' =>
                    (string) $product
                        ->base_unit_code,
            ];
        }

        usort(
            $prepared,
            static fn (
                array $left,
                array $right
            ): int =>
                $left['sequence']
                <=>
                $right['sequence']
        );

        return $prepared;
    }

    private function preparePayments(
        array $payments,
        int $differenceAmountMinor,
        string $currencyCode,
        User $actor,
        int $organizationId
    ): array {
        if ($differenceAmountMinor <= 0) {
            if ($payments !== []) {
                throw new DomainException(
                    'Un cambio sin diferencia a cobrar no admite pagos.'
                );
            }

            return [];
        }

        if ($payments === []) {
            throw new DomainException(
                'La diferencia positiva del cambio debe cobrarse explícitamente.'
            );
        }

        $total = 0;

        foreach ($payments as $payment) {
            $amount =
                (int) $payment[
                    'amount_minor'
                ];

            if (
                $amount <= 0
                || $total > PHP_INT_MAX - $amount
            ) {
                throw new DomainException(
                    'Los pagos de la diferencia contienen un importe inválido.'
                );
            }

            $total += $amount;
        }

        if ($total !== $differenceAmountMinor) {
            throw new DomainException(
                'Los pagos deben cubrir exactamente la diferencia positiva del cambio.'
            );
        }

        $ids =
            collect($payments)
                ->reject(
                    fn (array $payment): bool =>
                        $payment['method']
                            === CommercePaymentMethod::AccountCredit
                )
                ->pluck('financial_account_id')
                ->map(
                    fn (mixed $id): int =>
                        (int) $id
                )
                ->unique()
                ->values();

        $accounts =
            FinancialAccount::query()
                ->forOrganization($organizationId)
                ->whereIn('id', $ids->all())
                ->where('active', true)
                ->where(
                    'currency_code',
                    $currencyCode
                )
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

        if ($accounts->count() !== $ids->count()) {
            throw new DomainException(
                'Una cuenta de cobro no está activa, no pertenece a la organización o usa otra moneda.'
            );
        }

        $cashPayments =
            collect($payments)
                ->filter(
                    fn (array $payment): bool =>
                        $payment['method']
                            === CommercePaymentMethod::Cash
                );

        $cashSession = null;

        if ($cashPayments->isNotEmpty()) {
            $cashSession =
                $this->cashSessions
                    ->lockCurrentFor($actor);

            $this->assertCashSession(
                $cashSession,
                $actor,
                $organizationId,
                $currencyCode
            );
        }

        $prepared = [];
        $sequence = 1;

        foreach ($payments as $payment) {
            if (
                $payment['method']
                    === CommercePaymentMethod::AccountCredit
            ) {
                $prepared[] = [
                    ...$payment,
                    'sequence' =>
                        $sequence++,
                    'cash_register_session_id' =>
                        null,
                    'cash_register_id' =>
                        null,
                ];

                continue;
            }

            $account =
                $accounts->get(
                    $payment[
                        'financial_account_id'
                    ]
                );

            if (! $account) {
                throw new DomainException(
                    'No pudo resolverse una cuenta de cobro.'
                );
            }

            if (
                $payment['method']
                    === CommercePaymentMethod::Cash
            ) {
                $sessionAccountId =
                    $cashSession
                        ?->register
                        ?->financialAccount
                        ?->id;

                if (
                    (int) $sessionAccountId
                        !== (int) $account->id
                    || $account->type
                        !== FinancialAccountType::CashBox
                ) {
                    throw new DomainException(
                        'El efectivo debe ingresar en la cuenta de la caja del turno propio abierto.'
                    );
                }
            } elseif (
                in_array(
                    $account->type,
                    [
                        FinancialAccountType::CashBox,
                        FinancialAccountType::CashReserve,
                    ],
                    true
                )
            ) {
                throw new DomainException(
                    'Un pago no efectivo no puede registrarse contra una cuenta física de caja o reserva.'
                );
            }

            $prepared[] = [
                ...$payment,
                'sequence' => $sequence++,
                'cash_register_session_id' =>
                    $payment['method']
                        === CommercePaymentMethod::Cash
                            ? $cashSession?->id
                            : null,
                'cash_register_id' =>
                    $payment['method']
                        === CommercePaymentMethod::Cash
                            ? $cashSession
                                ?->cash_register_id
                            : null,
            ];
        }

        return $prepared;
    }

    private function assertCashSession(
        ?CashRegisterSession $session,
        User $actor,
        int $organizationId,
        string $currencyCode
    ): void {
        $register =
            $session?->register;

        $account =
            $register?->financialAccount;

        if (
            ! $session
            || (int) $session->organization_id
                !== $organizationId
            || $session->currency_code
                !== $currencyCode
            || (int) $session->opened_by_user_id
                !== (int) $actor->id
            || ! $register
            || ! $register->active
            || (int) $register->organization_id
                !== $organizationId
            || ! $account
            || ! $account->active
            || (int) $account->organization_id
                !== $organizationId
            || $account->type
                !== FinancialAccountType::CashBox
            || $account->currency_code
                !== $currencyCode
        ) {
            throw new DomainException(
                'Para cobrar la diferencia en efectivo debe existir un turno propio compatible y abierto.'
            );
        }
    }

    private function recordPositiveDifference(
        CommercePostSaleExchangeExecution $execution,
        array $payments,
        User $actor,
        CarbonImmutable $executedAt
    ): void {
        foreach ($payments as $paymentData) {
            if (
                $paymentData['method']
                    === CommercePaymentMethod::AccountCredit
            ) {
                $consumption =
                    $this->creditConsumer
                        ->consumeForExchangePayment(
                            $execution,
                            (int) $paymentData[
                                'sequence'
                            ],
                            (int) $paymentData[
                                'amount_minor'
                            ],
                            'p857:exchange-credit:'
                            .hash(
                                'sha256',
                                $execution
                                    ->idempotency_key
                                .'|'
                                .$paymentData[
                                    'sequence'
                                ]
                            ),
                            $actor
                        );

                $this->audit->record(
                    $consumption,
                    'commerce_post_sale_exchange_account_credit_consumed',
                    null,
                    [
                        'commerce_post_sale_exchange_execution_id' =>
                            (int) $execution->id,
                        'payment_position' =>
                            (int) $paymentData[
                                'sequence'
                            ],
                        'business_party_id' =>
                            (int) $consumption
                                ->business_party_id,
                        'amount_minor' =>
                            (int) $consumption
                                ->amount_minor,
                        'currency_code' =>
                            $execution
                                ->currency_code,
                    ]
                );

                continue;
            }

            $paymentFingerprint =
                $this->fingerprint([
                    'commerce_post_sale_exchange_execution_id' =>
                        (int) $execution->id,
                    'sequence' =>
                        $paymentData['sequence'],
                    'financial_account_id' =>
                        $paymentData[
                            'financial_account_id'
                        ],
                    'method' =>
                        $paymentData[
                            'method'
                        ]->value,
                    'amount_minor' =>
                        $paymentData[
                            'amount_minor'
                        ],
                    'tendered_amount_minor' =>
                        $paymentData[
                            'tendered_amount_minor'
                        ],
                    'change_amount_minor' =>
                        $paymentData[
                            'change_amount_minor'
                        ],
                    'reference' =>
                        $paymentData['reference'],
                    'card_brand' =>
                        $paymentData['card_brand'],
                    'card_network' =>
                        $paymentData[
                            'card_network'
                        ],
                    'card_last4' =>
                        $paymentData['card_last4'],
                    'installments' =>
                        $paymentData[
                            'installments'
                        ],
                    'processor' =>
                        $paymentData['processor'],
                    'external_operation_id' =>
                        $paymentData[
                            'external_operation_id'
                        ],
                    'authorization_code' =>
                        $paymentData[
                            'authorization_code'
                        ],
                    'provider_status' =>
                        $paymentData[
                            'provider_status'
                        ],
                    'notes' =>
                        $paymentData['notes'],
                    'received_by_user_id' =>
                        (int) $actor->id,
                    'paid_at' =>
                        $paymentData['paid_at']
                            ?? $executedAt,
                ]);

            $payment =
                CommercePostSaleExchangePayment::query()
                    ->create([
                        'organization_id' =>
                            $execution
                                ->organization_id,
                        'commerce_post_sale_exchange_execution_id' =>
                            $execution->id,
                        'sequence' =>
                            $paymentData[
                                'sequence'
                            ],
                        'financial_account_id' =>
                            $paymentData[
                                'financial_account_id'
                            ],
                        'cash_register_session_id' =>
                            $paymentData[
                                'cash_register_session_id'
                            ],
                        'cash_register_id' =>
                            $paymentData[
                                'cash_register_id'
                            ],
                        'method' =>
                            $paymentData[
                                'method'
                            ],
                        'amount_minor' =>
                            $paymentData[
                                'amount_minor'
                            ],
                        'tendered_amount_minor' =>
                            $paymentData[
                                'tendered_amount_minor'
                            ],
                        'change_amount_minor' =>
                            $paymentData[
                                'change_amount_minor'
                            ],
                        'reference' =>
                            $paymentData['reference'],
                        'card_brand' =>
                            $paymentData[
                                'card_brand'
                            ],
                        'card_network' =>
                            $paymentData[
                                'card_network'
                            ],
                        'card_last4' =>
                            $paymentData[
                                'card_last4'
                            ],
                        'installments' =>
                            $paymentData[
                                'installments'
                            ],
                        'processor' =>
                            $paymentData[
                                'processor'
                            ],
                        'external_operation_id' =>
                            $paymentData[
                                'external_operation_id'
                            ],
                        'authorization_code' =>
                            $paymentData[
                                'authorization_code'
                            ],
                        'provider_status' =>
                            $paymentData[
                                'provider_status'
                            ],
                        'notes' =>
                            $paymentData['notes'],
                        'received_by_user_id' =>
                            $actor->id,
                        'paid_at' =>
                            $paymentData['paid_at']
                                ?? $executedAt,
                        'fingerprint' =>
                            $paymentFingerprint,
                        'created_at' =>
                            $executedAt,
                    ]);

            $this->audit->record(
                $payment,
                'commerce_post_sale_exchange_difference_collected',
                null,
                [
                    'commerce_post_sale_exchange_execution_id' =>
                        (int) $execution->id,
                    'sequence' =>
                        (int) $payment->sequence,
                    'financial_account_id' =>
                        (int) $payment
                            ->financial_account_id,
                    'method' =>
                        $payment->method,
                    'amount_minor' =>
                        (int) $payment
                            ->amount_minor,
                    'currency_code' =>
                        $execution->currency_code,
                ]
            );

            if (
                $payment->method
                    === CommercePaymentMethod::Cash
            ) {
                $this->recordCashDifference(
                    $execution,
                    $payment,
                    $actor,
                    $executedAt
                );
            }
        }
    }

    private function recordCashDifference(
        CommercePostSaleExchangeExecution $execution,
        CommercePostSaleExchangePayment $payment,
        User $actor,
        CarbonImmutable $executedAt
    ): void {
        $cashFingerprint =
            $this->fingerprint([
                'organization_id' =>
                    (int) $execution
                        ->organization_id,
                'post_sale_exchange_payment_id' =>
                    (int) $payment->id,
                'cash_register_session_id' =>
                    (int) $payment
                        ->cash_register_session_id,
                'cash_register_id' =>
                    (int) $payment
                        ->cash_register_id,
                'financial_account_id' =>
                    (int) $payment
                        ->financial_account_id,
                'amount_minor' =>
                    (int) $payment
                        ->amount_minor,
                'currency_code' =>
                    $execution
                        ->currency_code,
                'recorded_by_user_id' =>
                    (int) $actor->id,
            ]);

        $movement =
            CashMovement::query()->create([
                'organization_id' =>
                    $execution->organization_id,
                'cash_register_session_id' =>
                    $payment
                        ->cash_register_session_id,
                'cash_register_id' =>
                    $payment
                        ->cash_register_id,
                'financial_account_id' =>
                    $payment
                        ->financial_account_id,
                'post_sale_exchange_payment_id' =>
                    $payment->id,
                'direction' =>
                    CashMovementDirection::In,
                'type' =>
                    CashMovementType::PostSaleExchangeDifference,
                'amount_minor' =>
                    $payment->amount_minor,
                'currency_code' =>
                    $execution->currency_code,
                'idempotency_key' =>
                    'post-sale-exchange-payment:'
                    .$payment->id,
                'fingerprint' =>
                    $cashFingerprint,
                'recorded_by_user_id' =>
                    $actor->id,
                'occurred_at' =>
                    $executedAt,
                'created_at' =>
                    $executedAt,
            ]);

        $this->audit->record(
            $movement,
            'cash_movement_recorded',
            null,
            [
                'cash_register_session_id' =>
                    (int) $payment
                        ->cash_register_session_id,
                'cash_register_id' =>
                    (int) $payment
                        ->cash_register_id,
                'financial_account_id' =>
                    (int) $payment
                        ->financial_account_id,
                'post_sale_exchange_payment_id' =>
                    (int) $payment->id,
                'direction' =>
                    CashMovementDirection::In,
                'type' =>
                    CashMovementType::PostSaleExchangeDifference,
                'amount_minor' =>
                    (int) $payment
                        ->amount_minor,
                'currency_code' =>
                    $execution->currency_code,
            ]
        );
    }

    private function grantNegativeDifference(
        CommercePostSaleExchangeExecution $execution,
        int $partyId,
        int $amountMinor,
        User $actor,
        CarbonImmutable $executedAt
    ): void {
        $party =
            BusinessParty::query()
                ->forOrganization(
                    $execution->organization_id
                )
                ->whereKey($partyId)
                ->lockForUpdate()
                ->first();

        if (! $party) {
            throw new DomainException(
                'El cliente original no pertenece a la organización activa.'
            );
        }

        $fingerprint =
            $this->fingerprint([
                'commerce_post_sale_exchange_execution_id' =>
                    (int) $execution->id,
                'business_party_id' =>
                    (int) $party->id,
                'amount_minor' =>
                    $amountMinor,
                'currency_code' =>
                    $execution->currency_code,
                'granted_by_user_id' =>
                    (int) $actor->id,
            ]);

        $grant =
            CommercePostSaleExchangeCreditGrant::query()
                ->create([
                    'organization_id' =>
                        $execution->organization_id,
                    'commerce_post_sale_exchange_execution_id' =>
                        $execution->id,
                    'business_party_id' =>
                        $party->id,
                    'amount_minor' =>
                        $amountMinor,
                    'currency_code' =>
                        $execution->currency_code,
                    'granted_by_user_id' =>
                        $actor->id,
                    'granted_at' =>
                        $executedAt,
                    'fingerprint' =>
                        $fingerprint,
                    'created_at' =>
                        $executedAt,
                ]);

        $this->audit->record(
            $grant,
            'commerce_post_sale_exchange_credit_granted',
            null,
            [
                'commerce_post_sale_exchange_execution_id' =>
                    (int) $execution->id,
                'business_party_id' =>
                    (int) $party->id,
                'amount_minor' =>
                    $amountMinor,
                'currency_code' =>
                    $execution->currency_code,
            ]
        );
    }

    private function normalize(
        CommercePostSaleExchangeExecutionData $data
    ): array {
        $idempotencyKey =
            Str::of($data->idempotencyKey)
                ->squish()
                ->toString();

        if (
            $idempotencyKey === ''
            || mb_strlen($idempotencyKey) > 180
        ) {
            throw new DomainException(
                'La clave idempotente de ejecución de cambio no es válida.'
            );
        }

        $notes =
            filled($data->notes)
                ? Str::of(
                    (string) $data->notes
                )
                    ->squish()
                    ->toString()
                : null;

        if (
            $notes !== null
            && mb_strlen($notes) > 2000
        ) {
            throw new DomainException(
                'La nota de ejecución de cambio supera la longitud admitida.'
            );
        }

        if ($data->lines === []) {
            throw new DomainException(
                'La ejecución de cambio requiere líneas de reemplazo.'
            );
        }

        $lines = [];
        $seenLines = [];

        foreach ($data->lines as $line) {
            if (
                ! $line
                    instanceof CommercePostSaleExchangeExecutionLineData
                || $line
                    ->commercePostSaleExchangeSelectionLineId
                    <= 0
                || $line->sourceLocationId <= 0
                || isset(
                    $seenLines[
                        $line
                            ->commercePostSaleExchangeSelectionLineId
                    ]
                )
            ) {
                throw new DomainException(
                    'Una línea de ejecución de cambio contiene referencias inválidas o duplicadas.'
                );
            }

            $seenLines[
                $line
                    ->commercePostSaleExchangeSelectionLineId
            ] = true;

            $lines[] = [
                'selection_line_id' =>
                    $line
                        ->commercePostSaleExchangeSelectionLineId,
                'source_location_id' =>
                    $line->sourceLocationId,
                'condition' =>
                    $line->condition,
            ];
        }

        usort(
            $lines,
            static fn (
                array $left,
                array $right
            ): int =>
                $left['selection_line_id']
                <=>
                $right['selection_line_id']
        );

        $payments = [];

        foreach ($data->payments as $payment) {
            if (! $payment instanceof CommercePaymentData) {
                throw new DomainException(
                    'Los pagos de la diferencia de cambio no son válidos.'
                );
            }

            $payments[] =
                $this->normalizePayment(
                    $payment
                );
        }

        return [
            'lines' => $lines,
            'payments' => $payments,
            'idempotency_key' =>
                $idempotencyKey,
            'notes' => $notes,
        ];
    }

    private function normalizePayment(
        CommercePaymentData $payment
    ): array {
        if ($payment->amountMinor <= 0) {
            throw new DomainException(
                'Cada pago de diferencia requiere un importe positivo.'
            );
        }

        if (
            $payment->method
                === CommercePaymentMethod::AccountCredit
        ) {
            if (
                $payment->financialAccountId !== null
                || filled($payment->reference)
                || $payment->tenderedAmountMinor !== null
                || filled($payment->cardBrand)
                || filled($payment->cardNetwork)
                || filled($payment->cardLast4)
                || $payment->installments !== null
                || filled($payment->processor)
                || filled($payment->externalOperationId)
                || filled($payment->authorizationCode)
                || filled($payment->providerStatus)
                || $payment->paidAt !== null
            ) {
                throw new DomainException(
                    'El saldo a favor no admite cuenta financiera, referencia, efectivo ni evidencia de proveedor.'
                );
            }

            return [
                'financial_account_id' =>
                    null,
                'method' =>
                    $payment->method,
                'amount_minor' =>
                    $payment->amountMinor,
                'tendered_amount_minor' =>
                    null,
                'change_amount_minor' =>
                    null,
                'reference' =>
                    null,
                'card_brand' =>
                    null,
                'card_network' =>
                    null,
                'card_last4' =>
                    null,
                'installments' =>
                    null,
                'processor' =>
                    null,
                'external_operation_id' =>
                    null,
                'authorization_code' =>
                    null,
                'provider_status' =>
                    null,
                'notes' =>
                    $this->paymentText(
                        $payment->notes,
                        2000,
                        'Las notas del pago'
                    ),
                'paid_at' =>
                    null,
            ];
        }

        if (
            $payment->financialAccountId === null
            || $payment->financialAccountId <= 0
        ) {
            throw new DomainException(
                'Cada pago convencional de diferencia requiere una cuenta financiera válida.'
            );
        }

        $reference =
            $this->paymentText(
                $payment->reference,
                255,
                'La referencia del pago'
            );

        $notes =
            $this->paymentText(
                $payment->notes,
                2000,
                'Las notas del pago'
            );

        $cardBrand =
            $this->paymentText(
                $payment->cardBrand,
                50,
                'La marca de tarjeta'
            );

        $cardNetwork =
            $this->paymentText(
                $payment->cardNetwork,
                50,
                'La red de tarjeta'
            );

        $cardLast4 =
            $this->paymentText(
                $payment->cardLast4,
                4,
                'Los últimos 4 de tarjeta'
            );

        $processor =
            $this->paymentText(
                $payment->processor,
                100,
                'El procesador del pago'
            );

        $externalOperationId =
            $this->paymentText(
                $payment->externalOperationId,
                191,
                'La operación externa'
            );

        $authorizationCode =
            $this->paymentText(
                $payment->authorizationCode,
                100,
                'El código de autorización'
            );

        $providerStatus =
            $this->paymentText(
                $payment->providerStatus,
                50,
                'El estado informado por el proveedor'
            );

        if (
            $payment->method
                ->requiresReference()
            && $reference === null
        ) {
            throw new DomainException(
                'El medio de pago no efectivo requiere una referencia.'
            );
        }

        $isCard =
            in_array(
                $payment->method,
                [
                    CommercePaymentMethod::DebitCard,
                    CommercePaymentMethod::CreditCard,
                ],
                true
            );

        if (
            $cardLast4 !== null
            && preg_match(
                '/^\d{4}$/D',
                $cardLast4
            ) !== 1
        ) {
            throw new DomainException(
                'Los últimos 4 de tarjeta deben contener exactamente cuatro dígitos.'
            );
        }

        if (
            $payment->installments !== null
            && (
                $payment->installments < 1
                || $payment->installments > 120
            )
        ) {
            throw new DomainException(
                'La cantidad de cuotas debe estar entre 1 y 120.'
            );
        }

        if (
            ! $isCard
            && (
                $cardBrand !== null
                || $cardNetwork !== null
                || $cardLast4 !== null
                || $payment->installments !== null
            )
        ) {
            throw new DomainException(
                'Los datos de tarjeta sólo pueden asociarse a un pago con tarjeta.'
            );
        }

        $tenderedAmountMinor =
            $payment->tenderedAmountMinor;

        $changeAmountMinor = null;

        if (
            $payment->method
                === CommercePaymentMethod::Cash
        ) {
            if (
                $tenderedAmountMinor !== null
                && $tenderedAmountMinor
                    < $payment->amountMinor
            ) {
                throw new DomainException(
                    'El efectivo entregado no puede ser menor que el importe aplicado.'
                );
            }

            if ($tenderedAmountMinor !== null) {
                $changeAmountMinor =
                    $tenderedAmountMinor
                    - $payment->amountMinor;
            }

            if (
                $processor !== null
                || $externalOperationId !== null
                || $authorizationCode !== null
                || $providerStatus !== null
            ) {
                throw new DomainException(
                    'El efectivo no admite evidencia de procesador u operación externa.'
                );
            }
        } elseif ($tenderedAmountMinor !== null) {
            throw new DomainException(
                'Sólo el efectivo admite dinero entregado y vuelto.'
            );
        }

        return [
            'financial_account_id' =>
                (int) $payment
                    ->financialAccountId,
            'method' =>
                $payment->method,
            'amount_minor' =>
                $payment->amountMinor,
            'tendered_amount_minor' =>
                $tenderedAmountMinor,
            'change_amount_minor' =>
                $changeAmountMinor,
            'reference' =>
                $reference,
            'card_brand' =>
                $cardBrand,
            'card_network' =>
                $cardNetwork,
            'card_last4' =>
                $cardLast4,
            'installments' =>
                $payment->installments,
            'processor' =>
                $processor,
            'external_operation_id' =>
                $externalOperationId,
            'authorization_code' =>
                $authorizationCode,
            'provider_status' =>
                $providerStatus,
            'notes' =>
                $notes,
            'paid_at' =>
                $payment->paidAt
                    ? CarbonImmutable::instance(
                        $payment->paidAt
                    )
                    : null,
        ];
    }

    private function paymentText(
        ?string $value,
        int $maxLength,
        string $label
    ): ?string {
        $value =
            $value === null
                ? null
                : Str::of($value)
                    ->squish()
                    ->toString();

        if ($value === '') {
            $value = null;
        }

        if (
            $value !== null
            && mb_strlen($value) > $maxLength
        ) {
            throw new DomainException(
                $label.' supera la longitud admitida.'
            );
        }

        return $value;
    }

    private function loadExecution(
        CommercePostSaleExchangeExecution $execution
    ): CommercePostSaleExchangeExecution {
        return $execution->load([
            'selection.resolution.request.sale',
            'inventoryMovement.lines',
            'executedBy',
            'lines.selectionLine.product',
            'lines.inventoryMovementLine',
            'lines.sourceLocation',
            'payments.financialAccount',
            'payments.cashMovement',
            'creditConsumptions.allocations',
            'creditGrant.party',
        ]);
    }

    private function fingerprint(
        array $source
    ): string {
        try {
            return hash(
                'sha256',
                json_encode(
                    $this->fingerprintable(
                        $source
                    ),
                    JSON_THROW_ON_ERROR
                        | JSON_UNESCAPED_UNICODE
                        | JSON_UNESCAPED_SLASHES
                )
            );
        } catch (JsonException $exception) {
            throw new DomainException(
                'No se pudo construir la huella de ejecución del cambio.',
                previous: $exception
            );
        }
    }

    private function fingerprintable(
        mixed $value
    ): mixed {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance(
                $value
            )->toIso8601String();
        }

        if (! is_array($value)) {
            return $value;
        }

        $normalized = [];

        foreach ($value as $key => $item) {
            $normalized[$key] =
                $this->fingerprintable($item);
        }

        return $normalized;
    }
}
