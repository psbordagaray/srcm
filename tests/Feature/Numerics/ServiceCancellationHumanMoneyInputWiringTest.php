<?php

namespace Tests\Feature\Numerics;

use App\Domain\Numerics\AuthoritativeNumericInput;
use App\Domain\Numerics\ExactDecimalLegacyAdapter;
use App\Domain\Numerics\HumanNumericInput;
use App\Http\Requests\ResolveServiceCancellationRequest;
use InvalidArgumentException;
use ReflectionMethod;
use Tests\TestCase;

final class ServiceCancellationHumanMoneyInputWiringTest extends TestCase
{
    public function test_policy_and_preflight_report_service_cancellation_human_money_wired_v1(): void
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
            'not_in_foundation_cut',
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

    public function test_trimmed_comma_dot_and_integer_inputs_preserve_human_token_and_exact_minor_units(): void
    {
        $cases = [
            [' 12,50 ', '12,50', '12.50', 1250],
            ['12.50', '12.50', '12.50', 1250],
            ['12', '12', '12', 1200],
            ['0,00', '0,00', '0.00', 0],
        ];

        foreach ($cases as [$input, $raw, $canonical, $minor]) {
            $request = $this->preparedRequest($input);
            $authoritative = $request->customerChargeAuthoritativeInput();

            $this->assertInstanceOf(
                AuthoritativeNumericInput::class,
                $authoritative,
            );
            $this->assertSame(
                AuthoritativeNumericInput::SOURCE_HUMAN_PARSED,
                $authoritative->source,
            );
            $this->assertSame($raw, $authoritative->rawHumanInput);
            $this->assertSame($canonical, $authoritative->canonical->value);
            $this->assertSame(
                $minor,
                ExactDecimalLegacyAdapter::toMinorUnit(
                    $authoritative->canonical,
                    2,
                ),
            );
        }
    }

    public function test_ambiguous_or_noncanonical_human_money_fails_closed(): void
    {
        foreach (['1,2.3', '01.00'] as $input) {
            $request = $this->preparedRequest($input);

            try {
                $request->customerChargeAuthoritativeInput();
                $this->fail(
                    'Se esperaba InvalidArgumentException para '.$input
                );
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_empty_customer_charge_has_no_authoritative_money_value(): void
    {
        $request = $this->preparedRequest('   ');

        $this->assertNull(
            $request->customerChargeAuthoritativeInput()
        );
        $this->assertNull($request->input('customer_charge'));
    }

    public function test_runtime_source_uses_human_numeric_contract_without_binary_float_or_rounding(): void
    {
        $requestBody = file_get_contents(
            app_path(
                'Http/Requests/ResolveServiceCancellationRequest.php'
            )
        );
        $controllerBody = file_get_contents(
            app_path(
                'Http/Controllers/ServiceCancellationController.php'
            )
        );

        $this->assertIsString($requestBody);
        $this->assertIsString($controllerBody);

        $this->assertStringContainsString(
            'HumanNumericInput::parse(',
            $requestBody,
        );
        $this->assertStringContainsString(
            'AuthoritativeNumericInput::humanParsed(',
            $requestBody,
        );
        $this->assertStringContainsString(
            'NumericKind::Money',
            $requestBody,
        );
        $this->assertStringNotContainsString(
            '(float) $charge',
            $requestBody,
        );
        $this->assertStringContainsString(
            'ExactDecimalLegacyAdapter::toMinorUnit(',
            $controllerBody,
        );
        $this->assertStringContainsString(
            '$request->customerChargeAuthoritativeInput()',
            $controllerBody,
        );
        $this->assertStringNotContainsString(
            'BigDecimal::of($value)',
            $controllerBody,
        );
        $this->assertStringNotContainsString(
            'RoundingMode::',
            $controllerBody,
        );
    }

    private function preparedRequest(
        string $customerCharge
    ): ResolveServiceCancellationRequest {
        $request = ResolveServiceCancellationRequest::create(
            '/service-cancellation-human-money-test',
            'POST',
            [
                'customer_charge' => $customerCharge,
                'currency_code' => 'ARS',
            ],
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
