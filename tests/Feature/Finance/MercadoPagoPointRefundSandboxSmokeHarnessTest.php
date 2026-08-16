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

    public function test_official_orders_api_resources_without_live_mode_complete_processed_then_refunded_simulation(): void
    {
        Http::fake([
            'https://api.mercadopago.com/v1/orders' =>
                Http::response(
                    $this->order(
                        status:
                            'created'
                    ),
                    201
                ),

            'https://api.mercadopago.com/v1/orders/ORD_P84371/events' =>
                Http::sequence()
                    ->push(
                        null,
                        204
                    )
                    ->push(
                        null,
                        204
                    ),

            'https://api.mercadopago.com/v1/orders/ORD_P84371' =>
                Http::sequence()
                    ->push(
                        $this->order(
                            status:
                                'processed',
                            payment:
                                [
                                    'id' =>
                                        'PAY_P84371',
                                    'paid_amount' =>
                                        '50.00',
                                    'status' =>
                                        'processed',
                                ]
                        ),
                        200
                    )
                    ->push(
                        $this->order(
                            status:
                                'refunded',
                            payment:
                                [
                                    'id' =>
                                        'PAY_P84371',
                                    'paid_amount' =>
                                        '50.00',
                                    'status' =>
                                        'refunded',
                                ]
                        ),
                        200
                    ),
        ]);

        $result =
            app(
                MercadoPagoPointRefundSandboxSmokeRunner::class
            )->run(
                $this->testSecrets(),
                5000
            );

        $this->assertSame(
            'ORD_P84371',
            $result->orderId
        );

        $this->assertSame(
            'PAY_P84371',
            $result->paymentId
        );

        $this->assertSame(
            'NEWLAND_N950__SBX0000001',
            $result->terminalId
        );

        $this->assertSame(
            5000,
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
                && data_get(
                    $request->data(),
                    'transactions.payments.0.amount'
                )
                    === '50.00'
        );

        Http::assertSent(
            fn (Request $request): bool =>
                $request->url()
                    === 'https://api.mercadopago.com/v1/orders/ORD_P84371/events'
                && ($request->data()['status'] ?? null)
                    === 'processed'
        );

        Http::assertSent(
            fn (Request $request): bool =>
                $request->url()
                    === 'https://api.mercadopago.com/v1/orders/ORD_P84371/events'
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

    public function test_local_live_classification_is_rejected_before_network(): void
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
                5000
            )
        );

        Http::assertNothingSent();
    }

    public function test_unexpected_live_mode_true_in_api_resource_fails_before_simulation(): void
    {
        $order =
            $this->order(
                status:
                    'created'
            );

        $order['live_mode'] =
            true;

        Http::fake([
            'https://api.mercadopago.com/v1/orders' =>
                Http::response(
                    $order,
                    201
                ),
        ]);

        $this->assertDomainFailure(
            fn () => app(
                MercadoPagoPointRefundSandboxSmokeRunner::class
            )->run(
                $this->testSecrets(),
                5000
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

    public function test_order_user_identity_must_match_expected_test_user_before_simulation(): void
    {
        $order =
            $this->order(
                status:
                    'created'
            );

        $order['user_id'] =
            '999999999';

        Http::fake([
            'https://api.mercadopago.com/v1/orders' =>
                Http::response(
                    $order,
                    201
                ),
        ]);

        $this->assertDomainFailure(
            fn () => app(
                MercadoPagoPointRefundSandboxSmokeRunner::class
            )->run(
                $this->testSecrets(),
                5000
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

    public function test_order_terminal_identity_must_match_standard_virtual_terminal_before_simulation(): void
    {
        $order =
            $this->order(
                status:
                    'created'
            );

        $order['config']['point']['terminal_id'] =
            'NEWLAND_N950__REAL123';

        Http::fake([
            'https://api.mercadopago.com/v1/orders' =>
                Http::response(
                    $order,
                    201
                ),
        ]);

        $this->assertDomainFailure(
            fn () => app(
                MercadoPagoPointRefundSandboxSmokeRunner::class
            )->run(
                $this->testSecrets(),
                5000
            )
        );

        Http::assertSentCount(1);
    }

    public function test_processed_payment_amount_must_match_created_amount_before_refunded_simulation(): void
    {
        Http::fake([
            'https://api.mercadopago.com/v1/orders' =>
                Http::response(
                    $this->order(
                        status:
                            'created'
                    ),
                    201
                ),

            'https://api.mercadopago.com/v1/orders/ORD_P84371/events' =>
                Http::response(
                    null,
                    204
                ),

            'https://api.mercadopago.com/v1/orders/ORD_P84371' =>
                Http::response(
                    $this->order(
                        status:
                            'processed',
                        payment:
                            [
                                'id' =>
                                    'PAY_AMOUNT_GUARD',
                                'paid_amount' =>
                                    '51.00',
                                'status' =>
                                    'processed',
                            ]
                    ),
                    200
                ),
        ]);

        $this->assertDomainFailure(
            fn () => app(
                MercadoPagoPointRefundSandboxSmokeRunner::class
            )->run(
                $this->testSecrets(),
                5000
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

    /**
     * @param array<string,mixed>|null $payment
     * @return array<string,mixed>
     */
    private function order(
        string $status,
        ?array $payment = null
    ): array {
        $order = [
            'id' =>
                'ORD_P84371',
            'user_id' =>
                '123456789',
            'type' =>
                'point',
            'status' =>
                $status,
            'status_detail' =>
                $status,
            'country_code' =>
                'AR',
            'config' => [
                'point' => [
                    'terminal_id' =>
                        'NEWLAND_N950__SBX0000001',
                    'print_on_terminal' =>
                        'no_ticket',
                ],
            ],
            'transactions' => [
                'payments' => [],
            ],
        ];

        if ($payment !== null) {
            $order['transactions']['payments'][] =
                $payment;
        }

        return $order;
    }

    private function testSecrets():
        MercadoPagoConnectionSecrets {
        return new MercadoPagoConnectionSecrets(
            webhookSecret:
                'sandbox-not-used',
            accessToken:
                'APP_USR_TEST_P84371',
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
