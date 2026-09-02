<?php

namespace App\Domain\Numerics;

interface NumericalDiscrepancyClassifier
{
    public function identifier(): string;

    public function classify(
        string $referenceValue,
        string $observedValue,
    ): ?NumericalDiscrepancySignal;
}