<?php

namespace App\Domain\Release;

use InvalidArgumentException;

final readonly class ReleaseStateTransition
{
    /** @var array<string, bool> */
    public array $evidence;

    /**
     * @param array<string, bool> $evidence
     */
    public function __construct(
        public ReleaseState $from,
        public ReleaseState $to,
        array $evidence,
    ) {
        foreach ($evidence as $key => $value) {
            if (! is_string($key) || $key === '' || ! is_bool($value)) {
                throw new InvalidArgumentException(
                    'Release transition evidence must be a non-empty string to bool map.'
                );
            }
        }

        ksort($evidence, SORT_STRING);
        $this->evidence = $evidence;
    }

    public function key(): string
    {
        return $this->from->value.'>'.$this->to->value;
    }

    /** @return array{from: string, to: string, evidence: array<string, bool>} */
    public function toArray(): array
    {
        return [
            'from' => $this->from->value,
            'to' => $this->to->value,
            'evidence' => $this->evidence,
        ];
    }
}
