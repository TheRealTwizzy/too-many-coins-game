<?php
/**
 * Too Many Coins - Tick Health Self-Check
 *
 * game_state.timing.tick_health tells the client whether the player's season
 * is actually being processed, so the payout effect only plays when coins
 * really landed (room tasks E5/E6, docs/economy-bugs-rootcause.md). When a
 * tick batch keeps rolling back, last_processed_tick stops moving while game
 * time keeps going; that gap is the signal.
 *
 * Contract:
 *   tick_health = {
 *     season_last_processed_tick: int|null   null when not in a season
 *     lag_ticks: int|null                    ticks game time is ahead of it
 *     degraded: bool                         lag_ticks > TMC_TICK_HEALTH_MAX_LAG_TICKS (2)
 *   }
 *
 * Usage:  php tools/tick_health_selfcheck.php [--verbose]
 * Exit:   0 = pass, 1 = fail
 */

require_once __DIR__ . '/../includes/tick_health.php';

$verbose = in_array('--verbose', $argv, true);
$pass = 0;
$fail = 0;

function check(string $name, bool $ok, $detail = null): void {
    global $pass, $fail, $verbose;
    if ($ok) {
        $pass++;
        if ($verbose) {
            echo "  pass  {$name}\n";
        }
        return;
    }
    $fail++;
    echo "  FAIL  {$name}\n";
    if ($detail !== null) {
        echo "        " . json_encode($detail) . "\n";
    }
}

function season(array $overrides = []): array {
    return array_merge([
        'season_id' => 3,
        'start_time' => 1000,
        'end_time' => 2000,
        'last_processed_tick' => 1500,
        'expiration_finalized' => 0,
    ], $overrides);
}

echo "Tick health self-check\n\n";

$h = tmc_tick_health(null, 1500);
check('no season: all season fields null, not degraded',
    $h === ['season_last_processed_tick' => null, 'lag_ticks' => null, 'degraded' => false], $h);

$h = tmc_tick_health(season(), 1500);
check('caught up: lag 0, healthy', $h['lag_ticks'] === 0 && $h['degraded'] === false, $h);
check('reports the season last_processed_tick', $h['season_last_processed_tick'] === 1500, $h);

$h = tmc_tick_health(season(), 1502);
check('two ticks behind is still healthy (worker runs just after the boundary)', $h['lag_ticks'] === 2 && $h['degraded'] === false, $h);

$h = tmc_tick_health(season(), 1503);
check('three ticks behind is degraded', $h['lag_ticks'] === 3 && $h['degraded'] === true, $h);

$h = tmc_tick_health(season(['last_processed_tick' => 1000]), 1900);
check('a season stuck since its start is degraded', $h['lag_ticks'] === 900 && $h['degraded'] === true, $h);

$h = tmc_tick_health(season(['last_processed_tick' => 1000]), 900);
check('before the season starts there is no lag', $h['lag_ticks'] === 0 && $h['degraded'] === false, $h);

$h = tmc_tick_health(season(['last_processed_tick' => 2000]), 2600);
check('lag is measured against end_time, not game time, after the season ends', $h['lag_ticks'] === 0 && $h['degraded'] === false, $h);

// Finalizing expiration sets last_processed_tick to the game time it ran at,
// past end_time. Reporting that jump would read as one more payout (E6 review).
$h = tmc_tick_health(season(['last_processed_tick' => 2600, 'expiration_finalized' => 1]), 2600);
check('the reported tick stops at the last payable tick, so finalizing is not a payout', $h['season_last_processed_tick'] === 1999, $h);
// The last real batch can only reach end_time - 1: at end_time the engine
// runs expiration instead of UBI. So that batch then finalizing must not move.
$before = tmc_tick_health(season(['last_processed_tick' => 1999]), 2000);
$after = tmc_tick_health(season(['last_processed_tick' => 2000, 'expiration_finalized' => 1]), 2000);
check('last batch then expiration reports the same tick',
    $before['season_last_processed_tick'] === $after['season_last_processed_tick'], [$before, $after]);

$h = tmc_tick_health(season(['last_processed_tick' => 1990, 'expiration_finalized' => 1]), 2600);
check('a finalized season is never degraded', $h['degraded'] === false && $h['lag_ticks'] === 0, $h);

$h = tmc_tick_health(season(['last_processed_tick' => 1600]), 1500);
check('a processed tick ahead of game time is not negative lag', $h['lag_ticks'] === 0 && $h['degraded'] === false, $h);

$h = tmc_tick_health(season(['last_processed_tick' => '1500', 'start_time' => '1000', 'end_time' => '2000']), 1504);
check('string columns from PDO are handled as ints', $h['season_last_processed_tick'] === 1500 && $h['lag_ticks'] === 4, $h);

echo "\nWiring\n";
$api = (string)file_get_contents(__DIR__ . '/../api/index.php');
check('api/index.php loads includes/tick_health.php', strpos($api, "require_once __DIR__ . '/../includes/tick_health.php';") !== false);
check('game_state publishes timing.tick_health from the joined season',
    (bool)preg_match('/\$state\[\'timing\'\]\[\'tick_health\'\]\s*=\s*tmc_tick_health\(\s*\$joinedSeason/', $api));
check('logged-out game_state still publishes an empty tick_health',
    (bool)preg_match('/\$state\[\'timing\'\]\[\'tick_health\'\]\s*=\s*tmc_tick_health\(\s*null/', $api));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
