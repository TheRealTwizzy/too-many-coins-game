<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/feature_factory/ExperimentBrief.php';

class ExperimentBriefTest extends TestCase
{
    public function testCombinedCapsuleNormalizesExperimentAndMechanicProposal(): void
    {
        $brief = ExperimentBrief::fromProposal($this->proposal());

        $this->assertSame('streak_pressure_qol_capsule', $brief['experiment_id']);
        $this->assertSame('capsule', $brief['mode_preference']);
        $this->assertSame('capsule', $brief['recommended_mode']);
        $this->assertSame('combined', $brief['change_intent']);
        $this->assertSame('daily_streak_pressure', $brief['mechanic_brief']['mechanic_id']);
        $this->assertSame('daily_streak_pressure', $brief['mechanic_proposal']['mechanic_id']);
        $this->assertCount(1, $brief['experience_changes']);
        $this->assertSame('public/js/app.js', $brief['experience_changes'][0]['path']);
        $this->assertSame([], $brief['mode_decision_reasons']);
    }

    public function testTooManyCapsuleExperienceChangesRecommendDualLane(): void
    {
        $proposal = $this->proposal();
        $proposal['experience_changes'] = [
            $this->experienceChange('public/js/app.js', 'HUD copy polish'),
            $this->experienceChange('public/css/style.css', 'HUD spacing polish'),
            $this->experienceChange('public/index.html', 'View label polish'),
            $this->experienceChange('api/index.php', 'Read-only response label polish'),
        ];

        $brief = ExperimentBrief::fromProposal($proposal);

        $this->assertSame('capsule', $brief['mode_preference']);
        $this->assertSame('dual_lane', $brief['recommended_mode']);
        $this->assertSame(['too_many_experience_changes_for_capsule'], array_column($brief['mode_decision_reasons'], 'reason_code'));
    }

    public function testRemovalIntentRequiresRemovalTargetAndRollbackNotes(): void
    {
        $proposal = $this->proposal();
        $proposal['change_intent'] = 'removal';
        unset($proposal['removal_target'], $proposal['rollback_notes']);

        try {
            ExperimentBrief::fromProposal($proposal);
            $this->fail('Expected removal validation to fail.');
        } catch (FeatureFactoryException $e) {
            $codes = array_column($e->details(), 'reason_code');
            $this->assertContains('missing_removal_target', $codes);
            $this->assertContains('missing_rollback_notes', $codes);
        }
    }

    public function testInvalidModePreferenceIsRejected(): void
    {
        $proposal = $this->proposal();
        $proposal['mode_preference'] = 'fast_track';

        $this->expectException(FeatureFactoryException::class);
        $this->expectExceptionMessage('Experiment brief validation failed');

        ExperimentBrief::fromProposal($proposal);
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
            'experience_changes' => [
                $this->experienceChange('public/js/app.js', 'Clarify the daily rhythm HUD label.'),
            ],
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

    private function experienceChange(string $path, string $summary): array
    {
        return [
            'path' => $path,
            'change_type' => 'hud_readability',
            'summary' => $summary,
            'economy_behavior' => 'none',
            'reversible' => true,
            'qa_evidence' => ['manual_hud_smoke'],
        ];
    }
}
