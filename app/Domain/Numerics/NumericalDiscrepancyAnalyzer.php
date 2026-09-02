<?php

namespace App\Domain\Numerics;

use InvalidArgumentException;
use UnexpectedValueException;

final readonly class NumericalDiscrepancyAnalyzer
{
    /** @var list<NumericalDiscrepancyClassifier> */
    public array $classifiers;

    /**
     * @param list<NumericalDiscrepancyClassifier> $classifiers
     */
    public function __construct(array $classifiers)
    {
        $identifiers = [];

        foreach ($classifiers as $classifier) {
            if (! $classifier instanceof NumericalDiscrepancyClassifier) {
                throw new InvalidArgumentException(
                    'Every numeric discrepancy classifier must implement the common interface.'
                );
            }

            $identifier = $classifier->identifier();

            if (
                $identifier === ''
                || array_key_exists($identifier, $identifiers)
            ) {
                throw new InvalidArgumentException(
                    'Numeric discrepancy classifier identifiers must be unique.'
                );
            }

            $identifiers[$identifier] = true;
        }

        $this->classifiers = array_values($classifiers);
    }

    public static function foundation(): self
    {
        return new self([
            new ModuloNineTranspositionSignalClassifier(),
        ]);
    }

    /** @return list<NumericalDiscrepancySignal> */
    public function analyze(
        string $referenceValue,
        string $observedValue,
    ): array {
        $signals = [];

        foreach ($this->classifiers as $classifier) {
            $signal = $classifier->classify(
                $referenceValue,
                $observedValue,
            );

            if ($signal === null) {
                continue;
            }

            if ($signal->ruleId !== $classifier->identifier()) {
                throw new UnexpectedValueException(
                    'Numeric discrepancy signal rule id must match its deterministic classifier.'
                );
            }

            $signals[] = $signal;
        }

        return $signals;
    }
}