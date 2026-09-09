<?php

namespace App\Domain\Catalog;

use InvalidArgumentException;

final readonly class ContextualActionInteraction
{
    /** @param array<string, mixed> $provenance */
    public function __construct(
        public string $actionKey,
        public bool $visible,
        public bool $enabled,
        public string $authority,
        public ?string $reasonCode,
        public ?string $explanationSource,
        public array $provenance,
    ) {
        if (
            preg_match('/^[a-z0-9][a-z0-9._:-]{0,127}$/D', $this->actionKey) !== 1
            || trim($this->authority) === ''
            || $this->provenance === []
        ) {
            throw new InvalidArgumentException(
                'Contextual action interaction identity is invalid.'
            );
        }

        if ($this->enabled && ! $this->visible) {
            throw new InvalidArgumentException(
                'Enabled contextual action must be visible.'
            );
        }

        if (
            (! $this->enabled || ! $this->visible)
            && ($this->reasonCode === null || trim($this->reasonCode) === '')
        ) {
            throw new InvalidArgumentException(
                'Disabled or hidden contextual action requires a reason code.'
            );
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'action_key' => $this->actionKey,
            'visible' => $this->visible,
            'enabled' => $this->enabled,
            'authority' => $this->authority,
            'reason_code' => $this->reasonCode,
            'explanation_source' => $this->explanationSource,
            'provenance' => $this->provenance,
        ];
    }
}
