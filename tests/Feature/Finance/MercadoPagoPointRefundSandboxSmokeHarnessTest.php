<?php

namespace Tests\Feature\Finance;

use App\Adapters\Finance\MercadoPago\MercadoPagoConnectionSecrets;
use App\Domain\Finance\MercadoPagoPointRefundSandboxSmokeRunner;
use App\Enums\FinancialMovementStatus;
use DomainException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MercadoPagoPointRefundSandboxSmokeHarnessTest
    extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_sandbox_smoke_creates_virtual_order_processes_and_refunds_without_real_money(): void
    {
        Http::fake([
            'https://api.mercadopago.com/v1/orders' =>
                Http::response([
                    'id' =>
                        'ORD_P8435',
                    'type' =>
                        'point',
                    'status' =>
                        'created',
                    'live_mode' =>
                        false,
                ], 201),

            'https://api.mercadopago.com/v1/orders/ORD_P8435/events' =>
                Http::response(
                    null,
                    204
                ),

            'https://api.mercadopago.com/v1/orders/ORD_P8435' =>
                Http::sequence()
                    ->push([
                        'id' =>
                            'ORD_P8435',
                        'type' =>
                            'point',
                        'status' =>
                            'processed',
                        'live_mode' =>
                            false,
                        'country_code' =>
                            'ARG',
                        'transactions' => [
                            'payments' => [[
                                'id' =>
                                    'PAY_P8435',
                                'paid_amount' =>
                                    '1.00',
                                'status' =>
                                    'processed',
                            ]],
                        ],
                    ], 200)
                    ->push([
                        'id' =>
                            'ORD_P8435',
                        'type' =>
                            'point',
                        'status' =>
                            'refunded',
                        'live_mode' =>
                            false,
                        'country_code' =>
                            'ARG',
                        'transactions' => [
                            'payments' => [[
                                'id' =>
                                    'PAY_P8435',
                                'paid_amount' =>
                                    '1.00',
                                'status' =>
                                    'processed',
                            ]],
                            'refunds' => [[
                                'id' =>
                                    'REF_P8435',
                                'transaction_id' =>
                                    'PAY_P8435',
                                'amount' =>
                                    '1.00',
                                'status' =>
                                    'processed',
                            ]],
                        ],
                    ], 200),

            'https://api.mercadopago.com/v1/orders/ORD_P8435/refund' =>
                Http::response([
                    'id' =>
                        'ORD_P8435',
                    'status' =>
                        'refunded',
                    'status_detail' =>
                        'refunded',
                    'transactions' => [
                        'refunds' => [[
                            'id' =>
                                'REF_P8435',
                            'transaction_id' =>
                                'PAY_P8435',
                            'amount' =>
                                '1.00',
                            'status' =>
                                'processing',
                        ]],
                    ],
                ], 201),
        ]);

        $result =
            app(
                MercadoPagoPointRefundSandboxSmokeRunner::class
            )->run(
                $this->testSecrets(),
                100
            );

        $this->assertSame(
            'ORD_P8435',
            $result->orderId
        );

        $this->assertSame(
            'REF_P8435',
            $result->refundId
        );

        $this->assertSame(
            'NEWLAND_N950__SBX0000001',
            $result->terminalId
        );

        $this->assertSame(
            100,
            $result->amountMinor
        );

        $this->assertSame(
            'ARS',
            $result->currencyCode
        );

        $this->assertSame(
            FinancialMovementStatus::Posted,
            $result->status
        );

        Http::assertSentCount(5);

        Http::assertSent(
            fn (Request $request): bool =>
                $request->method()
                    === 'POST'
                && $request->url()
                    === 'https://api.mercadopago.com/v1/orders'
                && data_get(
                    $request->data(),
                    'config.point.terminal_id'
                )
                    === 'NEWLAND_N950__SBX0000001'
                && data_get(
                    $request->data(),
                    'transactions.payments.0.amount'
                )
                    === '1.00'
        );

        Http::assertSent(
            fn (Request $request): bool =>
                $request->method()
                    === 'POST'
                && $request->url()
                    === 'https://api.mercadopago.com/v1/orders/ORD_P8435/events'
                && ($request->data()['status'] ?? null)
                    === 'processed'
        );

        Http::assertSent(
            fn (Request $request): bool =>
                $request->method()
                    === 'POST'
                && $request->url()
                    === 'https://api.mercadopago.com/v1/orders/ORD_P8435/refund'
                && $request->data()
                    === []
                && $request->hasHeader(
                    'X-Idempotency-Key'
                )
        );
    }

    public function test_live_mode_is_rejected_before_any_network_call(): void
    {
        $secrets =
            new MercadoPagoConnectionSecrets(
                webhookSecret:
                    'not-used',
                accessToken:
                    'production-like-token',
                applicationId:
                    '123',
                userId:
                    '456',
                liveMode:
                    true
            );

        $this->assertDomainFailure(
            fn () => app(
                MercadoPagoPointRefundSandboxSmokeRunner::class
            )->run(
                $secrets,
                100
            )
        );

        Http::assertNothingSent();
    }

    public function test_provider_must_confirm_live_mode_false_before_processing_or_refund(): void
    {
        Http::fake([
            'https://api.mercadopago.com/v1/orders' =>
                Http::response([
                    'id' =>
                        'ORD_LIVE_GUARD',
                    'type' =>
                        'point',
                    'status' =>
                        'created',
                    'live_mode' =>
                        true,
                ], 201),
        ]);

        $this->assertDomainFailure(
            fn () => app(
                MercadoPagoPointRefundSandboxSmokeRunner::class
            )->run(
                $this->testSecrets(),
                100
            )
        );

        Http::assertSentCount(1);

        Http::assertNotSent(
            fn (Request $request): bool =>
                str_ends_with(
                    $request->url(),
                    '/events'
                )
                || str_ends_with(
                    $request->url(),
                    '/refund'
                )
        );
    }

    public function test_processed_payment_amount_must_match_created_amount_before_refund(): void
    {
        Http::fake([
            'https://api.mercadopago.com/v1/orders' =>
                Http::response([
                    'id' =>
                        'ORD_AMOUNT_GUARD',
                    'type' =>
                        'point',
                    'status' =>
                        'created',
                    'live_mode' =>
                        false,
                ], 201),

            'https://api.mercadopago.com/v1/orders/ORD_AMOUNT_GUARD/events' =>
                Http::response(
                    null,
                    204
                ),

            'https://api.mercadopago.com/v1/orders/ORD_AMOUNT_GUARD' =>
                Http::response([
                    'id' =>
                        'ORD_AMOUNT_GUARD',
                    'type' =>
                        'point',
                    'status' =>
                        'processed',
                    'live_mode' =>
                        false,
                    'country_code' =>
                        'ARG',
                    'transactions' => [
                        'payments' => [[
                            'id' =>
                                'PAY_AMOUNT_GUARD',
                            'paid_amount' =>
                                '2.00',
                            'status' =>
                                'processed',
                        ]],
                    ],
                ], 200),
        ]);

        $this->assertDomainFailure(
            fn () => app(
                MercadoPagoPointRefundSandboxSmokeRunner::class
            )->run(
                $this->testSecrets(),
                100
            )
        );

        Http::assertNotSent(
            fn (Request $request): bool =>
                str_ends_with(
                    $request->url(),
                    '/refund'
                )
        );
    }

    private function testSecrets():
        MercadoPagoConnectionSecrets {
        return new MercadoPagoConnectionSecrets(
            webhookSecret:
                'sandbox-not-used',
            accessToken:
                'APP_USR_TEST_P8435',
            applicationId:
                '987654321',
            userId:
                '123456789',
            liveMode:
                false
        );
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
