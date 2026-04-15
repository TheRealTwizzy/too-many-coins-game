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
            30.0,
            $ratio,
            sprintf(
                'tier3_full work units (%.0f) must be within 30x of tier2_integration work units (%.0f). '
                . 'Actual ratio: %.2f. The agentic tier3 is a directional screening filter — '
                . 'the official promotion pipeline uses SweepComparatorCampaignRunner for the real final gate.',
                $tier3WorkUnits,
                $tier2WorkUnits,
                $ratio
            )
        );
    }

    public function testSubsystemsWithOwnedParametersHaveNonEmptyAfterFilter(): void
    {
        $decomposition = AgenticEconomyDecomposition::build();

        $subsystemsWithNoParams = [];
        foreach ($decomposition['subsystems'] as $subsystem) {
            $ownedParams = $subsystem['owned_parameters'] ?? [];
            if (count($ownedParams) === 0) {
                $subsystemsWithNoParams[] = $subsystem['id'];
            }
        }

        $this->assertEmpty(
            $subsystemsWithNoParams,
            sprintf(
                'The following subsystems have zero owned_parameters after the activeSearchKeys filter, '
                . 'meaning they will be silently skipped by the optimizer: [%s]. '
                . 'Either add their parameter keys to CanonicalEconomyConfigContract::PATCHABLE_PARAMETER_SCHEMA '
                . 'or remove them from the decomposition.',
                implode(', ', $subsystemsWithNoParams)
            )
        );
    }
}
