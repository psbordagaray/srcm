<?php

namespace App\Domain\Catalog;

use InvalidArgumentException;

final readonly class ContextualOperationContext
{
    public function __construct(
        public string $operation,
        public int $catalogProductId,
        public ?int $organizationId,
    ) {
        if (
            preg_match('/^[a-z0-9][a-z0-9._:-]{0,127}$/D', $this->operation) !== 1
            || $this->catalogProductId <= 0
            || ($this->organizationId !== null && $this->organizationId <= 0)
        ) {
            throw new InvalidArgumentException(
                'Contextual operation context identity is invalid.'
            );
        }
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'operation' => $this->operation,
            'catalog_product_id' => $this->catalogProductId,
            'organization_id' => $this->organizationId,
        ];
    }
}
