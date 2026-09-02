<?php

namespace App\Domain\Numerics;

interface CheckDigitAlgorithm
{
    public function identifier(): string;

    public function calculate(string $payload): string;

    public function append(string $payload): string;

    public function isValid(string $candidate): bool;
}