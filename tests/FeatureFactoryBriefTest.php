<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/feature_factory/MechanicBrief.php';

class FeatureFactoryBriefTest extends TestCase
{
    public function testValidProposalNormalizesToBrief(): void
    {
        $brief = MechanicBrief::fromProposal($this->validProposal());

        $this->assertSame('daily_streak_pressure', $brief['mechanic_id']);
        $this->assertSame('Regular play streak pressure', $brief['title']);
        $this->assertSame('regular', $brief['primary_strategy']);
        $this->assertSame(['hoarder', 'mostly_idle'], $brief['secondary_strategies']);
        $this->assertSame(['base_ubi_active_per_tick'], array_column($brief['tunable_parameters'], 'key'));
        $this->assertSame(['streak_decay_rate_fp'], array_column($brief['proposed_new_config_keys'], 'key'));
        $this->assertSame('proposed', $brief['config_key_status']['streak_decay_rate_fp']);
        $this->assertSame('existing', $brief['config_key_status']['base_ubi_active_per_tick']);
    }

    public function testMissingStrategicFootprintIsRejected(): void
    {
        $proposal = $this->validProposal();
        unset($proposal['counterplay'], $proposal['required_metrics']['mechanic_specific']);

        try {
            MechanicBrief::fromProposal($proposal);
            $this->fail('Expected missing strategic footprint to fail.');
        } catch (FeatureFactoryException $e) {
            $codes = array_column($e->details(), 'reason_code');
            $this->assertContains('missing_counterplay', $codes);
            $this->assertContains('missing_metric_family', $codes);
        }
    }

    public function testInvalidMechanicIdIsRejected(): void
    {
        $proposal = $this->validProposal();
        $proposal['mechanic_id'] = 'Bad Id With Spaces';

        $this->expectException(FeatureFactoryException::class);
        $this->expectExceptionMessage('Mechanic brief validation failed');

        MechanicBrief::fromProposal($proposal);
    }

    private function validProposal(): array
    {
        return [
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
                [
                    'key' => 'base_ubi_active_per_tick',
                    'kind' => 'existing',
                    'proposed_value' => 42,
                    'reason' => 'Existing patchable key used to model reward pressure.',
                ],
            ],
            'proposed_new_config_keys' => [
                [
                    'key' => 'streak_decay_rate_fp',
                    'type' => 'int',
                    'reason' => 'Future runtime key for streak decay.',
                ],
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
