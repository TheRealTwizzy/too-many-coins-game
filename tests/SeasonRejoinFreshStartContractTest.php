<?php

use PHPUnit\Framework\TestCase;

class SeasonRejoinFreshStartContractTest extends TestCase
{
    private function actionsSource(): string
    {
        $source = file_get_contents(__DIR__ . '/../includes/actions.php');
        $this->assertIsString($source);
        return $source;
    }

    private function apiSource(): string
    {
        $source = file_get_contents(__DIR__ . '/../api/index.php');
        $this->assertIsString($source);
        return $source;
    }

    public function testSeasonJoinFreshStartResetClearsCurrentRunFields(): void
    {
        $actions = $this->actionsSource();

        $this->assertStringContainsString('resetSeasonParticipationForFreshStart', $actions);
        $this->assertStringContainsString('sigil_drops_total = 0', $actions);
        $this->assertStringContainsString('participation_time_total = 0', $actions);
        $this->assertStringContainsString('active_ticks_total = 0', $actions);
        $this->assertStringContainsString('lock_in_effect_tick = NULL', $actions);
        $this->assertStringContainsString('lock_in_snapshot_seasonal_stars = NULL', $actions);
        $this->assertStringContainsString('first_joined_at = ?', $actions);
    }

    public function testSeasonJoinAndLockInClearAuxiliaryRunState(): void
    {
        $actions = $this->actionsSource();

        $this->assertStringContainsString('clearSeasonRunAuxiliaryState', $actions);
        $this->assertStringContainsString('DELETE FROM active_boosts WHERE player_id = ? AND season_id = ?', $actions);
        $this->assertStringContainsString('DELETE FROM active_freezes', $actions);
        $this->assertStringContainsString('DELETE FROM player_season_vault WHERE player_id = ? AND season_id = ?', $actions);
    }

    public function testApiDerivesCurrentRunBoundaryFromFirstJoinedAt(): void
    {
        $api = $this->apiSource();

        $this->assertStringContainsString('function getSeasonRunStartTick', $api);
        $this->assertStringContainsString("participation['first_joined_at']", $api);
        $this->assertStringContainsString('SELECT first_joined_at', $api);
    }

    public function testApiFiltersImmutableHistoryToCurrentRun(): void
    {
        $api = $this->apiSource();

        $this->assertStringContainsString('created_tick >= ?', $api, 'Theft status should ignore prior-run attempts.');
        $this->assertStringContainsString('drop_tick >= ?', $api, 'Recent drops should ignore prior-run history.');
        $this->assertStringContainsString('activated_tick >= ?', $api, 'Boost/freeze readers should ignore prior-run effects.');
        $this->assertStringContainsString('getActiveBoosts($player, $participation)', $api);
        $this->assertStringContainsString('getRecentSigilDrops($player, $participation)', $api);
    }
}