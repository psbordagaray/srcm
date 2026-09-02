<?php

namespace Tests\Feature\Numerics;

use App\Domain\Numerics\AuthoritativeNumericInput;
use App\Domain\Numerics\ExactDecimalLegacyAdapter;
use App\Http\Requests\StoreCommerceSaleRequest;
use InvalidArgumentException;
use ReflectionMethod;
use Tests\TestCase;

final class CommerceHumanMoneyInputWiringTest extends TestCase
{
    public function test_policy_and_preflight_report_commerce_human_money_wired_v1(): void
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
            'wired_v1_human_parsed_exact_scale_2',
            $policy['service_cancellation_request_rewrite_status'],
        );
        $this->assertSame(
            'wired_v1_human_parsed_exact_scale_2',
            $policy['commerce_checkout_rewrite_status'],
        );

        $result = app(
            \App\Domain\Release\ReleasePreflightInspector::class
        )->inspect();

        $this->assertTrue(
            $result['static']['p13_numeric_money_boundary_adapter_policy_contract']
        );
        $this->assertFalse($result['production_authorized']);
    }

    public function test_payment_tendered_and_receivable_preserve_trimmed_raw_tokens_and_exact_minor_units(): void
    {
        $request = $this->preparedRequest([
            'payments' => [
                [
                    'method' => 'cash',
                    'amount' => ' 12,50 ',
                    'tendered_amount' => ' 20,00 ',
                ],
                [
                    'method' => 'bank_transfer',
                    'amount' => '12.50',
                    'tendered_amount' => '',
                ],
                [
                    'method' => 'digital_wallet',
                    'amount' => '12',
                    'tendered_amount' => null,
                ],
            ],
            'receivable_amount' => ' 7,25 ',
        ]);

        $amount0 = $request->paymentAmountAuthoritativeInput(0);
        $tendered0 = $request->paymentTenderedAmountAuthoritativeInput(0);
        $amount1 = $request->paymentAmountAuthoritativeInput(1);
        $amount2 = $request->paymentAmountAuthoritativeInput(2);
        $receivable = $request->receivableAmountAuthoritativeInput();

        foreach ([$amount0, $tendered0, $amount1, $amount2, $receivable] as $value) {
            $this->assertInstanceOf(
                AuthoritativeNumericInput::class,
                $value,
            );
            $this->assertSame(
                AuthoritativeNumericInput::SOURCE_HUMAN_PARSED,
                $value->source,
            );
        }

        $this->assertSame('12,50', $amount0->rawHumanInput);
        $this->assertSame('12.50', $amount0->canonical->value);
        $this->assertSame(1250, ExactDecimalLegacyAdapter::toMinorUnit(
            $amount0->canonical,
            2,
        ));

        $this->assertSame('20,00', $tendered0->rawHumanInput);
        $this->assertSame('20.00', $tendered0->canonical->value);
        $this->assertSame(2000, ExactDecimalLegacyAdapter::toMinorUnit(
            $tendered0->canonical,
            2,
        ));

        $this->assertSame('12.50', $amount1->rawHumanInput);
        $this->assertSame('12.50', $amount1->canonical->value);
        $this->assertNull(
            $request->paymentTenderedAmountAuthoritativeInput(1)
        );

        $this->assertSame('12', $amount2->rawHumanInput);
        $this->assertSame('12', $amount2->canonical->value);
        $this->assertNull(
            $request->paymentTenderedAmountAuthoritativeInput(2)
        );

        $this->assertSame('7,25', $receivable->rawHumanInput);
        $this->assertSame('7.25', $receivable->canonical->value);
        $this->assertSame(725, ExactDecimalLegacyAdapter::toMinorUnit(
            $receivable->canonical,
            2,
        ));

        $this->assertSame('12.50', $request->input('payments.0.amount'));
        $this->assertSame('20.00', $request->input('payments.0.tendered_amount'));
        $this->assertSame('7.25', $request->input('receivable_amount'));
    }

    public function test_ambiguous_and_noncanonical_human_money_fail_closed(): void
    {
        foreach (['1,2.3', '01.00'] as $input) {
            $request = $this->preparedRequest([
                'payments' => [[
                    'method' => 'cash',
                    'amount' => $input,
                ]],
            ]);

            try {
                $request->paymentAmountAuthoritativeInput(0);
                $this->fail(
                    'Se esperaba InvalidArgumentException para '.$input
                );
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_runtime_source_uses_one_human_numeric_authority_without_bigdecimal_or_manual_minor_arithmetic(): void
    {
        $requestBody = file_get_contents(
            app_path('Http/Requests/StoreCommerceSaleRequest.php')
        );
        $controllerBody = file_get_contents(
            app_path('Http/Controllers/CommerceSaleController.php')
        );

        $this->assertIsString($requestBody);
        $this->assertIsString($controllerBody);

        foreach ([
            'HumanNumericInput::parse(',
            'AuthoritativeNumericInput::humanParsed(',
            'NumericKind::Money',
            'ExactDecimalLegacyAdapter::toMinorUnit(',
            'paymentAmountAuthoritativeInput(',
            'paymentTenderedAmountAuthoritativeInput(',
            'receivableAmountAuthoritativeInput(',
            'validatedMoneyMinor(',
        ] as $required) {
            $this->assertStringContainsString(
                $required,
                $requestBody,
            );
        }

        $this->assertStringNotContainsString(
            'return ((int) $whole * 100) + (int) $decimal;',
            $requestBody,
        );

        foreach ([
            '$request->paymentAmountAuthoritativeInput(',
            '$request',
            '->paymentTenderedAmountAuthoritativeInput(',
            '$request->receivableAmountAuthoritativeInput()',
            'ExactDecimalLegacyAdapter::toMinorUnit(',
        ] as $required) {
            $this->assertStringContainsString(
                $required,
                $controllerBody,
            );
        }

        $this->assertStringNotContainsString(
            'BigDecimal::of(',
            $controllerBody,
        );
        $this->assertStringNotContainsString(
            'RoundingMode::',
            $controllerBody,
        );
        $this->assertStringNotContainsString(
            'private function moneyMinor(',
            $controllerBody,
        );
    }

    /** @param array<string, mixed> $input */
    private function preparedRequest(
        array $input
    ): StoreCommerceSaleRequest {
        $request = StoreCommerceSaleRequest::create(
            '/commerce-human-money-test',
            'POST',
            $input,
        );

        $prepare = new ReflectionMethod(
            $request,
            'prepareForValidation',
        );
        $prepare->setAccessible(true);
        $prepare->invoke($request);

        return $request;
    }
}
