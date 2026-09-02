<?php

namespace App\Domain\Numerics;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode as BrickRoundingMode;
use InvalidArgumentException;
use Throwable;

final readonly class NumericRoundingBoundary
{
    public const BOUNDARY_PATTERN = '/^[a-z][a-z0-9_.:-]*$/D';

    public function __construct(
        public string $boundary,
        public NumericRoundingMode $mode,
        public int $scale,
    ) {
        if (
            $this->boundary === ''
            || strlen($this->boundary) > 128
            || trim($this->boundary) !== $this->boundary
            || preg_match(
                self::BOUNDARY_PATTERN,
                $this->boundary,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'Numeric rounding boundary must use an explicit canonical identifier.'
            );
        }

        if ($this->scale < 0 || $this->scale > 18) {
            throw new InvalidArgumentException(
                'Numeric rounding boundary scale must be explicitly between 0 and 18.'
            );
        }
    }

    public function apply(ExactDecimal $value): ExactDecimal
    {
        try {
            $rounded = BigDecimal::of($value->value)
                ->toScale(
                    $this->scale,
                    $this->brickMode(),
                );

            return ExactDecimal::fromCanonical(
                (string) $rounded
            );
        } catch (Throwable $exception) {
            throw new InvalidArgumentException(
                'Numeric rounding boundary could not produce the contracted exact value.',
                previous: $exception,
            );
        }
    }

    private function brickMode(): BrickRoundingMode
    {
        return match ($this->mode) {
            NumericRoundingMode::Unnecessary =>
                BrickRoundingMode::Unnecessary,
            NumericRoundingMode::Down =>
                BrickRoundingMode::Down,
            NumericRoundingMode::Up =>
                BrickRoundingMode::Up,
            NumericRoundingMode::Floor =>
                BrickRoundingMode::Floor,
            NumericRoundingMode::Ceiling =>
                BrickRoundingMode::Ceiling,
            NumericRoundingMode::HalfUp =>
                BrickRoundingMode::HalfUp,
            NumericRoundingMode::HalfDown =>
                BrickRoundingMode::HalfDown,
            NumericRoundingMode::HalfEven =>
                BrickRoundingMode::HalfEven,
        };
    }
}
