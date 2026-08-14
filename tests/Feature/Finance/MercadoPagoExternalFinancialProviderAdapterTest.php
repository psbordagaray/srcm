<?php

namespace Tests\Feature\Finance;

use App\Adapters\Finance\MercadoPago\MercadoPagoExternalFinancialProviderAdapter;
use App\Enums\FinancialMovementDirection;
use App\Enums\FinancialMovementStatus;
use DomainException;
use Tests\TestCase;

class MercadoPagoExternalFinancialProviderAdapterTest extends TestCase
{
    public function test_processed_point_order_is_normalized_without_provider_specific_leakage(): void
    {
        $adapter = new MercadoPagoExternalFinancialProviderAdapter();

        $observation = $adapter->normalize([
            'id' => 'ORD01JY0PGGPZ4DBV73E2PXRBCQ84',
            'type' => 'point',
            'status' => 'processed',
            'status_detail' => 'accredited',
            'currency' => 'ARS',
            'total_amount' => '120.00',
            'total_paid_amount' => '120.00',
            'version' => 3,
            'last_updated_date' => '2026-08-13T23:15:10.000Z',
            'external_reference' => 'VENTA-PII-NO-DEBE-SALIR',
            'payer' => ['email' => 'cliente@example.com'],
            'transactions' => [
                'payments' => [[
                    'id' => 'PAY01JY0PGGPZ4DBV73E2Q0DQZQCJ',
                    'amount' => '120.00',
                    'paid_amount' => '120.00',
                    'card' => [
                        'first_digits' => '450995',
                        'last_digits' => '3704',
                    ],
                    'token' => 'SENSITIVE_PAYMENT_TOKEN',
                ]],
            ],
        ]);

        $this->assertSame('mercado-pago', $adapter->providerKey());
        $this->assertSame('mercado-pago', $observation->providerKey);
        $this->assertSame(
            'point-order:ORD01JY0PGGPZ4DBV73E2PXRBCQ84:processed:v3',
            $observation->observationKey
        );
        $this->assertSame(
            'ORD01JY0PGGPZ4DBV73E2PXRBCQ84',
            $observation->externalOperationId
        );
        $this->assertSame(FinancialMovementDirection::Credit, $observation->direction);
        $this->assertSame(FinancialMovementStatus::Posted, $observation->status);
        $this->assertSame('ARS', $observation->currencyCode);
        $this->assertSame(12000, $observation->grossAmountMinor);
        $this->assertSame(12000, $observation->netAmountMinor);
        $this->assertSame(0, $observation->feeAmountMinor);
        $this->assertSame(0, $observation->withholdingAmountMinor);
        $this->assertNotNull($observation->occurredAt);
        $this->assertStringNotContainsString(
            'cliente@example.com',
            (string) $observation->rawReference
        );
        $this->assertStringNotContainsString(
            'SENSITIVE_PAYMENT_TOKEN',
            (string) $observation->rawReference
        );
        $this->assertStringNotContainsString(
            'VENTA-PII-NO-DEBE-SALIR',
            (string) $observation->rawReference
        );
        $this->assertStringNotContainsString(
            '450995',
            (string) $observation->rawReference
        );
        $this->assertStringNotContainsString(
            '3704',
            (string) $observation->rawReference
        );
    }

    public function test_full_webhook_envelope_and_api_resource_are_deterministic(): void
    {
        $adapter = new MercadoPagoExternalFinancialProviderAdapter();

        $order = [
            'id' => 'ORD01JYTEST000000000000000001',
            'type' => 'point',
            'status' => 'action_required',
            'status_detail' => 'check_on_terminal',
            'currency' => 'ARS',
            'total_amount' => '5.00',
            'version' => 7,
            'last_updated_date' => '2026-08-13T23:20:00Z',
        ];

        $fromApi = $adapter->normalize($order);
        $fromWebhook = $adapter->normalize([
            'action' => 'order.action_required',
            'api_version' => 'v1',
            'data' => $order,
            'live_mode' => false,
            'type' => 'order',
            'date_created' => '2026-08-13T23:20:01Z',
        ]);

        $this->assertSame(FinancialMovementStatus::Pending, $fromApi->status);
        $this->assertSame($fromApi->observationKey, $fromWebhook->observationKey);
        $this->assertSame($fromApi->externalOperationId, $fromWebhook->externalOperationId);
        $this->assertSame($fromApi->grossAmountMinor, $fromWebhook->grossAmountMinor);
        $this->assertSame($fromApi->currencyCode, $fromWebhook->currencyCode);
    }

    public function test_point_lifecycle_maps_to_provider_neutral_statuses(): void
    {
        $adapter = new MercadoPagoExternalFinancialProviderAdapter();

        $cases = [
            'created' => FinancialMovementStatus::Pending,
            'at_terminal' => FinancialMovementStatus::Pending,
            'action_required' => FinancialMovementStatus::Pending,
            'processed' => FinancialMovementStatus::Posted,
            'refunded' => FinancialMovementStatus::Reversed,
            'canceled' => FinancialMovementStatus::Failed,
            'expired' => FinancialMovementStatus::Failed,
            'failed' => FinancialMovementStatus::Failed,
        ];

        foreach ($cases as $status => $expected) {
            $observation = $adapter->normalize([
                'id' => 'ORD_STATUS_'.strtoupper($status),
                'type' => 'point',
                'status' => $status,
                'currency' => 'ARS',
                'total_amount' => '10.00',
            ]);

            $this->assertSame($expected, $observation->status, $status);
        }
    }

    public function test_amount_can_be_read_from_point_payment_when_order_totals_are_absent(): void
    {
        $adapter = new MercadoPagoExternalFinancialProviderAdapter();

        $observation = $adapter->normalize([
            'id' => 'ORD_PAYMENT_AMOUNT_1',
            'type' => 'point',
            'status' => 'processed',
            'currency' => 'ARS',
            'transactions' => [
                'payments' => [[
                    'amount' => '24.50',
                    'status' => 'processed',
                ]],
            ],
        ]);

        $this->assertSame(2450, $observation->grossAmountMinor);
    }

    public function test_notification_only_unknown_status_and_ambiguous_money_fail_closed(): void
    {
        $adapter = new MercadoPagoExternalFinancialProviderAdapter();

        $this->assertDomainFailure(fn () => $adapter->normalize([
            'action' => 'order.processed',
            'data' => ['id' => 'ORD_ONLY_ID'],
        ]));

        $this->assertDomainFailure(fn () => $adapter->normalize([
            'id' => 'ORD_WRONG_TYPE',
            'type' => 'online',
            'status' => 'processed',
            'currency' => 'ARS',
            'total_amount' => '10.00',
        ]));

        $this->assertDomainFailure(fn () => $adapter->normalize([
            'id' => 'ORD_UNKNOWN_STATUS',
            'type' => 'point',
            'status' => 'magic_status',
            'currency' => 'ARS',
            'total_amount' => '10.00',
        ]));

        $this->assertDomainFailure(fn () => $adapter->normalize([
            'id' => 'ORD_FLOAT',
            'type' => 'point',
            'status' => 'processed',
            'currency' => 'ARS',
            'total_amount' => 10.10,
        ]));

        $this->assertDomainFailure(fn () => $adapter->normalize([
            'id' => 'ORD_BAD_DECIMALS',
            'type' => 'point',
            'status' => 'processed',
            'currency' => 'ARS',
            'total_amount' => '10.123',
        ]));

        $this->assertDomainFailure(fn () => $adapter->normalize([
            'id' => 'ORD_BAD_CURRENCY',
            'type' => 'point',
            'status' => 'processed',
            'currency' => 'PESO',
            'total_amount' => '10.00',
        ]));
    }

    private function assertDomainFailure(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Se esperaba DomainException.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }
    }
}
