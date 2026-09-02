<?php

namespace Tests\Feature\Numerics;

use App\Domain\Numerics\CheckDigitAlgorithm;
use App\Domain\Numerics\LuhnCheckDigit;
use App\Domain\Release\ReleasePreflightInspector;
use InvalidArgumentException;
use Tests\TestCase;

final class CheckDigitLuhnFoundationTest extends TestCase
{
    public function test_policy_declares_reusable_luhn_foundation_without_entity_validity_claim(): void
    {
        $policy = config('release.numeric_integrity.check_digit');

        $this->assertIsArray($policy);
        $this->assertSame(1, $policy['foundation_version']);
        $this->assertSame(CheckDigitAlgorithm::class, $policy['interface_class']);
        $this->assertSame(LuhnCheckDigit::class, $policy['luhn_class']);
        $this->assertSame(LuhnCheckDigit::IDENTIFIER, $policy['luhn_identifier']);
        $this->assertSame('non_empty_ascii_digits_only', $policy['input_policy']);
        $this->assertFalse($policy['normalization_allowed']);
        $this->assertFalse($policy['silent_repair_allowed']);
        $this->assertTrue($policy['mathematical_validity_only']);
        $this->assertFalse($policy['entity_validity_inference_allowed']);
        $this->assertSame(
            'foundation_only_not_yet_wired',
            $policy['runtime_wiring_status'],
        );
        $this->assertTrue(
            $policy['runtime_wiring_requires_separate_reviewed_cut'],
        );

        $result = app(ReleasePreflightInspector::class)->inspect();

        $this->assertTrue(
            $result['static']['p13_numeric_integrity_policy_contract'],
        );
        $this->assertFalse($result['production_authorized']);
    }

    public function test_luhn_implements_common_interface_and_known_vectors(): void
    {
        $algorithm = new LuhnCheckDigit();

        $this->assertInstanceOf(CheckDigitAlgorithm::class, $algorithm);
        $this->assertSame('LUHN_MOD_10', $algorithm->identifier());

        foreach ([
            ['7992739871', '3'],
            ['4992739871', '6'],
            ['123456781234567', '0'],
            ['0000', '0'],
        ] as [$payload, $expectedDigit]) {
            $this->assertSame(
                $expectedDigit,
                $algorithm->calculate($payload),
            );

            $candidate = $payload.$expectedDigit;

            $this->assertSame(
                $candidate,
                $algorithm->append($payload),
            );
            $this->assertTrue($algorithm->isValid($candidate));
        }
    }

    public function test_luhn_detects_mismatch_without_silent_repair(): void
    {
        $algorithm = new LuhnCheckDigit();
        $candidate = '79927398714';

        $this->assertFalse($algorithm->isValid($candidate));
        $this->assertSame('79927398714', $candidate);
    }

    public function test_luhn_rejects_ambiguous_or_non_ascii_input_fail_closed(): void
    {
        $algorithm = new LuhnCheckDigit();

        foreach ([
            '',
            '7992 7398713',
            '7992-7398713',
            '+79927398713',
            '7992739871.3',
            '7992739871,3',
            '１２３４',
        ] as $invalid) {
            try {
                $algorithm->isValid($invalid);
                $this->fail(
                    "Malformed Luhn candidate [{$invalid}] must fail closed."
                );
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_luhn_preserves_leading_zeroes_as_exact_digit_text(): void
    {
        $algorithm = new LuhnCheckDigit();

        $this->assertSame('00000', $algorithm->append('0000'));
        $this->assertTrue($algorithm->isValid('00000'));
    }
}