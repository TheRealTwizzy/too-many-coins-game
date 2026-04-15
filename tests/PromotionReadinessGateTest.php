<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/simulation/PromotionReadinessGate.php';

class PromotionReadinessGateTest extends TestCase
{
    public function testComparatorRejectedCandidateCannotBecomePromotionEligible(): void
    {
        $gate = PromotionReadinessGate::evaluateQualificationComparatorScenario([
            'recommended_disposition' => 'reject',
            'wins' => 6,
            'losses' => 9,
            'mixed_tradeoffs' => 2,
            'regression_flags' => [
                'candidate_improves_B_but_worsens_C',
                'dominant_archetype_shifted',
                'reduced_one_dominant_but_created_new_dominant',
                'skip_rejoin_exploit_worsened',
            ],
            'cross_simulator_regression_flags' => [
                'candidate_improves_B_but_worsens_C',
                'reduced_one_dominant_but_created_new_dominant',
            ],
            'rejection_attribution' => [
                'primary_failed_gate' => [
                    'flag' => 'skip_rejoin_exploit_worsened',
                ],
            ],
        ]);

        $this->assertFalse((bool)$gate['passes']);
        $this->assertSame('non-reject', (string)$gate['required_disposition']);
        $this->assertSame('reject', (string)$gate['actual_disposition']);
        $this->assertContains('skip_rejoin_exploit_worsened', (array)$gate['regression_flags']);
        $this->assertSame('skip_rejoin_exploit_worsened', (string)$gate['rejection_attribution']['primary_failed_gate']['flag']);
    }

    public function testGenuinelyPassingCandidateCanStillBecomePromotionEligible(): void
    {
        $gate = PromotionReadinessGate::evaluateQualificationComparatorScenario([
            'recommended_disposition' => 'candidate for production tuning',
            'wins' => 5,
            'losses' => 2,
            'mixed_tradeoffs' => 1,
            'regression_flags' => [],
            'cross_simulator_regression_flags' => [],
        ]);

        $this->assertTrue((bool)$gate['passes']);
        $this->assertSame('non-reject', (string)$gate['required_disposition']);
        $this->assertSame('candidate for production tuning', (string)$gate['actual_disposition']);
        $this->assertSame([], $gate['regression_flags']);
    }

    public function testQualificationAndPromotionUseAlignedGateSemantics(): void
    {
        $scenarioReport = [
            'recommended_disposition' => 'keep testing',
            'wins' => 3,
            'losses' => 3,
            'mixed_tradeoffs' => 2,
            'regression_flags' => [],
            'cross_simulator_regression_flags' => [],
        ];

        $gate = PromotionReadinessGate::evaluateQualificationComparatorScenario($scenarioReport);

        $this->assertTrue((bool)$gate['passes']);
        $this->assertSame((string)$scenarioReport['recommended_disposition'], (string)$gate['actual_disposition']);
        $this->assertSame((int)$scenarioReport['wins'], (int)$gate['wins']);
        $this->assertSame((int)$scenarioReport['losses'], (int)$gate['losses']);
        $this->assertSame((int)$scenarioReport['mixed_tradeoffs'], (int)$gate['mixed_tradeoffs']);
        $this->assertSame($scenarioReport['regression_flags'], $gate['regression_flags']);
        $this->assertSame($scenarioReport['cross_simulator_regression_flags'], $gate['cross_simulator_regression_flags']);
    }
}
