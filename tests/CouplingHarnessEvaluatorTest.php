<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/optimization/AgenticOptimization.php';

class CouplingHarnessEvaluatorTest extends TestCase
{
    public function testCatalogExposesAllKnownFamilies(): void
    {
        $families = AgenticCouplingHarnessCatalog::families();

        $this->assertSame([
            'lock_in_down_but_expiry_dominance_up',
            'skip_rejoin_exploit_worsened',
            'hoarding_pressure_imbalance',
            'boost_underperformance',
            'star_affordability_pricing_instability',
        ], array_keys($families));
    }

    public function testLockInExpiryFamilyFailsOnKnownWallPattern(): void
    {
        $family = AgenticCouplingHarnessCatalog::family('lock_in_down_but_expiry_dominance_up');

        $baseline = [
            'lock_in_total' => 10.0,
            'expiry_rate_mean' => 0.20,
            'lock_in_timing_entropy' => 0.50,
        ];
        $candidate = [
            'lock_in_total' => 6.0,
            'expiry_rate_mean' => 0.28,
            'lock_in_timing_entropy' => 0.40,
        ];

        $result = AgenticCouplingHarnessEvaluator::evaluateFamily(
            $family,
            $baseline,
            $candidate,
            ['lock_in_down_but_expiry_dominance_up']
        );

        $this->assertSame('fail', $result['status']);
        $this->assertSame(['lock_in_total', 'expiry_rate_mean', 'lock_in_timing_entropy'], $result['failed_metrics']);
        $this->assertSame(['lock_in_down_but_expiry_dominance_up'], $result['blocking_flags']);
    }

    public function testStarPricingFamilyReportsDirectionalMetricFailures(): void
    {
        $family = AgenticCouplingHarnessCatalog::family('star_affordability_pricing_instability');

        $baseline = [
            'star_purchase_density' => 1.5,
            'first_choice_viability' => 0.75,
            'star_price_cap_share' => 0.10,
            'star_price_range_ratio' => 0.60,
        ];
        $candidate = [
            'star_purchase_density' => 1.2,
            'first_choice_viability' => 0.60,
            'star_price_cap_share' => 0.18,
            'star_price_range_ratio' => 0.95,
        ];

        $result = AgenticCouplingHarnessEvaluator::evaluateFamily($family, $baseline, $candidate, []);

        $this->assertSame('fail', $result['status']);
        $this->assertSame([
            'star_purchase_density',
            'first_choice_viability',
            'star_price_cap_share',
            'star_price_range_ratio',
        ], $result['failed_metrics']);
        $this->assertStringContainsString('need >= 0', (string)$result['metric_results'][0]['diagnostic']);
    }

    public function testDecompositionSubsystemsExposeCouplingHarnessFamilies(): void
    {
        $decomposition = AgenticEconomyDecomposition::build();
        $subsystems = [];
        foreach ((array)$decomposition['subsystems'] as $subsystem) {
            $subsystems[(string)$subsystem['id']] = (array)($subsystem['coupling_harness_families'] ?? []);
        }

        $this->assertSame(['hoarding_pressure_imbalance'], $subsystems['hoarding_pressure']);
        $this->assertSame(['boost_underperformance'], $subsystems['boost_viability']);
        $this->assertSame(['skip_rejoin_exploit_worsened'], $subsystems['retention_repeat_season']);
        $this->assertContains('star_affordability_pricing_instability', $subsystems['star_pricing']);
    }
}
