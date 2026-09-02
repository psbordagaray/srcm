<?php

namespace Tests\Feature\Numerics;

use App\Adapters\Finance\MercadoPago\MercadoPagoExternalFinancialProviderAdapter;
use App\Domain\Release\ReleasePreflightInspector;
use DomainException;
use Tests\TestCase;

final class MercadoPagoMachineMoneyBoundaryWiringTest extends TestCase
{
    public function test_policy_and_preflight_report_incremental_money_boundaries_wired_v1(): void
    {
        $policy = config(
            'release.numeric_integrity.money_boundary_adapter'
        );

        $this->assertIsArray($policy);
        $this->assertSame(
            'wired_v1_machine_canonical_exact_scale_2',
            $policy['mercado_pago_rewrite_status'],
        );
        $this->assertSame(
            'not_in_foundation_cut',
            $policy['purchase_money_rewrite_status'],
        );
        $this->assertSame(
            'not_in_foundation_cut',
            $policy['commerce_checkout_rewrite_status'],
        );
        $this->assertSame(
            'wired_v1_human_parsed_exact_scale_2',
            $policy['service_cancellation_request_rewrite_status'],
        );

        $result = app(
            ReleasePreflightInspector::class
        )->inspect();

        $this->assertTrue(
            $result['static']['p13_numeric_money_boundary_adapter_policy_contract']
        );
        $this->assertFalse(
            $result['production_authorized']
        );
    }

    public function test_machine_money_boundary_preserves_exact_valid_provider_values(): void
    {
        $adapter = new MercadoPagoExternalFinancialProviderAdapter();

        $cases = [
            ['120.00', 12000],
            ['120.5', 12050],
            ['120', 12000],
            [120, 12000],
            [' 120.00 ', 12000],
            ['0', 0],
            ['9999999999999999.99', 999999999999999999],
        ];

        foreach ($cases as [$value, $expectedMinor]) {
            $observation = $adapter->normalize(
                $this->pointOrder($value)
            );

            $this->assertSame(
                $expectedMinor,
                $observation->grossAmountMinor,
                'Unexpected minor-unit result for '.var_export($value, true),
            );
        }
    }

    public function test_machine_money_boundary_preserves_fail_closed_provider_semantics(): void
    {
        $this->assertProviderFailure(
            10.10,
            'Mercado Pago total_amount llegó como float; se rechaza para evitar redondeo binario ambiguo.',
        );
        $this->assertProviderFailure(
            true,
            'Mercado Pago total_amount no tiene formato monetario válido.',
        );
        $this->assertProviderFailure(
            '01.00',
            'Mercado Pago total_amount no tiene formato decimal seguro.',
        );
        $this->assertProviderFailure(
            '10.123',
            'Mercado Pago total_amount no tiene formato decimal seguro.',
        );
        $this->assertProviderFailure(
            '-1.00',
            'Mercado Pago total_amount no tiene formato decimal seguro.',
        );
        $this->assertProviderFailure(
            '99999999999999999',
            'Mercado Pago total_amount excede el rango monetario admitido.',
        );
    }

    public function test_payment_fallback_preserves_field_specific_provider_error(): void
    {
        $adapter = new MercadoPagoExternalFinancialProviderAdapter();

        try {
            $adapter->normalize([
                'id' => 'ORD_NUMERIC_WIRING_FALLBACK',
                'type' => 'point',
                'status' => 'processed',
                'currency' => 'ARS',
                'transactions' => [
                    'payments' => [[
                        'amount' => '10.123',
                    ]],
                ],
            ]);

            $this->fail('Se esperaba DomainException.');
        } catch (DomainException $exception) {
            $this->assertSame(
                'Mercado Pago transactions.payments[0].amount no tiene formato decimal seguro.',
                $exception->getMessage(),
            );
        }
    }

    public function test_wiring_uses_authoritative_machine_input_and_exact_legacy_adapter_without_rounding(): void
    {
        $body = file_get_contents(
            app_path(
                'Adapters/Finance/MercadoPago/MercadoPagoExternalFinancialProviderAdapter.php'
            )
        );

        $this->assertIsString($body);
        $this->assertStringContainsString(
            'AuthoritativeNumericInput::machineCanonical(',
            $body,
        );
        $this->assertStringContainsString(
            'NumericKind::Money',
            $body,
        );
        $this->assertStringContainsString(
            'ExactDecimalLegacyAdapter::toMinorUnit(',
            $body,
        );
        $this->assertStringNotContainsString(
            'NumericRoundingBoundary',
            $body,
        );
    }

    /** @return array<string, mixed> */
    private function pointOrder(mixed $amount): array
    {
        return [
            'id' => 'ORD_NUMERIC_WIRING',
            'type' => 'point',
            'status' => 'processed',
            'currency' => 'ARS',
            'total_amount' => $amount,
        ];
    }

    private function assertProviderFailure(
        mixed $value,
        string $expectedMessage,
    ): void {
        $adapter = new MercadoPagoExternalFinancialProviderAdapter();

        try {
            $adapter->normalize(
                $this->pointOrder($value)
            );

            $this->fail('Se esperaba DomainException.');
        } catch (DomainException $exception) {
            $this->assertSame(
                $expectedMessage,
                $exception->getMessage(),
            );
        }
    }
}
