<?php

namespace App\Domain\Catalog;

use InvalidArgumentException;

final readonly class ContextualInteractionProfile
{
    /**
     * @param list<ContextualAttributeRequirement> $attributes
     * @param list<ContextualCapabilityInteraction> $capabilities
     * @param list<ContextualActionInteraction> $actions
     * @param array<string, mixed> $provenance
     */
    public function __construct(
        public ContextualOperationContext $context,
        public bool $classified,
        public ?int $productDefinitionId,
        public ?string $productDefinitionKey,
        public ?int $productSchemaVersionId,
        public array $attributes,
        public array $capabilities,
        public array $actions,
        public array $provenance,
    ) {
        if ($this->provenance === []) {
            throw new InvalidArgumentException(
                'Contextual interaction profile requires provenance.'
            );
        }

        if (! $this->classified) {
            if (
                $this->productDefinitionId !== null
                || $this->productDefinitionKey !== null
                || $this->productSchemaVersionId !== null
                || $this->attributes !== []
                || $this->capabilities !== []
            ) {
                throw new InvalidArgumentException(
                    'Unclassified contextual interaction profile cannot carry semantic specialization.'
                );
            }
        } elseif (
            $this->productDefinitionId === null
            || $this->productDefinitionId <= 0
            || trim((string) $this->productDefinitionKey) === ''
            || $this->productSchemaVersionId === null
            || $this->productSchemaVersionId <= 0
        ) {
            throw new InvalidArgumentException(
                'Classified contextual interaction profile requires semantic identity.'
            );
        }

        $this->assertOrderedUnique(
            $this->attributes,
            ContextualAttributeRequirement::class,
            static fn (ContextualAttributeRequirement $item): string =>
                $item->attributeDefinitionKey,
            'attribute'
        );

        $this->assertOrderedUnique(
            $this->capabilities,
            ContextualCapabilityInteraction::class,
            static fn (ContextualCapabilityInteraction $item): string =>
                $item->semanticCapabilityKey,
            'capability'
        );

        $this->assertOrderedUnique(
            $this->actions,
            ContextualActionInteraction::class,
            static fn (ContextualActionInteraction $item): string =>
                $item->actionKey,
            'action'
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'context' => $this->context->toArray(),
            'classification_status' => $this->classified
                ? 'classified'
                : 'unclassified',
            'product_definition_id' => $this->productDefinitionId,
            'product_definition_key' => $this->productDefinitionKey,
            'product_schema_version_id' => $this->productSchemaVersionId,
            'attributes' => array_map(
                static fn (ContextualAttributeRequirement $item): array =>
                    $item->toArray(),
                $this->attributes
            ),
            'capabilities' => array_map(
                static fn (ContextualCapabilityInteraction $item): array =>
                    $item->toArray(),
                $this->capabilities
            ),
            'actions' => array_map(
                static fn (ContextualActionInteraction $item): array =>
                    $item->toArray(),
                $this->actions
            ),
            'provenance' => $this->provenance,
        ];
    }

    /**
     * @param array<int, mixed> $items
     * @param class-string $class
     * @param callable(object): string $key
     */
    private function assertOrderedUnique(
        array $items,
        string $class,
        callable $key,
        string $label
    ): void {
        $seen = [];
        $last = null;

        foreach ($items as $item) {
            if (! $item instanceof $class) {
                throw new InvalidArgumentException(
                    "Contextual interaction profile {$label} projection is invalid."
                );
            }

            $current = $key($item);

            if (isset($seen[$current]) || ($last !== null && strcmp($current, $last) <= 0)) {
                throw new InvalidArgumentException(
                    "Contextual interaction profile {$label} ordering/identity is invalid."
                );
            }

            $seen[$current] = true;
            $last = $current;
        }
    }
}
