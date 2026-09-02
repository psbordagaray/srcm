<?php

namespace Tests\Feature\Release;

use App\Domain\Release\ReleasePreflightInspector;
use App\Domain\Release\ReleaseState;
use App\Domain\Release\ReleaseStateMachine;
use InvalidArgumentException;
use Tests\TestCase;

final class ReleaseStateMachineFoundationTest extends TestCase
{
    public function test_release_state_policy_is_versioned_fail_closed_and_not_runtime_wired(): void
    {
        $policy = config('release.release_state_machine');

        $this->assertIsArray($policy);
        $this->assertSame(1, $policy['foundation_version']);
        $this->assertSame(ReleaseState::values(), $policy['canonical_states']);
        $this->assertSame(ReleaseStateMachine::transitionMap(), $policy['transitions']);
        $this->assertSame(ReleaseStateMachine::evidenceMap(), $policy['transition_evidence']);
        $this->assertTrue($policy['illegal_transitions_fail_closed']);
        $this->assertTrue($policy['state_progression_is_forward_only']);
        $this->assertTrue($policy['current_symlink_switch_does_not_commit_active_state']);
        $this->assertTrue($policy['active_requires_post_activation_readiness']);
        $this->assertTrue($policy['failed_ready_to_active_transition_keeps_candidate_ready']);
        $this->assertTrue(
            $policy['previous_active_remains_active_until_replacement_active_confirmed']
        );
        $this->assertTrue(
            $policy['previous_active_becomes_superseded_only_after_replacement_active_confirmed']
        );
        $this->assertTrue($policy['active_uniqueness_required']);
        $this->assertTrue($policy['retirement_requires_superseded_state']);
        $this->assertTrue($policy['automatic_database_rollback_is_outside_state_machine']);
        $this->assertSame(
            'foundation_only_not_yet_wired',
            $policy['runtime_persistence_status']
        );
        $this->assertTrue($policy['runtime_persistence_requires_separate_reviewed_cut']);
        $this->assertSame('foundation_only_not_yet_wired', $policy['deploy_wiring_status']);
        $this->assertTrue($policy['deploy_wiring_requires_separate_reviewed_cut']);

        $result = app(ReleasePreflightInspector::class)->inspect();

        $this->assertTrue($result['static']['p13_release_state_machine_policy_contract']);
        $this->assertFalse($result['production_authorized']);
        $this->assertFalse(config('release.production_release_enabled'));
    }

    public function test_release_state_values_are_exact_and_ordered(): void
    {
        $this->assertSame([
            'BUILT',
            'VERIFIED',
            'AUTHORIZED',
            'INSTALLED_INACTIVE',
            'READY',
            'ACTIVE',
            'SUPERSEDED',
            'RETIRED',
        ], ReleaseState::values());

        $this->assertSame([
            'BUILT>VERIFIED',
            'VERIFIED>AUTHORIZED',
            'AUTHORIZED>INSTALLED_INACTIVE',
            'INSTALLED_INACTIVE>READY',
            'READY>ACTIVE',
            'ACTIVE>SUPERSEDED',
            'SUPERSEDED>RETIRED',
        ], ReleaseStateMachine::canonicalTransitions());
    }

    public function test_all_canonical_transitions_require_exact_true_evidence(): void
    {
        $machine = new ReleaseStateMachine();

        foreach (ReleaseStateMachine::transitionMap() as $fromValue => $toValue) {
            $from = ReleaseState::from($fromValue);
            $to = ReleaseState::from($toValue);
            $evidence = array_fill_keys(
                $machine->requiredEvidence($from, $to),
                true,
            );

            $transition = $machine->transition($from, $to, $evidence);

            $this->assertSame($from, $transition->from);
            $this->assertSame($to, $transition->to);
            $this->assertSame($fromValue.'>'.$toValue, $transition->key());

            $expectedEvidence = $evidence;
            ksort($expectedEvidence, SORT_STRING);
            $this->assertSame($expectedEvidence, $transition->evidence);
        }
    }

    public function test_illegal_or_skipped_transitions_fail_closed(): void
    {
        $machine = new ReleaseStateMachine();

        $this->expectException(InvalidArgumentException::class);

        $machine->transition(
            ReleaseState::Built,
            ReleaseState::Authorized,
            [],
        );
    }

    public function test_missing_or_extra_transition_evidence_fails_closed(): void
    {
        $machine = new ReleaseStateMachine();

        $this->expectException(InvalidArgumentException::class);

        $machine->transition(
            ReleaseState::Ready,
            ReleaseState::Active,
            [
                'activation_applied' => true,
                'post_activation_readiness_verified' => true,
                'uncontracted_signal' => true,
            ],
        );
    }

    public function test_ready_to_active_requires_post_readiness_and_failure_keeps_candidate_ready(): void
    {
        $machine = new ReleaseStateMachine();
        $candidateState = ReleaseState::Ready;

        try {
            $machine->transition(
                $candidateState,
                ReleaseState::Active,
                [
                    'activation_applied' => true,
                    'post_activation_readiness_verified' => false,
                ],
            );

            $this->fail('READY>ACTIVE must fail when post-readiness is not verified.');
        } catch (InvalidArgumentException) {
            $this->assertSame(ReleaseState::Ready, $candidateState);
        }
    }

    public function test_previous_active_can_be_superseded_only_after_replacement_active_is_confirmed(): void
    {
        $machine = new ReleaseStateMachine();

        $this->expectException(InvalidArgumentException::class);

        $machine->transition(
            ReleaseState::Active,
            ReleaseState::Superseded,
            ['replacement_active_confirmed' => false],
        );
    }

    public function test_retired_is_reachable_only_from_superseded_with_explicit_authorization(): void
    {
        $machine = new ReleaseStateMachine();

        $transition = $machine->transition(
            ReleaseState::Superseded,
            ReleaseState::Retired,
            ['retirement_authorized' => true],
        );

        $this->assertSame(ReleaseState::Superseded, $transition->from);
        $this->assertSame(ReleaseState::Retired, $transition->to);
    }

    public function test_ci_preflight_exposes_release_state_machine_foundation_without_authorizing_production(): void
    {
        $this->artisan('srcm:release-preflight --ci')
            ->expectsOutputToContain('STATIC_P13_RELEASE_STATE_MACHINE_POLICY_CONTRACT=GREEN')
            ->expectsOutputToContain('PRODUCTION_RELEASE_AUTHORIZED=NO')
            ->expectsOutputToContain('PRODUCTION_REMAINS_BLOCKED=YES')
            ->assertSuccessful();
    }
}
