<?php

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/economy.php';
require_once __DIR__ . '/SimulationConfigPreflight.php';
require_once __DIR__ . '/SimulationSeason.php';
require_once __DIR__ . '/MetricsCollector.php';

class ContractSimulator
{
    public static function run(string $seed = 'phase1', array $options = []): array
    {
        $preflight = SimulationConfigPreflight::resolve([
            'seed' => $seed,
            'season_id' => 1,
            'simulator' => 'A',
            'run_label' => $options['run_label'] ?? null,
            'artifact_dir' => $options['preflight_artifact_dir'] ?? '',
            'debug_allow_inactive_candidate' => !empty($options['debug_allow_inactive_candidate']),
        ]);
        $season = $preflight['season'];
        $player = [
            'player_id' => 1,
            'participation_enabled' => 1,
            'activity_state' => 'Active',
            'economic_presence_state' => 'Active',
            'current_game_time' => $season['start_time'],
            'online_current' => 1,
        ];
        $participation = [
            'coins' => 0,
            'coins_fractional_fp' => 0,
            'seasonal_stars' => 100,
            'sigils_t1' => 1,
            'sigils_t2' => 2,
            'sigils_t3' => 0,
            'sigils_t4' => 1,
            'sigils_t5' => 0,
            'sigils_t6' => 1,
            'active_ticks_total' => 7200,
        ];

        $checks = [];

        $rate = Economy::calculateRateBreakdown($season, $player, $participation, 0, false);
        $checks[] = [
            'name' => 'active_rate_breakdown_nonzero',
            'passed' => (int)$rate['gross_rate_fp'] > 0 && (int)$rate['net_rate_fp'] > 0,
            'details' => $rate,
        ];

        $blackoutSeason = $season;
        $blackoutSeason['status'] = 'Blackout';
        $blackoutSeason['computed_status'] = 'Blackout';
        $blackoutSeason['blackout_star_price_snapshot'] = 321;
        $player['current_game_time'] = (int)$blackoutSeason['blackout_time'];
        $blackoutRate = Economy::calculateRateBreakdown($blackoutSeason, $player, $participation, 0, false);
        $checks[] = [
            'name' => 'blackout_zero_accrual',
            'passed' => (int)$blackoutRate['gross_rate_fp'] === 0 && (int)$blackoutRate['net_rate_fp'] === 0,
            'details' => $blackoutRate,
        ];

        $checks[] = [
            'name' => 'published_star_price_uses_blackout_snapshot',
            'passed' => Economy::publishedStarPrice($blackoutSeason, 'Blackout') === 321,
            'details' => ['published_price' => Economy::publishedStarPrice($blackoutSeason, 'Blackout')],
        ];

        $dropPlayer = $player;
        $dropPlayer['current_game_time'] = (int)$season['start_time'];
        $dropPlayer['economic_presence_state'] = 'Active';
        $firstDrop = Economy::evaluateSigilDropForTick($season, $dropPlayer, (int)$season['start_time'] + 55);
        $secondDrop = Economy::evaluateSigilDropForTick($season, $dropPlayer, (int)$season['start_time'] + 55);
        $checks[] = [
            'name' => 'sigil_drop_is_deterministic',
            'passed' => $firstDrop === $secondDrop,
            'details' => ['drop' => $firstDrop],
        ];

        $noBlackoutDrop = Economy::evaluateSigilDropForTick($blackoutSeason, $dropPlayer, (int)$blackoutSeason['blackout_time']);
        $checks[] = [
            'name' => 'blackout_has_no_rng_sigil_drop',
            'passed' => $noBlackoutDrop === null,
            'details' => ['drop' => $noBlackoutDrop],
        ];

        $lockIn = Economy::computeEarlyLockInPayout(100, [1, 2, 0, 1, 0], [50, 250, 1000, 3000, 9000]);
        $checks[] = [
            'name' => 'lock_in_payout_adds_sigil_refund_then_converts',
            'passed' => (int)$lockIn['sigil_refund_stars'] === 3550 && (int)$lockIn['total_seasonal_stars'] === 3650,
            'details' => $lockIn,
        ];

        $natural = Economy::computeNaturalExpiryPayout(200, 7200, 2, 0, true);
        $checks[] = [
            'name' => 'natural_expiry_payout_includes_participation_and_placement',
            'passed' => (int)$natural['participation_bonus'] > 0 && (int)$natural['placement_bonus'] === (int)(PLACEMENT_BONUS[2] ?? 0),
            'details' => $natural,
        ];

        $effectiveLocked = Economy::effectiveSeasonalStars([
            'lock_in_effect_tick' => 10,
            'lock_in_snapshot_seasonal_stars' => 555,
            'seasonal_stars' => 999,
        ]);
        $effectiveEnd = Economy::effectiveSeasonalStars([
            'end_membership' => 1,
            'final_seasonal_stars' => 444,
            'seasonal_stars' => 999,
        ]);
        $checks[] = [
            'name' => 'effective_score_semantics_match_runtime',
            'passed' => $effectiveLocked === 555 && $effectiveEnd === 444,
            'details' => ['locked' => $effectiveLocked, 'end' => $effectiveEnd],
        ];

        $payload = MetricsCollector::buildContractOutput($seed, $checks);
        $payload['config_audit'] = [
            'status' => (string)$preflight['report']['status'],
            'artifact_paths' => (array)$preflight['artifact_paths'],
            'requested_candidate_changes' => (array)$preflight['report']['requested_candidate_changes'],
        ];

        return $payload;
    }
}
