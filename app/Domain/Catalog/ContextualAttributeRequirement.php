<?php

namespace App\Domain\Catalog;

use InvalidArgumentException;

final readonly class ContextualAttributeRequirement
{
    public const STATUS_SATISFIED = 'satisfied';
    public const STATUS_MISSING_REQUIRED_NOW = 'missing_required_now';
    public const STATUS_OPTIONAL = 'optional';
    public const STATUS_NOT_APPLICABLE = 'not_applicable';
    public const STATUS_UNAVAILABLE = 'unavailable';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_SATISFIED,
        self::STATUS_MISSING_REQUIRED_NOW,
        self::STATUS_OPTIONAL,
        self::STATUS_NOT_APPLICABLE,
        self::STATUS_UNAVAILABLE,
    ];

    /** @param array<string, mixed> $provenance */
    public function __construct(
        public int $attributeBindingId,
        public int $attributeDefinitionId,
        public string $attributeDefinitionKey,
        public bool $relevant,
        public bool $requiredNow,
        public string $status,
        public bool $visible,
        public ?string $reasonCode,
        public ?string $explanationSource,
        public array $provenance,
    ) {
        if (
            $this->attributeBindingId <= 0
            || $this->attributeDefinitionId <= 0
            || trim($this->attributeDefinitionKey) === ''
            || ! in_array($this->status, self::STATUSES, true)
            || $this->provenance === []
        ) {
            throw new InvalidArgumentException(
                'Contextual attribute requirement identity is invalid.'
            );
        }

        if (
            in_array(
                $this->status,
                [
                    self::STATUS_SATISFIED,
                    self::STATUS_MISSING_REQUIRED_NOW,
                    self::STATUS_OPTIONAL,
                    self::STATUS_UNAVAILABLE,
                ],
                true
            )
            && ! $this->relevant
        ) {
            throw new InvalidArgumentException(
                'Relevant contextual attribute state cannot be marked irrelevant.'
            );
        }

        if (
            $this->status === self::STATUS_NOT_APPLICABLE
            && ($this->relevant || $this->requiredNow || $this->visible)
        ) {
            throw new InvalidArgumentException(
                'Not-applicable contextual attribute must remain hidden and irrelevant.'
            );
        }

        if (
            $this->status === self::STATUS_MISSING_REQUIRED_NOW
            && ! $this->requiredNow
        ) {
            throw new InvalidArgumentException(
                'Missing-required-now state requires requiredNow=true.'
            );
        }

        if (
            ($this->status === self::STATUS_NOT_APPLICABLE
                || $this->status === self::STATUS_UNAVAILABLE
                || $this->status === self::STATUS_MISSING_REQUIRED_NOW)
            && ($this->reasonCode === null || trim($this->reasonCode) === '')
        ) {
            throw new InvalidArgumentException(
                'Blocked contextual attribute state requires a reason code.'
            );
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'attribute_binding_id' => $this->attributeBindingId,
            'attribute_definition_id' => $this->attributeDefinitionId,
            'attribute_definition_key' => $this->attributeDefinitionKey,
            'relevant' => $this->relevant,
            'required_now' => $this->requiredNow,
            'status' => $this->status,
            'visible' => $this->visible,
            'reason_code' => $this->reasonCode,
            'explanation_source' => $this->explanationSource,
            'provenance' => $this->provenance,
        ];
    }
}
