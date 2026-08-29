<?php

declare(strict_types=1);

namespace App\Domain\Offline;

use DateTimeImmutable;

final readonly class RestrictedOfflineSignedGrantIssue
{
    /** @param list<string> $capabilities */
    public function __construct(
        public string $grant,
        public DateTimeImmutable $expiresAt,
        public string $kid,
        public array $capabilities,
        public string $policyVersion,
    ) {
    }
}
