<?php

namespace Tests\Feature\Finance;

use App\Adapters\Finance\MercadoPago\MercadoPagoConnectionSecrets;
use App\Domain\Finance\MercadoPagoPointRefundSandboxSmokeRunner;
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

    public function test_sandbox_smoke_uses_official_processed_then_refunded_simulation_without_refund_endpoint(): void
    {
        Http::fake([
            'https://api.mercadopago.com/v1/orders' =>
                Http::response([
                    'id' =>
                        'ORD_P8436',
                    'type' =>
                        'point',
                    'status' =>
                        'created',
                    'live_mode' =>
                        false,
                ], 201),

            'https://api.mercadopago.com/v1/orders/ORD_P8436/events' =>
                Http::sequence()
                    ->push(
                        null,
                        204
                    )
                    ->push(
                        null,
                        204
                    ),

            'https://api.mercadopago.com/v1/orders/ORD_P8436' =>
                Http::sequence()
                    ->push([
                        'id' =>
                            'ORD_P8436',
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
                                    'PAY_P8436',
                                'paid_amount' =>
                                    '1.00',
                                'status' =>
                                    'processed',
                            ]],
                        ],
                    ], 200)
                    ->push([
                        'id' =>
                            'ORD_P8436',
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
                                    'PAY_P8436',
                                'paid_amount' =>
                                    '1.00',
                                'status' =>
                                    'processed',
                            ]],
                        ],
                    ], 200),
        ]);

        $result =
            app(
                MercadoPagoPointRefundSandboxSmokeRunner::class
            )->run(
                $this->testSecrets(),
                100
            );

        $this->assertSame(
            'ORD_P8436',
            $result->orderId
        );

        $this->assertSame(
            'PAY_P8436',
            $result->paymentId
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
            'refunded',
            $result->orderStatus
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
        );

        Http::assertSent(
            fn (Request $request): bool =>
                $request->method()
                    === 'POST'
                && $request->url()
                    === 'https://api.mercadopago.com/v1/orders/ORD_P8436/events'
                && ($request->data()['status'] ?? null)
                    === 'processed'
        );

        Http::assertSent(
            fn (Request $request): bool =>
                $request->method()
                    === 'POST'
                && $request->url()
                    === 'https://api.mercadopago.com/v1/orders/ORD_P8436/events'
                && ($request->data()['status'] ?? null)
                    === 'refunded'
        );

        Http::assertNotSent(
            fn (Request $request): bool =>
                str_ends_with(
                    $request->url(),
                    '/refund'
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

    public function test_provider_must_confirm_live_mode_false_before_any_state_simulation(): void
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
        );
    }

    public function test_processed_payment_amount_must_match_created_amount_before_refunded_simulation(): void
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
                    '/events'
                )
                && ($request->data()['status'] ?? null)
                    === 'refunded'
        );
    }

    public function test_refunded_simulation_requires_refunded_order_observation(): void
    {
        $processed = [
            'id' =>
                'ORD_REFUND_POLL_GUARD',
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
                        'PAY_REFUND_POLL_GUARD',
                    'paid_amount' =>
                        '1.00',
                    'status' =>
                        'processed',
                ]],
            ],
        ];

        $pollSequence =
            Http::sequence();

        for ($i = 0; $i < 13; $i++) {
            $pollSequence->push(
                $processed,
                200
            );
        }

        Http::fake([
            'https://api.mercadopago.com/v1/orders' =>
                Http::response([
                    'id' =>
                        'ORD_REFUND_POLL_GUARD',
                    'type' =>
                        'point',
                    'status' =>
                        'created',
                    'live_mode' =>
                        false,
                ], 201),

            'https://api.mercadopago.com/v1/orders/ORD_REFUND_POLL_GUARD/events' =>
                Http::sequence()
                    ->push(
                        null,
                        204
                    )
                    ->push(
                        null,
                        204
                    ),

            'https://api.mercadopago.com/v1/orders/ORD_REFUND_POLL_GUARD' =>
                $pollSequence,
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
                'APP_USR_TEST_P8436',
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
