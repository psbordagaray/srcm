<?php

namespace Tests\Feature\Finance;

use App\Adapters\Finance\MercadoPago\MercadoPagoPointWebhookResolver;
use App\Adapters\Finance\MercadoPago\MercadoPagoWebhookSignatureVerifier;
use App\Enums\FinancialMovementStatus;
use DomainException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MercadoPagoWebhookResolutionFoundationTest extends TestCase
{
    public function test_signature_verifier_implements_official_manifest_and_lowercases_alphanumeric_data_id(): void
    {
        $verifier = app(MercadoPagoWebhookSignatureVerifier::class);

        $secret = 'P5_4_TEST_SECRET';
        $dataId = 'ORD01ABC123XYZ';
        $requestId = '2066ca19-c6f1-498a-be75-1923005edd06';
        $timestamp = '1742505638683';

        $manifest = 'id:'.strtolower($dataId)
            .';request-id:'.$requestId
            .';ts:'.$timestamp
            .';';

        $hash = hash_hmac('sha256', $manifest, $secret);

        $verifier->verify(
            'ts='.$timestamp.',v1='.$hash,
            $requestId,
            $dataId,
            $secret
        );

        $this->addToAssertionCount(1);
    }

    public function test_signature_tampering_and_malformed_headers_fail_closed_without_secret_leakage(): void
    {
        $verifier = app(MercadoPagoWebhookSignatureVerifier::class);
        $secret = 'P5_4_SUPER_SECRET';

        foreach ([
            ['ts=1742505638683,v1='.str_repeat('0', 64), 'req-1', 'ORD1'],
            ['ts=bad,v1='.str_repeat('0', 64), 'req-1', 'ORD1'],
            ['v1='.str_repeat('0', 64), 'req-1', 'ORD1'],
            ['ts=1742505638683', 'req-1', 'ORD1'],
            ['ts=1742505638683,ts=1742505638683,v1='.str_repeat('0', 64), 'req-1', 'ORD1'],
        ] as [$signature, $requestId, $dataId]) {
            try {
                $verifier->verify(
                    $signature,
                    $requestId,
                    $dataId,
                    $secret
                );
                $this->fail('Se esperaba DomainException.');
            } catch (DomainException $exception) {
                $this->assertStringNotContainsString(
                    $secret,
                    $exception->getMessage()
                );
            }
        }
    }

    public function test_resolver_verifies_then_fetches_canonical_order_and_ignores_body_financial_values(): void
    {
        Http::fake([
            'https://api.mercadopago.com/v1/orders/ORD01WEBHOOK001'
                => Http::response([
                    'id' => 'ORD01WEBHOOK001',
                    'type' => 'point',
                    'status' => 'processed',
                    'status_detail' => 'accredited',
                    'country_code' => 'ARG',
                    'version' => 4,
                    'last_updated_date' => '2026-08-13T23:40:00Z',
                    'transactions' => [
                        'payments' => [[
                            'paid_amount' => '24.00',
                            'status' => 'processed',
                        ]],
                    ],
                ], 200),
        ]);

        $secret = 'P5_4_WEBHOOK_SECRET';
        $requestId = 'req-webhook-001';
        $dataId = 'ORD01WEBHOOK001';
        $timestamp = '1742505638683';
        $signature = $this->signature(
            $secret,
            $dataId,
            $requestId,
            $timestamp
        );

        $observation = app(MercadoPagoPointWebhookResolver::class)
            ->resolve(
                $signature,
                $requestId,
                [
                    'data.id' => $dataId,
                    'type' => 'order',
                ],
                [
                    'action' => 'order.processed',
                    'api_version' => 'v1',
                    'application_id' => '123456',
                    'date_created' => '2026-08-13T23:39:59Z',
                    'id' => '987654321',
                    'live_mode' => false,
                    'type' => 'order',
                    'user_id' => '654321',
                    'data' => [
                        'id' => $dataId,
                        'status' => 'processed',
                        'total_paid_amount' => '999999.99',
                        'payer' => ['email' => 'ignored@example.com'],
                    ],
                ],
                $secret,
                'TRANSIENT_TEST_ACCESS_TOKEN',
                '123456',
                '654321',
                false
            );

        $this->assertSame('mercado-pago', $observation->providerKey);
        $this->assertSame($dataId, $observation->externalOperationId);
        $this->assertSame(
            FinancialMovementStatus::Posted,
            $observation->status
        );
        $this->assertSame('ARS', $observation->currencyCode);
        $this->assertSame(2400, $observation->grossAmountMinor);

        Http::assertSent(fn ($request): bool =>
            $request->method() === 'GET'
            && $request->url()
                === 'https://api.mercadopago.com/v1/orders/'.$dataId
            && $request->hasHeader(
                'Authorization',
                'Bearer TRANSIENT_TEST_ACCESS_TOKEN'
            )
        );
    }

    public function test_invalid_signature_never_calls_orders_api(): void
    {
        Http::fake();

        $this->assertDomainFailure(fn () =>
            app(MercadoPagoPointWebhookResolver::class)->resolve(
                'ts=1742505638683,v1='.str_repeat('0', 64),
                'req-invalid-signature',
                [
                    'data.id' => 'ORD01WEBHOOK002',
                    'type' => 'order',
                ],
                $this->body(
                    'ORD01WEBHOOK002',
                    '123456',
                    '654321',
                    false
                ),
                'REAL_SECRET_FOR_TEST',
                'TRANSIENT_TOKEN',
                '123456',
                '654321',
                false
            )
        );

        Http::assertNothingSent();
    }

    public function test_body_cannot_select_other_application_user_mode_or_resource(): void
    {
        Http::fake();

        $secret = 'P5_4_ROUTING_SECRET';
        $requestId = 'req-routing-001';
        $dataId = 'ORD01WEBHOOK003';
        $signature = $this->signature(
            $secret,
            $dataId,
            $requestId,
            '1742505638683'
        );

        $cases = [
            $this->body($dataId, '999999', '654321', false),
            $this->body($dataId, '123456', '999999', false),
            $this->body($dataId, '123456', '654321', true),
            $this->body('ORD_OTHER_RESOURCE', '123456', '654321', false),
        ];

        foreach ($cases as $body) {
            $this->assertDomainFailure(fn () =>
                app(MercadoPagoPointWebhookResolver::class)->resolve(
                    $signature,
                    $requestId,
                    [
                        'data.id' => $dataId,
                        'type' => 'order',
                    ],
                    $body,
                    $secret,
                    'TRANSIENT_TOKEN',
                    '123456',
                    '654321',
                    false
                )
            );
        }

        Http::assertNothingSent();
    }

    public function test_unknown_point_action_fails_closed_before_fetch(): void
    {
        Http::fake();

        $secret = 'P5_4_ACTION_SECRET';
        $requestId = 'req-action-001';
        $dataId = 'ORD01WEBHOOK004';
        $signature = $this->signature(
            $secret,
            $dataId,
            $requestId,
            '1742505638683'
        );

        $body = $this->body(
            $dataId,
            '123456',
            '654321',
            false
        );
        $body['action'] = 'order.magic';

        $this->assertDomainFailure(fn () =>
            app(MercadoPagoPointWebhookResolver::class)->resolve(
                $signature,
                $requestId,
                [
                    'data.id' => $dataId,
                    'type' => 'order',
                ],
                $body,
                $secret,
                'TRANSIENT_TOKEN',
                '123456',
                '654321',
                false
            )
        );

        Http::assertNothingSent();
    }

    private function signature(
        string $secret,
        string $dataId,
        string $requestId,
        string $timestamp
    ): string {
        $signedId = ctype_alnum($dataId)
            ? strtolower($dataId)
            : $dataId;

        $manifest = 'id:'.$signedId
            .';request-id:'.$requestId
            .';ts:'.$timestamp
            .';';

        return 'ts='.$timestamp
            .',v1='
            .hash_hmac('sha256', $manifest, $secret);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(
        string $dataId,
        string $applicationId,
        string $userId,
        bool $liveMode
    ): array {
        return [
            'action' => 'order.processed',
            'api_version' => 'v1',
            'application_id' => $applicationId,
            'date_created' => '2026-08-13T23:39:59Z',
            'id' => '987654321',
            'live_mode' => $liveMode,
            'type' => 'order',
            'user_id' => $userId,
            'data' => [
                'id' => $dataId,
            ],
        ];
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
