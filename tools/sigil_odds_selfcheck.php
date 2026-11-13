#!/usr/bin/env php
<?php
/**
 * Too Many Coins - Published Sigil Odds Self-Check
 *
 *   php tools/sigil_odds_selfcheck.php
 *
 * season.sigil_drop_rates is what players are told their drop odds are. It
 * used to be computed from a 1-in-8 sigil-power model the tick engine no longer
 * ran, so it showed a 12.5% per-tick chance against a real 0.35%. Now it is
 * built from Economy::sigilDropOddsForTick(), the function the engine's own
 * evaluateSigilDropForTick() rolls against.
 *
 * This check holds the two together:
 *
 *   - exact: across activity states, inventory and boost pressure, every season
 *     phase and a Legion swarm, every tick the engine treats as an attempt is
 *     one the odds call eligible, and every drop it lands carries exactly the
 *     published chance and tier weights
 *   - empirical: the engine's actual drop frequency and tier mix over many
 *     rolls sit within 5 standard deviations of the published percentages
 *   - the payload: percentages add up, ineligible states publish zero, and the
 *     baseline is SIGIL_DROP_CHANCE_FP rather than the old 1-in-8
 *
 * No server and no database required.
 *
 * Exit: 0 = every check passed, 1 = at least one is wrong.
 */

require_once __DIR__ . '/../includes/economy.php';
require_once __DIR__ . '/../includes/sigil_drops_api.php';

$pass = 0;
$fail = 0;

function check(string $name, bool $ok, $detail = null): void {
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  pass  {$name}\n";
    } else {
        $fail++;
        echo "  FAIL  {$name}";
        if ($detail !== null) {
            echo ' -- ' . (is_string($detail) ? $detail : json_encode($detail));
        }
        echo "\n";
    }
}

function near(float $a, float $b, float $eps = 1e-6): bool {
    return abs($a - $b) <= $eps;
}

$start = 100000;
$season = [
    'season_id' => 7,
    'season_seed' => str_repeat("\x5a", 32),
    'start_time' => $start,
    'end_time' => $start + (int)SEASON_DURATION,
];

// One representative tick per phase, found by walking the season.
$phaseTicks = [];
$step = max(1, intdiv((int)SEASON_DURATION, 2000));
for ($t = $start; $t < $start + (int)SEASON_DURATION; $t += $step) {
    $phase = Economy::sigilSeasonPhase($season, $t);
    if (!isset($phaseTicks[$phase])) $phaseTicks[$phase] = $t;
}
check('every season phase is reachable', count($phaseTicks) === 4, array_keys($phaseTicks));

$players = [
    'active'  => ['player_id' => 11, 'economic_presence_state' => 'Active'],
    'idle'    => ['player_id' => 12, 'economic_presence_state' => 'Idle'],
    'offline' => ['player_id' => 13, 'economic_presence_state' => 'Offline'],
];
$capFull = (int)SIGIL_INVENTORY_DROP_PRESSURE_FULL;
$inventories = [
    'empty'   => null,
    'partial' => ['sigils_t1' => (int)SIGIL_INVENTORY_DROP_PRESSURE_START + 3],
    'full'    => ['sigils_t1' => $capFull],
];
$boosts = ['none' => 0, 'plus30' => 300000, 'plus100' => 1000000];

echo "Engine and published odds agree tick by tick\n";
$mismatches = [];
$attempts = 0;
$drops = 0;
foreach ($phaseTicks as $phase => $baseTick) {
    foreach ([false, true] as $swarm) {
        $s = $season;
        $s['_modifier_event'] = $swarm
            ? ['event_kind' => 'swarm', 'started_tick' => $baseTick, 'ends_tick' => $baseTick + 400]
            : null;
        foreach ($players as $pk => $player) {
            foreach ($inventories as $ik => $inv) {
                foreach ($boosts as $bk => $boost) {
                    for ($t = $baseTick; $t < $baseTick + 400; $t++) {
                        $odds = Economy::sigilDropOddsForTick($s, $player, $t, $inv, $boost);
                        $wasEligible = false;
                        $drop = Economy::evaluateSigilDropForTick($s, $player, $t, $inv, $boost, $wasEligible);
                        $label = "{$phase}/" . ($swarm ? 'swarm' : 'calm') . "/{$pk}/{$ik}/{$bk}@{$t}";
                        if ($wasEligible !== $odds['eligible']) {
                            $mismatches[] = "{$label}: engine eligible=" . var_export($wasEligible, true);
                        }
                        if ($wasEligible) $attempts++;
                        if ($drop === null) continue;
                        $drops++;
                        $meta = $drop['metadata'];
                        if ((int)$meta['effective_drop_chance_fp'] !== (int)$odds['effective_drop_chance_fp']
                            || $meta['tier_weights'] !== $odds['tier_weights']) {
                            $mismatches[] = "{$label}: drop rolled against different odds";
                        }
                    }
                }
            }
        }
    }
}
check('eligibility and per-drop odds match the published odds on every tick',
    $mismatches === [], array_slice($mismatches, 0, 5));
check('the sweep exercised real attempts and drops', $attempts > 1000 && $drops > 10,
    "{$attempts} attempts, {$drops} drops");

echo "\nPublished payload\n";
$midTick = $phaseTicks[(string)SIGIL_SEASON_PHASE_MID] ?? $start;
$base = sigilDropRatesPayload(Economy::sigilDropOddsForTick($season, $players['active'], $midTick, null, 0), 'active_player_no_boost');
check('baseline per-tick chance is SIGIL_DROP_CHANCE_FP, not the retired 1-in-8 model',
    near($base['base_percent'], SIGIL_DROP_CHANCE_FP / FP_SCALE * 100), $base['base_percent']);
check('base_one_in agrees with base_percent',
    $base['base_one_in'] === (int)round(FP_SCALE / SIGIL_DROP_CHANCE_FP), $base['base_one_in']);
check('tier chances add up to the base chance',
    near(array_sum(array_column($base['tiers'], 'chance_percent')), $base['base_percent'], 1e-4));
check('conditional tier odds add up to 100%',
    near(array_sum(array_column($base['tiers'], 'conditional_percent')), 100.0, 1e-4));
check('only tiers available in the phase are published',
    array_column($base['tiers'], 'tier') === Economy::sigilAvailableTiersForPhase(SIGIL_SEASON_PHASE_MID),
    array_column($base['tiers'], 'tier'));

$offline = sigilDropRatesPayload(Economy::sigilDropOddsForTick($season, $players['offline'], $midTick, null, 0), 'player');
check('offline publishes ineligible and zero',
    !$offline['eligible'] && $offline['base_percent'] == 0 && $offline['base_one_in'] === 0);

$full = sigilDropRatesPayload(Economy::sigilDropOddsForTick($season, $players['active'], $midTick, $inventories['full'], 0), 'player');
check('a full inventory publishes zero', !$full['eligible'] && $full['base_percent'] == 0);

$blackoutTick = $phaseTicks[(string)SIGIL_SEASON_PHASE_BLACKOUT] ?? null;
if ($blackoutTick !== null) {
    $bo = sigilDropRatesPayload(Economy::sigilDropOddsForTick($season, $players['active'], $blackoutTick, null, 0), 'player');
    check('blackout publishes zero', !$bo['eligible'] && $bo['base_percent'] == 0 && $bo['season_phase'] === SIGIL_SEASON_PHASE_BLACKOUT);
}

$boosted = sigilDropRatesPayload(Economy::sigilDropOddsForTick($season, $players['active'], $midTick, null, 1000000), 'player');
check('a +100% boost publishes a tenth of the baseline',
    near($boosted['base_percent'], $base['base_percent'] / 10, 1e-4), $boosted['base_percent']);

echo "\nEmpirical frequency matches the published odds\n";
// Many players at one MID tick: the gate roll is keyed on player id, so this
// is n independent draws against one published chance.
$n = 200000;
$landed = 0;
$byTier = [];
for ($pid = 1; $pid <= $n; $pid++) {
    $drop = Economy::evaluateSigilDropForTick($season, ['player_id' => $pid, 'economic_presence_state' => 'Active'], $midTick, null, 0);
    if ($drop === null) continue;
    $landed++;
    $byTier[(int)$drop['tier']] = ($byTier[(int)$drop['tier']] ?? 0) + 1;
}
$p = $base['base_percent'] / 100;
$expected = $n * $p;
$sd = sqrt($n * $p * (1 - $p));
check(sprintf('drop rate %.4f%% is within 5 sd of published %.4f%%', 100 * $landed / $n, $base['base_percent']),
    abs($landed - $expected) <= 5 * $sd, "landed {$landed}, expected " . round($expected, 1));

foreach ($base['tiers'] as $tier) {
    $q = $tier['conditional_percent'] / 100;
    $got = $byTier[$tier['tier']] ?? 0;
    $exp = $landed * $q;
    $tsd = sqrt(max(1e-9, $landed * $q * (1 - $q)));
    check(sprintf('tier %d share within 5 sd of published %.2f%%', $tier['tier'], $tier['conditional_percent']),
        abs($got - $exp) <= 5 * $tsd + 1, "got {$got}, expected " . round($exp, 1));
}
$unpublished = array_diff(array_keys($byTier), array_column($base['tiers'], 'tier'));
check('no drop landed in an unpublished tier', $unpublished === [], array_values($unpublished));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
