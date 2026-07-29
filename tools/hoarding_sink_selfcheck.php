<?php
/**
 * Too Many Coins - Hoarding Sink Self-Check
 *
 * The sink spent its whole life silently doing nothing: the flag was 0
 * everywhere, and even with the flag on, whole-coin-per-tick arithmetic plus a
 * cap that divided by FP_SCALE twice clamped every result to zero. Nothing
 * failed - it just quietly wasn't a mechanic.
 *
 * This drives the real Economy::calculateHoardingSinkFpPerTick and asserts the
 * curve has the shape the design calls for.
 *
 * Usage:  php tools/hoarding_sink_selfcheck.php [--verbose]
 * Exit:   0 = pass, 1 = fail
 */

putenv('TMC_TICK_REAL_SECONDS=5');
require_once __DIR__ . '/../includes/economy.php';

$verbose = in_array('--verbose', $argv, true);

// The values migration_20260729b writes.
function sinkSeason(array $over = []): array {
    return array_merge([
        'hoarding_sink_enabled'         => 1,
        'hoarding_safe_hours'           => 12,
        'hoarding_safe_min_coins'       => 20000,
        'hoarding_tier1_excess_cap'     => 50000,
        'hoarding_tier2_excess_cap'     => 200000,
        'hoarding_tier1_rate_hourly_fp' => 10000,
        'hoarding_tier2_rate_hourly_fp' => 30000,
        'hoarding_tier3_rate_hourly_fp' => 60000,
        'hoarding_sink_cap_ratio_fp'    => 300000,
        'hoarding_idle_multiplier_fp'   => 1000000,
        'start_time' => 0, 'end_time' => 241920, 'blackout_time' => 190080,
    ], $over);
}

const GROSS_PER_MIN = 30;
$grossFp   = (int)(GROSS_PER_MIN / 12 * FP_SCALE);   // 12 ticks/min at 5s
$grossHour = GROSS_PER_MIN * 60;
$ticksHour = 720;

function sinkPerHour(int $held, array $season, string $activity = 'Active', ?int $grossFp = null): float {
    $player = [
        'current_game_time' => 1000,
        'activity_state' => $activity,
        'participation_enabled' => 1,
        'online_current' => 1,
        'last_seen_at' => date('Y-m-d H:i:s'),
    ];
    $fp = Economy::calculateHoardingSinkFpPerTick($season, $player, ['coins' => $held], $grossFp, 'MID');
    return ($fp / FP_SCALE) * 720;
}

$failures = [];
$rows = [];
foreach ([15000, 25000, 40000, 60000, 80000, 300000, 1000000] as $held) {
    $perHour = sinkPerHour($held, sinkSeason(), 'Active', $grossFp);
    $rows[$held] = $perHour;
    if ($verbose) {
        printf("  held %-10s sink/hr %-8s (%.0f%% of gross)\n",
            number_format($held), number_format((int)$perHour), $perHour / $grossHour * 100);
    }
}

echo "Hoarding sink self-check\n" . str_repeat('-', 58) . "\n";

// 1. The mechanic must actually do something. This is the regression that
//    mattered: for its entire life the answer here was zero.
if (max($rows) <= 0) {
    $failures[] = 'sink is zero at every balance - the mechanic is inert again';
}

// 2. A player inside the safe buffer is never touched.
if ($rows[15000] != 0) {
    $failures[] = "a player under the 12h buffer is being drained ({$rows[15000]}/hr)";
}

// 3. Monotonic: holding more is never cheaper.
$prev = null;
foreach ($rows as $held => $v) {
    if ($prev !== null && $v < $prev - 0.001) {
        $failures[] = "sink decreases as holdings rise (at {$held})";
    }
    $prev = $v;
}

// 4. The ceiling holds. The sink must never be able to reverse progress -
//    there should be no balance at which playing normally makes you poorer.
$capPct = $rows[1000000] / $grossHour;
if ($capPct > 0.31) {
    $failures[] = sprintf('sink reaches %.0f%% of gross, above the 30%% ceiling', $capPct * 100);
}
if ($capPct < 0.05) {
    $failures[] = sprintf('sink tops out at only %.1f%% of gross - too weak to be a mechanic', $capPct * 100);
}

// 5. Stepping away must not be punished harder than staying. Idle players
//    already earn 30% of active UBI; a >1 idle multiplier taxes them twice.
$idle = sinkPerHour(300000, sinkSeason(), 'Idle', $grossFp);
$active = $rows[300000];
if ($idle > $active + 0.001) {
    $failures[] = sprintf('idle players are drained harder than active (%.0f vs %.0f per hour)', $idle, $active);
}

// 6. Disabling the flag must still fully disable it.
if (sinkPerHour(1000000, sinkSeason(['hoarding_sink_enabled' => 0]), 'Active', $grossFp) != 0) {
    $failures[] = 'hoarding_sink_enabled = 0 no longer disables the sink';
}

echo str_repeat('-', 58) . "\n";
if (empty($failures)) {
    printf("Result: PASS - ramps from 0 to %.0f%% of gross, ceiling holds.\n", $capPct * 100);
    exit(0);
}
echo "Result: FAIL\n";
foreach ($failures as $f) { echo "  - {$f}\n"; }
exit(1);
