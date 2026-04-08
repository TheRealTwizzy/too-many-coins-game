<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/economy.php';

class BlackoutSettlementContractTest extends TestCase
{
    public function testBlackoutSettlementPhaseHelperRecognizesWindowBounds(): void
    {
        $season = [
            'blackout_time' => 500,
            'end_time' => 1000,
        ];

        $this->assertFalse(Economy::isBlackoutSettlementPhase($season, 499));
        $this->assertTrue(Economy::isBlackoutSettlementPhase($season, 500));
        $this->assertTrue(Economy::isBlackoutSettlementPhase($season, 999));
        $this->assertFalse(Economy::isBlackoutSettlementPhase($season, 1000));
    }

    public function testPurchaseStarsAndLockInRemainAvailableDuringBlackout(): void
    {
        $actionsSource = file_get_contents(__DIR__ . '/../includes/actions.php');
        $this->assertIsString($actionsSource);

        $this->assertMatchesRegularExpression(
            '/public static function purchaseStars[\s\S]*?if \(\$status !== \'Active\' && \$status !== \'Blackout\'\)/',
            $actionsSource
        );
        $this->assertStringContainsString(
            '$starPrice = Economy::publishedStarPrice($season, $status);',
            $actionsSource
        );
        $this->assertMatchesRegularExpression(
            '/public static function lockIn[\s\S]*?if \(\$status !== \'Active\' && \$status !== \'Blackout\'\)/',
            $actionsSource
        );
    }

    public function testPreviewPathUsesPublishedBlackoutPrice(): void
    {
        $apiSource = file_get_contents(__DIR__ . '/../api/index.php');
        $this->assertIsString($apiSource);

        $this->assertMatchesRegularExpression(
            '/function previewStarPurchase\([\s\S]*?if \(\$status !== \'Active\' && \$status !== \'Blackout\'\)[\s\S]*?Economy::publishedStarPrice\(\$season, \$status\)/',
            $apiSource
        );
    }

    public function testEffectiveScoreFieldIsPresentAcrossLeaderboardProfileAndHistorySurfaces(): void
    {
        $apiSource = file_get_contents(__DIR__ . '/../api/index.php');
        $this->assertIsString($apiSource);

        $this->assertMatchesRegularExpression(
            '/function getSeasonDetail\([\s\S]*?AS effective_seasonal_stars/',
            $apiSource
        );
        $this->assertMatchesRegularExpression(
            '/function getLeaderboard\([\s\S]*?AS effective_seasonal_stars/',
            $apiSource
        );
        $this->assertMatchesRegularExpression(
            '/function getProfile\([\s\S]*?effective_seasonal_stars/',
            $apiSource
        );
        $this->assertMatchesRegularExpression(
            '/function getSeasonHistory\([\s\S]*?effective_seasonal_stars/',
            $apiSource
        );
    }

    public function testScorePayloadAlsoExposesSettlementBreakdownFields(): void
    {
        $apiSource = file_get_contents(__DIR__ . '/../api/index.php');
        $this->assertIsString($apiSource);

        $this->assertStringContainsString('$participation[\'payout_seasonal_stars\'] = Economy::settlementPayoutSeasonalStars($participation);', $apiSource);
        $this->assertStringContainsString('$participation[\'payout_source_stars\'] = Economy::settlementPayoutSourceStars($participation);', $apiSource);
    }
}