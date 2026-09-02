<?php

namespace Tests\Feature\Numerics;

use App\Domain\Numerics\AuthoritativeNumericInput;
use App\Domain\Numerics\ExactDecimal;
use App\Domain\Numerics\ExactDecimalLegacyAdapter;
use App\Domain\Numerics\HumanNumericInput;
use App\Domain\Numerics\NumericKind;
use App\Domain\Numerics\NumericRoundingBoundary;
use App\Domain\Numerics\NumericRoundingMode;
use App\Domain\Release\ReleasePreflightInspector;
use InvalidArgumentException;
use Tests\TestCase;

final class NumericalRuntimeMoneyBoundaryAdapterFoundationTest extends TestCase
{
    public function test_policy_is_present_fail_closed_and_wave_1_runtime_wired(): void
    {
        $policy = config(
            'release.numeric_integrity.money_boundary_adapter'
        );

        $this->assertIsArray($policy);
        $this->assertSame(1, $policy['foundation_version']);
        $this->assertSame(
            ExactDecimalLegacyAdapter::class,
            $policy['legacy_adapter_class'],
        );
        $this->assertSame(
            NumericRoundingBoundary::class,
            $policy['rounding_boundary_class'],
        );
        $this->assertSame(
            AuthoritativeNumericInput::class,
            $policy['authoritative_input_class'],
        );
        $this->assertTrue(
            $policy['legacy_minor_unit_scale_must_be_explicit']
        );
        $this->assertFalse(
            $policy['legacy_minor_unit_automatic_rewrite_allowed']
        );
        $this->assertFalse(
            $policy['machine_canonical_binary_float_allowed']
        );
        $this->assertTrue(
            $policy['human_input_must_be_preparsed_by_human_numeric_input']
        );
        $this->assertTrue(
            $policy['rounding_boundary_must_be_explicit']
        );
        $this->assertSame(
            'server_side_authoritative_money_boundaries',
            $policy['wave_1_target'],
        );
        $this->assertSame(
            'wired_v1_wave_1_closed',
            $policy['runtime_wiring_status'],
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

    public function test_legacy_minor_units_require_explicit_scale_without_global_two_decimal_assumption(): void
    {
        $scaleTwo = ExactDecimalLegacyAdapter::fromMinorUnit(
            12345,
            2,
        );
        $scaleThree = ExactDecimalLegacyAdapter::fromMinorUnit(
            12345,
            3,
        );
        $negative = ExactDecimalLegacyAdapter::fromMinorUnit(
            -5,
            2,
        );

        $this->assertSame('123.45', $scaleTwo->value);
        $this->assertSame('12.345', $scaleThree->value);
        $this->assertSame('-0.05', $negative->value);

        $this->assertSame(
            12345,
            ExactDecimalLegacyAdapter::toMinorUnit(
                ExactDecimal::fromCanonical('123.45'),
                2,
            ),
        );

        $this->assertSame(
            12345,
            ExactDecimalLegacyAdapter::toMinorUnit(
                ExactDecimal::fromCanonical('12.345'),
                3,
            ),
        );
    }

    public function test_legacy_minor_unit_conversion_fails_closed_on_scale_loss(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        ExactDecimalLegacyAdapter::toMinorUnit(
            ExactDecimal::fromCanonical('12.345'),
            2,
        );
    }

    public function test_machine_authoritative_input_rejects_binary_float_and_noncanonical_text(): void
    {
        foreach ([
            12.34,
            '12,34',
            ' 12.34',
            '1e3',
        ] as $invalid) {
            try {
                AuthoritativeNumericInput::machineCanonical(
                    $invalid,
                    NumericKind::Money,
                    2,
                );
                $this->fail(
                    'Invalid authoritative machine input must fail closed.'
                );
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }

        $accepted = AuthoritativeNumericInput::machineCanonical(
            '12.34',
            NumericKind::Money,
            2,
        );

        $this->assertSame(
            AuthoritativeNumericInput::SOURCE_MACHINE_CANONICAL,
            $accepted->source,
        );
        $this->assertSame('12.34', $accepted->canonical->value);
        $this->assertNull($accepted->rawHumanInput);
    }

    public function test_human_numeric_input_must_be_parsed_before_becoming_authoritative(): void
    {
        $human = HumanNumericInput::parse(
            '12,34',
            NumericKind::Money,
            HumanNumericInput::SEPARATOR_COMMA,
            2,
        );

        $authoritative = AuthoritativeNumericInput::humanParsed(
            $human,
            2,
        );

        $this->assertSame(
            AuthoritativeNumericInput::SOURCE_HUMAN_PARSED,
            $authoritative->source,
        );
        $this->assertSame('12,34', $authoritative->rawHumanInput);
        $this->assertSame('12.34', $authoritative->canonical->value);
    }

    public function test_rounding_is_available_only_through_explicit_named_boundary(): void
    {
        $boundary = new NumericRoundingBoundary(
            boundary: 'money.checkout.line-total',
            mode: NumericRoundingMode::HalfUp,
            scale: 2,
        );

        $rounded = $boundary->apply(
            ExactDecimal::fromCanonical('1.235')
        );

        $this->assertSame('1.24', $rounded->value);
        $this->assertSame(
            'money.checkout.line-total',
            $boundary->boundary,
        );

        $unnecessary = new NumericRoundingBoundary(
            boundary: 'money.purchase.subtotal',
            mode: NumericRoundingMode::Unnecessary,
            scale: 2,
        );

        $this->expectException(
            InvalidArgumentException::class
        );

        $unnecessary->apply(
            ExactDecimal::fromCanonical('1.235')
        );
    }

    public function test_incremental_runtime_targets_report_all_wave_1_money_boundaries_wired_v1(): void
    {
        $policy = config(
            'release.numeric_integrity.money_boundary_adapter'
        );

        $this->assertSame(
            'existing_minor_unit_authority_no_runtime_rewrite_required',
            $policy['purchase_money_rewrite_status'],
        );
        $this->assertSame(
            'wired_v1_human_parsed_exact_scale_2',
            $policy['commerce_checkout_rewrite_status'],
        );
        $this->assertSame(
            'wired_v1_machine_canonical_exact_scale_2',
            $policy['mercado_pago_rewrite_status'],
        );
        $this->assertSame(
            'wired_v1_human_parsed_exact_scale_2',
            $policy['service_cancellation_request_rewrite_status'],
        );
        $this->assertSame(
            'non_money_float_outside_wave_1',
            $policy['financial_statement_date_serial_float_status'],
        );
        $this->assertSame(
            'not_in_foundation_cut',
            $policy['database_schema_change_status'],
        );

        $this->assertFalse(
            config('release.production_release_enabled')
        );
    }
}
