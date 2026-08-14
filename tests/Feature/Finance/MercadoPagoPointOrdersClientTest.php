<?php

namespace Tests\Feature\Finance;

use App\Adapters\Finance\MercadoPago\MercadoPagoPointOrdersClient;
use DomainException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MercadoPagoPointOrdersClientTest extends TestCase
{
    public function test_create_order_uses_orders_api_decimal_money_and_idempotency(): void
    {
        Http::fake([
            'https://api.mercadopago.com/v1/orders' => Http::response([
                'id' => 'ORD_P53_CREATE_1',
                'type' => 'point',
                'status' => 'created',
                'external_reference' => 'SRCM-P53-TEST-1',
                'country_code' => 'ARG',
                'transactions' => [
                    'payments' => [[
                        'amount' => '12.34',
                        'status' => 'created',
                    ]],
                ],
            ], 201),
        ]);

        $client = new MercadoPagoPointOrdersClient();

        $order = $client->createOrder(
            'TEST_TRANSIENT_TOKEN',
            'NEWLAND_N950__SBX0000001',
            'SRCM-P53-TEST-1',
            1234,
            '123e4567-e89b-42d3-a456-426614174000',
            'SRCM Point test'
        );

        $this->assertSame('ORD_P53_CREATE_1', $order['id']);
        $this->assertSame('created', $order['status']);

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return $request->url()
                    === 'https://api.mercadopago.com/v1/orders'
                && $request->method() === 'POST'
                && $request->hasHeader(
                    'Authorization',
                    'Bearer TEST_TRANSIENT_TOKEN'
                )
                && $request->hasHeader(
                    'X-Idempotency-Key',
                    '123e4567-e89b-42d3-a456-426614174000'
                )
                && ($body['type'] ?? null) === 'point'
                && ($body['external_reference'] ?? null)
                    === 'SRCM-P53-TEST-1'
                && ($body['transactions']['payments'][0]['amount'] ?? null)
                    === '12.34'
                && ($body['config']['point']['terminal_id'] ?? null)
                    === 'NEWLAND_N950__SBX0000001'
                && ($body['config']['point']['print_on_terminal'] ?? null)
                    === 'no_ticket';
        });
    }

    public function test_get_order_uses_authenticated_read_only_endpoint(): void
    {
        Http::fake([
            'https://api.mercadopago.com/v1/orders/ORD_P53_GET_1'
                => Http::response([
                    'id' => 'ORD_P53_GET_1',
                    'type' => 'point',
                    'status' => 'processed',
                    'country_code' => 'ARG',
                    'transactions' => [
                        'payments' => [[
                            'paid_amount' => '1.00',
                            'status' => 'processed',
                        ]],
                    ],
                ], 200),
        ]);

        $client = new MercadoPagoPointOrdersClient();

        $order = $client->getOrder(
            'TEST_TRANSIENT_TOKEN',
            'ORD_P53_GET_1'
        );

        $this->assertSame('processed', $order['status']);

        Http::assertSent(fn ($request): bool =>
            $request->method() === 'GET'
            && $request->url()
                === 'https://api.mercadopago.com/v1/orders/ORD_P53_GET_1'
            && $request->hasHeader(
                'Authorization',
                'Bearer TEST_TRANSIENT_TOKEN'
            )
        );
    }

    public function test_invalid_inputs_fail_before_network(): void
    {
        Http::fake();

        $client = new MercadoPagoPointOrdersClient();

        $this->assertDomainFailure(fn () => $client->createOrder(
            '',
            'NEWLAND_N950__SBX0000001',
            'SRCM-P53-TEST',
            100,
            '123e4567-e89b-42d3-a456-426614174000'
        ));

        $this->assertDomainFailure(fn () => $client->createOrder(
            'TOKEN',
            'bad terminal',
            'SRCM-P53-TEST',
            100,
            '123e4567-e89b-42d3-a456-426614174000'
        ));

        $this->assertDomainFailure(fn () => $client->createOrder(
            'TOKEN',
            'NEWLAND_N950__SBX0000001',
            'reference with spaces',
            100,
            '123e4567-e89b-42d3-a456-426614174000'
        ));

        $this->assertDomainFailure(fn () => $client->createOrder(
            'TOKEN',
            'NEWLAND_N950__SBX0000001',
            'SRCM-P53-TEST',
            0,
            '123e4567-e89b-42d3-a456-426614174000'
        ));

        $this->assertDomainFailure(fn () => $client->createOrder(
            'TOKEN',
            'NEWLAND_N950__SBX0000001',
            'SRCM-P53-TEST',
            100,
            'not-a-uuid'
        ));

        Http::assertNothingSent();
    }

    public function test_provider_errors_are_sanitized_without_body_or_token_leakage(): void
    {
        Http::fake([
            'https://api.mercadopago.com/v1/orders' => Http::response(
                [
                    'code' => 'property_value',
                    'details' => [[
                        'message' => 'Invalid value for terminal_id. SENSITIVE PROVIDER BODY',
                        'token_echo' => 'SHOULD_NOT_LEAK',
                    ]],
                ],
                403,
                ['x-request-id' => 'safe-request-123']
            ),
        ]);

        $client = new MercadoPagoPointOrdersClient();

        try {
            $client->createOrder(
                'VERY_SECRET_TOKEN',
                'NEWLAND_N950__SBX0000001',
                'SRCM-P53-ERROR',
                100,
                '123e4567-e89b-42d3-a456-426614174000'
            );
            $this->fail('Se esperaba DomainException.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString(
                'HTTP 403',
                $exception->getMessage()
            );
            $this->assertStringContainsString(
                'code=property_value',
                $exception->getMessage()
            );
            $this->assertStringContainsString(
                'field=config.point.terminal_id',
                $exception->getMessage()
            );
            $this->assertStringContainsString(
                'request_id=safe-request-123',
                $exception->getMessage()
            );
            $this->assertStringNotContainsString(
                'VERY_SECRET_TOKEN',
                $exception->getMessage()
            );
            $this->assertStringNotContainsString(
                'SENSITIVE PROVIDER BODY',
                $exception->getMessage()
            );
            $this->assertStringNotContainsString(
                'SHOULD_NOT_LEAK',
                $exception->getMessage()
            );
        }
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
