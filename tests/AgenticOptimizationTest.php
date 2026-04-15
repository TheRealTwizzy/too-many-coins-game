<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/optimization/AgenticOptimization.php';

class AgenticOptimizationTest extends TestCase
{
    public function testTier3FullProfileIsWithinReasonableCost(): void
    {
        $decomposition = AgenticEconomyDecomposition::build();

        $tier2Profile = $decomposition['profiles']['tier2_integration'];
        $tier3Profile = $decomposition['profiles']['tier3_full'];

        $tier2WorkUnits = AgenticCouplingHarnessCatalog::estimateWorkUnits($tier2Profile);
        $tier3WorkUnits = AgenticCouplingHarnessCatalog::estimateWorkUnits($tier3Profile);

        $this->assertGreaterThan(0.0, $tier2WorkUnits, 'tier2_integration must have positive work units');

        $ratio = $tier3WorkUnits / $tier2WorkUnits;

        $this->assertLessThanOrEqual(
            5.0,
            $ratio,
            sprintf(
                'tier3_full work units (%.0f) must be <= 5x tier2_integration (%.0f). '
                . 'Got ratio: ' . round($ratio, 1) . 'x. Reduce season_count, players_per_archetype, or seed count.',
                $tier3WorkUnits,
                $tier2WorkUnits
            )
        );
    }

    public function testSubsystemsWithOwnedParametersHaveNonEmptyAfterFilter(): void
    {
        $decomposition = AgenticEconomyDecomposition::build();
        $emptySubsystems = [];

        foreach ($decomposition['subsystems'] as $subsystem) {
            if (count((array)($subsystem['owned_parameters'] ?? [])) === 0) {
                $emptySubsystems[] = $subsystem['id'];
            }
        }

        $this->assertEmpty(
            $emptySubsystems,
            'These subsystems have zero owned parameters after active-surface filter: ' . implode(', ', $emptySubsystems) .
            '. Subsystems with no parameters cannot generate candidates.'
        );
    }
}
