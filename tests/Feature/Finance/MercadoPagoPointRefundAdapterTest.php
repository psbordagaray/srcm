<?php

namespace Tests\Feature\Finance;

use App\Adapters\Finance\MercadoPago\MercadoPagoConnectionSecrets;
use App\Adapters\Finance\MercadoPago\MercadoPagoPointOrdersClient;
use App\Adapters\Finance\MercadoPago\MercadoPagoPointRefundAdapter;
use App\Contracts\Finance\MercadoPagoConnectionSecretStore;
use App\Domain\Finance\FinancialProviderRefundRequest;
use App\Enums\FinancialMovementDirection;
use App\Enums\FinancialMovementStatus;
use App\Models\FinancialProviderConnection;
use DomainException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MercadoPagoPointRefundAdapterTest extends TestCase
{
    public function test_partial_refund_uses_point_order_transaction_and_normalizes_posted_debit(): void
    {
        Http::fake([
            'https://api.mercadopago.com/v1/orders/ORD_P8433_PARTIAL'
                => Http::response([
                    'id' => 'ORD_P8433_PARTIAL',
                    'type' => 'point',
                    'status' => 'processed',
                    'status_detail' => 'processed',
                    'country_code' => 'ARG',
                    'transactions' => [
                        'payments' => [[
                            'id' => 'PAY_P8433_PARTIAL',
                            'amount' => '20.00',
                            'paid_amount' => '20.00',
                            'status' => 'processed',
                        ]],
                    ],
                ], 200),
            'https://api.mercadopago.com/v1/orders/ORD_P8433_PARTIAL/refund'
                => Http::response([
                    'id' => 'ORD_P8433_PARTIAL',
                    'status' => 'processed',
                    'status_detail' => 'partially_refunded',
                    'transactions' => [
                        'refunds' => [[
                            'id' => 'REF_P8433_PARTIAL',
                            'transaction_id' => 'PAY_P8433_PARTIAL',
                            'reference_id' => 'SAFE-REF',
                            'amount' => '10.00',
                            'status' => 'processed',
                        ]],
                    ],
                ], 201),
        ]);

        $adapter =
            new MercadoPagoPointRefundAdapter(
                $this->secretStore(),
                new MercadoPagoPointOrdersClient()
            );

        $observation =
            $adapter->submitRefund(
                $this->connection(),
                new FinancialProviderRefundRequest(
                    instructionPublicId:
                        '11111111-2222-4333-8444-555555555555',
                    originalExternalOperationId:
                        'ORD_P8433_PARTIAL',
                    amountMinor:
                        1000,
                    currencyCode:
                        'ARS',
                    providerIdempotencyKey:
                        'srcm-refund:11111111-2222-4333-8444-555555555555'
                )
            );

        $this->assertSame(
            'mercado-pago',
            $observation->providerKey
        );

        $this->assertSame(
            'REF_P8433_PARTIAL',
            $observation->externalOperationId
        );

        $this->assertSame(
            FinancialMovementDirection::Debit,
            $observation->direction
        );

        $this->assertSame(
            FinancialMovementStatus::Posted,
            $observation->status
        );

        $this->assertSame(
            1000,
            $observation->grossAmountMinor
        );

        Http::assertSent(function ($request): bool {
            if (
                $request->url()
                    !== 'https://api.mercadopago.com/v1/orders/ORD_P8433_PARTIAL/refund'
            ) {
                return true;
            }

            return $request->method() === 'POST'
                && $request->hasHeader(
                    'Authorization',
                    'Bearer TEST_REFUND_TOKEN'
                )
                && $request->hasHeader(
                    'X-Idempotency-Key',
                    'srcm-refund:11111111-2222-4333-8444-555555555555'
                )
                && ($request->data()['transactions'][0]['id'] ?? null)
                    === 'PAY_P8433_PARTIAL'
                && ($request->data()['transactions'][0]['amount'] ?? null)
                    === '10.00';
        });
    }

    public function test_total_refund_sends_empty_body_and_processing_is_pending(): void
    {
        Http::fake([
            'https://api.mercadopago.com/v1/orders/ORD_P8433_TOTAL'
                => Http::response([
                    'id' => 'ORD_P8433_TOTAL',
                    'type' => 'point',
                    'status' => 'processed',
                    'country_code' => 'ARG',
                    'transactions' => [
                        'payments' => [[
                            'id' => 'PAY_P8433_TOTAL',
                            'paid_amount' => '10.00',
                            'status' => 'processed',
                        ]],
                    ],
                ], 200),
            'https://api.mercadopago.com/v1/orders/ORD_P8433_TOTAL/refund'
                => Http::response([
                    'id' => 'ORD_P8433_TOTAL',
                    'status' => 'refunded',
                    'status_detail' => 'refunded',
                    'transactions' => [
                        'refunds' => [[
                            'id' => 'REF_P8433_TOTAL',
                            'transaction_id' => 'PAY_P8433_TOTAL',
                            'reference_id' => 'SAFE-REF',
                            'amount' => '10.00',
                            'status' => 'processing',
                        ]],
                    ],
                ], 201),
        ]);

        $adapter =
            new MercadoPagoPointRefundAdapter(
                $this->secretStore(),
                new MercadoPagoPointOrdersClient()
            );

        $observation =
            $adapter->submitRefund(
                $this->connection(),
                new FinancialProviderRefundRequest(
                    instructionPublicId:
                        'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
                    originalExternalOperationId:
                        'ORD_P8433_TOTAL',
                    amountMinor:
                        1000,
                    currencyCode:
                        'ARS',
                    providerIdempotencyKey:
                        'srcm-refund:aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'
                )
            );

        $this->assertSame(
            FinancialMovementStatus::Pending,
            $observation->status
        );

        Http::assertSent(function ($request): bool {
            if (
                $request->url()
                    !== 'https://api.mercadopago.com/v1/orders/ORD_P8433_TOTAL/refund'
            ) {
                return true;
            }

            return $request->method() === 'POST'
                && $request->data() === [];
        });
    }

    public function test_refund_response_must_identify_exact_transaction_amount_and_documented_status(): void
    {
        Http::fake([
            'https://api.mercadopago.com/v1/orders/ORD_P8433_BAD'
                => Http::response([
                    'id' => 'ORD_P8433_BAD',
                    'type' => 'point',
                    'status' => 'processed',
                    'country_code' => 'ARG',
                    'transactions' => [
                        'payments' => [[
                            'id' => 'PAY_P8433_BAD',
                            'paid_amount' => '10.00',
                            'status' => 'processed',
                        ]],
                    ],
                ], 200),
            'https://api.mercadopago.com/v1/orders/ORD_P8433_BAD/refund'
                => Http::response([
                    'id' => 'ORD_P8433_BAD',
                    'status' => 'refunded',
                    'status_detail' => 'refunded',
                    'transactions' => [
                        'refunds' => [[
                            'id' => 'REF_P8433_BAD',
                            'transaction_id' => 'OTHER_PAYMENT',
                            'amount' => '10.00',
                            'status' => 'processed',
                        ]],
                    ],
                ], 201),
        ]);

        $adapter =
            new MercadoPagoPointRefundAdapter(
                $this->secretStore(),
                new MercadoPagoPointOrdersClient()
            );

        $this->assertDomainFailure(
            fn () => $adapter->submitRefund(
                $this->connection(),
                new FinancialProviderRefundRequest(
                    instructionPublicId:
                        '12345678-1234-4234-8234-123456789abc',
                    originalExternalOperationId:
                        'ORD_P8433_BAD',
                    amountMinor:
                        1000,
                    currencyCode:
                        'ARS',
                    providerIdempotencyKey:
                        'srcm-refund:12345678-1234-4234-8234-123456789abc'
                )
            )
        );
    }

    public function test_polling_normalization_requires_unique_refund_id_and_preserves_append_only_status_identity(): void
    {
        $adapter =
            new MercadoPagoPointRefundAdapter(
                $this->secretStore(),
                new MercadoPagoPointOrdersClient()
            );

        $order = [
            'id' => 'ORD_P8433_POLL',
            'type' => 'point',
            'status' => 'refunded',
            'transactions' => [
                'refunds' => [[
                    'id' => 'REF_P8433_POLL',
                    'transaction_id' => 'PAY_P8433_POLL',
                    'amount' => '10.00',
                    'status' => 'processed',
                ]],
            ],
        ];

        $observation =
            $adapter->normalizeObservedRefund(
                $order,
                'REF_P8433_POLL',
                1000,
                'ARS'
            );

        $this->assertSame(
            'point-refund:REF_P8433_POLL:processed',
            $observation->observationKey
        );

        $this->assertSame(
            FinancialMovementStatus::Posted,
            $observation->status
        );
    }

    private function connection(): FinancialProviderConnection
    {
        $connection =
            new FinancialProviderConnection();

        $connection->forceFill([
            'provider_key' =>
                'mercado-pago',
            'active' =>
                true,
            'public_id' =>
                '99999999-8888-4777-8666-555555555555',
        ]);

        return $connection;
    }

    private function secretStore(): MercadoPagoConnectionSecretStore
    {
        return new class implements MercadoPagoConnectionSecretStore {
            public function forConnection(
                FinancialProviderConnection $connection
            ): MercadoPagoConnectionSecrets {
                if (
                    $connection->provider_key
                        !== 'mercado-pago'
                ) {
                    throw new DomainException(
                        'Proveedor inválido.'
                    );
                }

                return new MercadoPagoConnectionSecrets(
                    webhookSecret:
                        'TEST_WEBHOOK_SECRET',
                    accessToken:
                        'TEST_REFUND_TOKEN',
                    applicationId:
                        '123456',
                    userId:
                        '654321',
                    liveMode:
                        false
                );
            }
        };
    }

    private function assertDomainFailure(
        callable $callback
    ): void {
        try {
            $callback();

            $this->fail(
                'Se esperaba DomainException.'
            );
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }
    }
}
