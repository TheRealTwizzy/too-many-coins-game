<?php
/**
 * Too Many Coins - Star Price Self-Check
 *
 * Guards against the market layer silently going inert again.
 *
 * The affordability bias used to be applied AFTER the velocity clamp, to the
 * value that was then written back as current_star_price - which the clamp reads
 * as its reference on the next tick. So the bias compounded while the up-clamp
 * could only add max(1, intdiv(prev * 83, 1e6)), which is 1 for any price below
 * 12,048. That produced the fixed point p = floor(0.97 * (p + 1)) = 32, so the
 * price converged to 32 coins at EVERY supply level and starprice_table,
 * star_price_cap and the active-vs-idle supply pipeline had no observable effect.
 *
 * This drives the real Economy::calculateStarPrice and asserts the settled price
 * tracks supply.
 *
 * Usage:  php tools/star_price_selfcheck.php [--verbose]
 * Exit:   0 = pass, 1 = fail
 */

putenv('TMC_TICK_REAL_SECONDS=5');
require_once __DIR__ . '/../includes/economy.php';

$verbose = in_array('--verbose', $argv, true);

$table = json_encode([
    ['m' => 0,       'price' => 100],
    ['m' => 25000,   'price' => 220],
    ['m' => 100000,  'price' => 520],
    ['m' => 500000,  'price' => 1600],
    ['m' => 2000000, 'price' => 4200],
]);

const BIAS_FP = 970000;

function settlePrice(int $supply, string $table, int $ticks = 8000, int $modelVersion = 2): int {
    $season = [
        'starprice_table'              => $table,
        'star_price_cap'               => 6000,
        'starprice_max_upstep_fp'      => 1000,
        'starprice_max_downstep_fp'    => 12960,
        'market_affordability_bias_fp' => BIAS_FP,
        'starprice_active_only'        => 1,
        'effective_price_supply'       => $supply,
        'current_star_price'           => 100,
        'starprice_model_version'      => $modelVersion,
    ];
    for ($i = 0; $i < $ticks; $i++) {
        $season['current_star_price'] = Economy::calculateStarPrice($season);
    }
    return (int)$season['current_star_price'];
}

$supplies = [0, 25000, 100000, 500000, 2000000];
$settled  = [];
$failures = [];

echo "Star price self-check\n" . str_repeat('-', 60) . "\n";

foreach ($supplies as $supply) {
    $raw      = (int)Economy::piecewiseLinear($supply, json_decode($table, true), 'm', 'price');
    $expected = max(1, intdiv($raw * BIAS_FP, 1000000));
    $actual   = settlePrice($supply, $table);
    $settled[$supply] = $actual;

    if ($actual !== $expected) {
        $failures[] = "supply {$supply}: settled at {$actual}, expected {$expected} (table {$raw} x bias)";
    }
    if ($verbose) {
        printf("  supply %-9d table %-6d expected %-6d settled %-6d %s\n",
            $supply, $raw, $expected, $actual, $actual === $expected ? 'ok' : 'MISMATCH');
    }
}

// The regression signature: every supply level collapsing to one value.
if (count(array_unique($settled)) === 1) {
    $only = (int)reset($settled);
    $failures[] = "price settled at {$only} for EVERY supply level - the clamp/bias "
                . "ordering has regressed and the market surface is inert";
}

// And it must be monotonic in supply: more coins chasing stars means dearer stars.
$prev = null;
foreach ($supplies as $supply) {
    if ($prev !== null && $settled[$supply] < $prev) {
        $failures[] = "price is not monotonic in supply (supply {$supply} settled below the previous level)";
    }
    $prev = $settled[$supply];
}

// v1 is the legacy ordering, kept only so seasons already in flight are not
// repriced underneath their players. It is SUPPOSED to collapse to 32 - assert
// that too, so the compatibility branch cannot be silently lost or inverted.
$v1 = [];
foreach ($supplies as $supply) {
    $v1[$supply] = settlePrice($supply, $table, 8000, STARPRICE_MODEL_V1_BIAS_AFTER_CLAMP);
}
if ($verbose) {
    echo "\n  legacy v1 (expected to collapse): " . implode(', ', $v1) . "\n";
}
if (count(array_unique($v1)) !== 1 || (int)reset($v1) !== 32) {
    $failures[] = 'legacy v1 seasons no longer reproduce the old behaviour ('
                . implode(', ', $v1) . ') - in-flight seasons would be repriced';
}

echo str_repeat('-', 60) . "\n";
if (empty($failures)) {
    echo "Result: PASS - price tracks supply (" . implode(', ', $settled) . ")\n";
    exit(0);
}
echo "Result: FAIL\n";
foreach ($failures as $f) {
    echo "  - {$f}\n";
}
echo "\nCheck the order of operations in Economy::calculateStarPrice: the\n";
echo "affordability bias must be applied to the raw table target, BEFORE the\n";
echo "velocity clamp compares against the previously published price.\n";
exit(1);
