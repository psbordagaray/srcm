<?php

namespace Tests\Feature\Numerics;

use App\Domain\Numerics\ExactDecimal;
use App\Domain\Numerics\HumanNumericInput;
use App\Domain\Numerics\NumericIntegrityContract;
use App\Domain\Numerics\NumericKind;
use App\Domain\Numerics\NumericRoundingMode;
use App\Domain\Release\ReleasePreflightInspector;
use InvalidArgumentException;
use Tests\TestCase;

final class NumericIntegrityFoundationTest extends TestCase
{
    public function test_policy_is_versioned_fail_closed_and_money_wave_1_closed(): void
    {
        $policy = config('release.numeric_integrity');

        $this->assertIsArray($policy);
        $this->assertSame(
            'wired_v1_wave_1_closed',
            $policy['money_boundary_adapter']['runtime_wiring_status'],
        );
        $this->assertSame(
            'existing_minor_unit_authority_no_runtime_rewrite_required',
            $policy['money_boundary_adapter']['purchase_money_rewrite_status'],
        );
        $this->assertSame(1, $policy['foundation_version']);
        $this->assertSame(NumericIntegrityContract::SCHEMA, $policy['schema']);
        $this->assertSame(NumericKind::values(), $policy['numeric_kind_values']);
        $this->assertSame(ExactDecimal::PATTERN, $policy['canonical_decimal_pattern']);
        $this->assertFalse($policy['authoritative_financial_binary_float_allowed']);
        $this->assertFalse($policy['scientific_notation_allowed']);
        $this->assertSame('reject_fail_closed', $policy['ambiguous_human_decimal_input_policy']);
        $this->assertFalse($policy['grouping_separators_allowed']);
        $this->assertFalse($policy['silent_truncation_allowed']);
        $this->assertSame('deny_fail_closed', $policy['scale_overflow_policy']);
        $this->assertSame(NumericRoundingMode::values(), $policy['rounding_mode_values']);
        $this->assertTrue($policy['rounding_requires_named_mode']);
        $this->assertTrue($policy['rounding_requires_defined_boundary']);
        $this->assertFalse($policy['intermediate_rounding_allowed']);
        $this->assertFalse($policy['money_scale_is_globally_two']);
        $this->assertSame('currency_or_domain_specific', $policy['money_scale_policy']);
        $this->assertSame('unit_or_domain_specific', $policy['quantity_scale_policy']);
        $this->assertTrue($policy['count_must_be_exact_integer']);
        $this->assertTrue($policy['high_impact_manual_numeric_mutation_requires_reason']);
        $this->assertTrue($policy['high_impact_manual_numeric_mutation_requires_evidence']);
        $this->assertTrue($policy['high_impact_manual_numeric_mutation_requires_capability_authorization']);
        $this->assertFalse($policy['authentication_or_role_alone_authorizes_numeric_override']);

        $result = app(ReleasePreflightInspector::class)->inspect();

        $this->assertTrue($result['static']['p13_numeric_integrity_policy_contract']);
        $this->assertFalse($result['production_authorized']);
    }

    public function test_numeric_kind_and_rounding_mode_values_are_exact(): void
    {
        $this->assertSame([
            'MONEY',
            'QUANTITY',
            'RATE',
            'PERCENTAGE',
            'COUNT',
        ], NumericKind::values());

        $this->assertSame([
            'UNNECESSARY',
            'DOWN',
            'UP',
            'FLOOR',
            'CEILING',
            'HALF_UP',
            'HALF_DOWN',
            'HALF_EVEN',
        ], NumericRoundingMode::values());
    }

    public function test_exact_decimal_preserves_explicit_scale_without_binary_float(): void
    {
        $value = ExactDecimal::fromCanonical('-120.3400');

        $this->assertSame('-120.3400', $value->value);
        $this->assertSame(4, $value->scale);
        $this->assertTrue($value->isNegative());
        $this->assertSame('-120.3400', (string) $value);

        $zero = ExactDecimal::fromCanonical('0.00');

        $this->assertTrue($zero->isZero());
        $this->assertSame(2, $zero->scale);
    }

    public function test_exact_decimal_rejects_noncanonical_or_ambiguous_forms(): void
    {
        foreach ([
            '',
            '01',
            '+1',
            '.5',
            '1.',
            '1,25',
            '1e3',
            ' 1',
            '1 ',
            '-0',
            '-0.00',
        ] as $invalid) {
            try {
                ExactDecimal::fromCanonical($invalid);
                $this->fail("Exact decimal [{$invalid}] must fail closed.");
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_scale_overflow_fails_closed_without_rounding_or_truncation(): void
    {
        $value = ExactDecimal::fromCanonical('10.123');

        $this->expectException(InvalidArgumentException::class);

        $value->assertMaxScale(2);
    }

    public function test_count_requires_exact_integer_and_zero_scale_contract(): void
    {
        $contract = new NumericIntegrityContract(
            NumericKind::Count,
            0,
        );

        $contract->assertAccepts(ExactDecimal::fromCanonical('12'));
        $this->assertSame(0, $contract->maxScale);

        $this->expectException(InvalidArgumentException::class);

        $contract->assertAccepts(ExactDecimal::fromCanonical('12.0'));
    }

    public function test_rounding_mode_requires_explicit_named_boundary(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new NumericIntegrityContract(
            kind: NumericKind::Money,
            maxScale: 2,
            roundingMode: NumericRoundingMode::HalfEven,
        );
    }

    public function test_contract_schema_unknown_fields_and_unknown_enums_fail_closed(): void
    {
        $valid = [
            'schema' => NumericIntegrityContract::SCHEMA,
            'kind' => 'MONEY',
            'max_scale' => 2,
            'rounding_mode' => null,
            'rounding_boundary' => null,
        ];

        $contract = NumericIntegrityContract::fromArray($valid);

        $this->assertSame($valid, $contract->toArray());
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/',
            $contract->fingerprint()
        );

        foreach ([
            array_replace($valid, ['schema' => 'straleon.numeric-integrity.v0']),
            array_replace($valid, ['kind' => 'UNKNOWN']),
            array_replace($valid, ['rounding_mode' => 'UNKNOWN', 'rounding_boundary' => 'sale.total']),
            array_merge($valid, ['unexpected' => true]),
        ] as $invalid) {
            try {
                NumericIntegrityContract::fromArray($invalid);
                $this->fail('Invalid numeric contract must fail closed.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_human_dot_and_comma_input_preserve_raw_and_canonicalize_explicitly(): void
    {
        $dot = HumanNumericInput::parse(
            '1234.50',
            NumericKind::Money,
            HumanNumericInput::SEPARATOR_DOT,
            2,
        );

        $this->assertSame('1234.50', $dot->raw);
        $this->assertSame('1234.50', $dot->canonical->value);
        $this->assertSame('DOT', $dot->decimalSeparator);

        $comma = HumanNumericInput::parse(
            '1234,50',
            NumericKind::Money,
            HumanNumericInput::SEPARATOR_COMMA,
            2,
        );

        $this->assertSame('1234,50', $comma->raw);
        $this->assertSame('1234.50', $comma->canonical->value);
        $this->assertSame('COMMA', $comma->decimalSeparator);
    }

    public function test_ambiguous_grouped_scientific_and_mismatched_human_input_fail_closed(): void
    {
        $cases = [
            ['1.234,56', HumanNumericInput::SEPARATOR_COMMA],
            ['1,234.56', HumanNumericInput::SEPARATOR_DOT],
            ['1.2.3', HumanNumericInput::SEPARATOR_DOT],
            ['1,2,3', HumanNumericInput::SEPARATOR_COMMA],
            ['1e3', HumanNumericInput::SEPARATOR_NONE],
            ['12,50', HumanNumericInput::SEPARATOR_DOT],
            ['12.50', HumanNumericInput::SEPARATOR_COMMA],
            ['12.50', HumanNumericInput::SEPARATOR_NONE],
        ];

        foreach ($cases as [$raw, $separator]) {
            try {
                HumanNumericInput::parse(
                    $raw,
                    NumericKind::Money,
                    $separator,
                    2,
                );
                $this->fail("Human numeric input [{$raw}] must fail closed.");
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_runtime_migrations_and_rewirings_remain_outside_foundation_cut(): void
    {
        $policy = config('release.numeric_integrity');

        $this->assertSame('not_in_foundation_cut', $policy['runtime_calculation_refactor_status']);
        $this->assertSame('not_in_foundation_cut', $policy['model_cast_rewiring_status']);
        $this->assertSame('not_in_foundation_cut', $policy['database_schema_change_status']);
        $this->assertSame('not_in_foundation_cut', $policy['frontend_rewiring_status']);
        $this->assertSame('not_in_foundation_cut', $policy['import_rewiring_status']);
        $this->assertSame('not_in_foundation_cut', $policy['capability_runtime_wiring_status']);

        $this->assertFalse(config('release.production_release_enabled'));
    }
}
