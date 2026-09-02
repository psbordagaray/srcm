<?php

namespace App\Domain\Release;

use InvalidArgumentException;

final class ReleaseStateMachine
{
    /** @var array<string, string> */
    private const TRANSITIONS = [
        'BUILT' => 'VERIFIED',
        'VERIFIED' => 'AUTHORIZED',
        'AUTHORIZED' => 'INSTALLED_INACTIVE',
        'INSTALLED_INACTIVE' => 'READY',
        'READY' => 'ACTIVE',
        'ACTIVE' => 'SUPERSEDED',
        'SUPERSEDED' => 'RETIRED',
    ];

    /** @var array<string, list<string>> */
    private const REQUIRED_EVIDENCE = [
        'BUILT>VERIFIED' => [
            'artifact_verified',
            'static_contracts_verified',
        ],
        'VERIFIED>AUTHORIZED' => [
            'release_authorization_verified',
        ],
        'AUTHORIZED>INSTALLED_INACTIVE' => [
            'immutable_release_installed',
            'current_symlink_unchanged',
        ],
        'INSTALLED_INACTIVE>READY' => [
            'pre_activation_readiness_verified',
            'migration_contract_satisfied',
        ],
        'READY>ACTIVE' => [
            'activation_applied',
            'post_activation_readiness_verified',
        ],
        'ACTIVE>SUPERSEDED' => [
            'replacement_active_confirmed',
        ],
        'SUPERSEDED>RETIRED' => [
            'retirement_authorized',
        ],
    ];

    public function canTransition(ReleaseState $from, ReleaseState $to): bool
    {
        return (self::TRANSITIONS[$from->value] ?? null) === $to->value;
    }

    /** @return list<string> */
    public function requiredEvidence(ReleaseState $from, ReleaseState $to): array
    {
        if (! $this->canTransition($from, $to)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Illegal release state transition: %s>%s.',
                    $from->value,
                    $to->value,
                )
            );
        }

        return self::REQUIRED_EVIDENCE[$from->value.'>'.$to->value];
    }

    /**
     * @param array<string, bool> $evidence
     */
    public function transition(
        ReleaseState $from,
        ReleaseState $to,
        array $evidence,
    ): ReleaseStateTransition {
        $required = $this->requiredEvidence($from, $to);

        $providedKeys = array_keys($evidence);
        sort($providedKeys, SORT_STRING);

        $requiredKeys = $required;
        sort($requiredKeys, SORT_STRING);

        if ($providedKeys !== $requiredKeys) {
            throw new InvalidArgumentException(
                sprintf(
                    'Release transition evidence keys must exactly match the contract for %s>%s.',
                    $from->value,
                    $to->value,
                )
            );
        }

        foreach ($required as $key) {
            if (($evidence[$key] ?? null) !== true) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Release transition evidence must be true: %s for %s>%s.',
                        $key,
                        $from->value,
                        $to->value,
                    )
                );
            }
        }

        return new ReleaseStateTransition($from, $to, $evidence);
    }

    /** @return array<string, string> */
    public static function transitionMap(): array
    {
        return self::TRANSITIONS;
    }

    /** @return array<string, list<string>> */
    public static function evidenceMap(): array
    {
        return self::REQUIRED_EVIDENCE;
    }

    /** @return list<string> */
    public static function canonicalTransitions(): array
    {
        $transitions = [];

        foreach (self::TRANSITIONS as $from => $to) {
            $transitions[] = $from.'>'.$to;
        }

        return $transitions;
    }
}
