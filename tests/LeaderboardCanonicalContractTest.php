<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/boost_catalog.php';

class LeaderboardCanonicalContractTest extends TestCase
{
    public function testCanonicalBoostCapConstantIsFiveHundredPercent(): void
    {
        $this->assertSame(5000000, (int)BoostCatalog::TOTAL_POWER_CAP_FP);
    }

    public function testLeaderboardPathMustNotHardcodeLegacyFourHundredPercentCap(): void
    {
        $apiSource = file_get_contents(__DIR__ . '/../api/index.php');
        $this->assertIsString($apiSource);

        // Guard against regressions back to the legacy 4,000,000 fp cap in leaderboard SQL.
        $this->assertStringNotContainsString(
            'LEAST(COALESCE(self_b.self_fp, 0), 4000000)',
            $apiSource
        );
        $this->assertStringNotContainsString(
            'ROUND(LEAST(COALESCE(self_b.self_fp, 0), 4000000) / 10000, 1)',
            $apiSource
        );
    }

    public function testLeaderboardUsesCanonicalBoostCapSource(): void
    {
        $apiSource = file_get_contents(__DIR__ . '/../api/index.php');
        $this->assertIsString($apiSource);

        $this->assertStringContainsString(
            'BoostCatalog::TOTAL_POWER_CAP_FP',
            $apiSource,
            'Leaderboard metrics must use canonical boost cap source.'
        );
    }

    public function testLeaderboardUsesCanonicalEffectiveSeasonScoreForLockedInRows(): void
    {
        $apiSource = file_get_contents(__DIR__ . '/../api/index.php');
        $this->assertIsString($apiSource);

        $this->assertStringContainsString(
            'function seasonEffectiveScoreSql',
            $apiSource,
            'Leaderboard code must define a canonical effective-score SQL helper for locked-in rows.'
        );
        $this->assertStringContainsString(
            '$effectiveScoreSql = seasonEffectiveScoreSql(\'sp\');',
            $apiSource,
            'Leaderboard SQL must use the canonical effective-score helper instead of raw seasonal_stars ordering.'
        );
    }

    public function testCanonicalCapAllowsValuesAboveFourHundredPercentAndClampsAtFiveHundred(): void
    {
        $cap = (int)BoostCatalog::TOTAL_POWER_CAP_FP;

        $boost450 = max(0, min(4500000, $cap));
        $boost600 = max(0, min(6000000, $cap));

        $this->assertSame(4500000, $boost450, '450% should pass through unchanged.');
        $this->assertSame(5000000, $boost600, '600% should clamp to canonical 500% cap.');

        $this->assertSame(450.0, round($boost450 / 10000, 1));
        $this->assertSame(500.0, round($boost600 / 10000, 1));
    }
}
