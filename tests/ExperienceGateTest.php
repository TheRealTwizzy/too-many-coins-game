<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/feature_factory/ExperimentBrief.php';
require_once __DIR__ . '/../scripts/feature_factory/ExperienceGate.php';

class ExperienceGateTest extends TestCase
{
    public function testExperienceChangeWithoutApprovalIsPendingApproval(): void
    {
        $brief = ExperimentBrief::fromProposal($this->proposal());

        $report = ExperienceGate::evaluate($brief, null, 'abc123');

        $this->assertSame('pending_approval', $report['status']);
        $this->assertSame(['experience_path_requires_approval'], array_column($report['issues'], 'reason_code'));
    }

    public function testApprovedTinyFrontendChangePasses(): void
    {
        $brief = ExperimentBrief::fromProposal($this->proposal());
        $manifest = [
            'schema_version' => 'tmc-feature-approval.v1',
            'mechanic_id' => 'daily_streak_pressure',
            'bundle_hash' => 'abc123',
            'allowed_path_classes' => ['frontend'],
            'allowed_paths' => ['public/js/app.js'],
            'approval_reason' => 'Tiny HUD readability polish approved for capsule testing.',
            'approver' => 'trent',
            'approved_at' => '2026-04-26T12:00:00Z',
        ];

        $report = ExperienceGate::evaluate($brief, $manifest, 'abc123');

        $this->assertSame('pass', $report['status']);
        $this->assertSame([], $report['issues']);
    }

    public function testRuntimePathRequiresDualLane(): void
    {
        $proposal = $this->proposal();
        $proposal['experience_changes'][0]['path'] = 'includes/economy.php';

        $brief = ExperimentBrief::fromProposal($proposal);
        $report = ExperienceGate::evaluate($brief, null, 'abc123');

        $this->assertSame('split_to_dual_lane', $report['status']);
        $this->assertSame(['experience_core_loop_path_requires_dual_lane'], array_column($report['issues'], 'reason_code'));
    }

    public function testNonReversibleChangeFails(): void
    {
        $proposal = $this->proposal();
        $proposal['experience_changes'][0]['reversible'] = false;

        $brief = ExperimentBrief::fromProposal($proposal);
        $report = ExperienceGate::evaluate($brief, null, 'abc123');

        $this->assertSame('fail', $report['status']);
        $this->assertSame(['experience_change_not_reversible'], array_column($report['issues'], 'reason_code'));
    }

    public function testEconomyBehaviorInExperienceChangeFails(): void
    {
        $proposal = $this->proposal();
        $proposal['experience_changes'][0]['economy_behavior'] = 'changes_lock_in_timing';

        $brief = ExperimentBrief::fromProposal($proposal);
        $report = ExperienceGate::evaluate($brief, null, 'abc123');

        $this->assertSame('fail', $report['status']);
        $this->assertSame(['experience_change_alters_economy_behavior'], array_column($report['issues'], 'reason_code'));
    }

    private function proposal(): array
    {
        return [
            'experiment_id' => 'streak_pressure_qol_capsule',
            'mode_preference' => 'capsule',
            'change_intent' => 'combined',
            'economy_hypothesis' => 'Slightly stronger active UBI can improve regular-player viability without making hoarding dominant.',
            'player_facing_intent' => 'Make the daily rhythm easier to understand.',
            'rollback_notes' => 'Revert the candidate patch and remove the HUD copy polish from the capsule bundle.',
            'experience_changes' => [[
                'path' => 'public/js/app.js',
                'change_type' => 'hud_readability',
                'summary' => 'Clarify the daily rhythm HUD label.',
                'economy_behavior' => 'none',
                'reversible' => true,
                'qa_evidence' => ['manual_hud_smoke'],
            ]],
            'mechanic_id' => 'daily_streak_pressure',
            'title' => 'Regular play streak pressure',
            'summary' => 'Reward repeat daily participation without making hoarding dominant.',
            'player_fantasy' => 'Consistent players feel their daily rhythm matters.',
            'affected_systems' => ['ubi', 'hoarding_pressure'],
            'primary_strategy' => 'regular',
            'secondary_strategies' => ['hoarder', 'mostly_idle'],
            'counterplay' => ['Spend earlier to avoid becoming an easy hoarding target.'],
            'failure_modes' => ['dominant_strategy_risk', 'hoarding_abuse', 'onboarding_harm'],
            'tunable_parameters' => [
                ['key' => 'base_ubi_active_per_tick', 'kind' => 'existing', 'proposed_value' => 42, 'reason' => 'Model reward pressure.'],
            ],
            'proposed_new_config_keys' => [
                ['key' => 'streak_decay_rate_fp', 'type' => 'int', 'reason' => 'Future runtime key for streak decay.'],
            ],
            'required_metrics' => [
                'viability' => ['archetype_viability_min_ratio'],
                'concentration_or_diversity' => ['strategic_diversity'],
                'mechanic_specific' => ['streak_completion_density'],
            ],
            'archetype_expectations' => [
                'hoarder' => 'down',
                'mostly_idle' => 'down',
                'regular' => 'up',
                'hardcore' => 'flat',
                'boost_focused' => 'flat',
                'star_focused' => 'flat',
                'early_locker' => 'flat',
                'late_deployer' => 'down',
                'casual' => 'up',
                'aggressive_sigil_user' => 'flat',
            ],
        ];
    }
}
