<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/simulation/SweepComparatorProfileCatalog.php';

class SweepComparatorProfileCatalogTest extends TestCase
{
    public function testQualificationProfileIsReducedAndPinned(): void
    {
        $profile = SweepComparatorProfileCatalog::resolve('qualification');

        $this->assertSame('qualification', (string)$profile['id']);
        $this->assertSame(2, (int)$profile['players_per_archetype']);
        $this->assertSame(4, (int)$profile['season_count']);
        $this->assertSame(['phase-gated-safe-24h-v1'], array_values((array)$profile['scenario_names']));
        $this->assertTrue((bool)$profile['include_baseline']);
        $this->assertFileExists((string)$profile['tuning_candidates_path']);
    }

    public function testFullCampaignProfileCoversFourScenarioBundle(): void
    {
        $profile = SweepComparatorProfileCatalog::resolve('full-campaign');

        $this->assertSame('full-campaign', (string)$profile['id']);
        $this->assertCount(4, (array)$profile['scenario_names']);
        $this->assertContains('phase-gated-plus-inflation-tighten-v1', (array)$profile['scenario_names']);
        $this->assertGreaterThan(
            (float)SweepComparatorProfileCatalog::resolve('qualification')['expected_completion_envelope']['max_minutes'],
            (float)$profile['expected_completion_envelope']['max_minutes']
        );
    }
}
