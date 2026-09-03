<?php

namespace App\Domain\Numerics;

use App\Domain\Authorization\CapabilityAuthorizationContract;
use App\Domain\Authorization\CapabilityDecision;
use InvalidArgumentException;

final readonly class NumericalDiscrepancyOverrideAuthorization
{
    public const SCHEMA = 'straleon.numeric-discrepancy-override-authorization.v1';

    public const CAPABILITY = 'numerics.discrepancy.override';

    public function __construct(
        public CapabilityAuthorizationContract $authorization,
    ) {
        if ($this->authorization->capability->value !== self::CAPABILITY) {
            throw new InvalidArgumentException(
                'Numeric discrepancy override requires the exact override capability.'
            );
        }

        if ($this->authorization->decision !== CapabilityDecision::Allow) {
            throw new InvalidArgumentException(
                'Numeric discrepancy override requires an explicit capability ALLOW decision.'
            );
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'capability' => self::CAPABILITY,
            'authorization' => $this->authorization->toArray(),
            'authorization_fingerprint' => $this->authorization->fingerprint(),
        ];
    }
}