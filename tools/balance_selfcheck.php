<?php
/**
 * Too Many Coins - Balance Self-Check
 *
 * Guards two design properties that are easy to break with a one-line tuning
 * change and impossible to notice afterwards, because nothing errors - the
 * mechanics just quietly stop being worth using.
 *
 * 1. THEFT MUST BE WORTH CONSIDERING.
 *
 *    Success is  p = spend / (spend + M * requested),  capped, and the spend is
 *    consumed whether the attempt lands or not. So a theft can never pay for
 *    itself in isolation; it is a cost paid to cost someone else more. The
 *    measure that matters is the net swing against the target:
 *
 *        swing = 2 * p * requested - spend
 *
 *    positive only when  requested * (2 - M) > spend.  At M = 3 that has no
 *    solution at any pair of tiers - theft was not mistuned, it was
 *    arithmetically dead, and every legal play lost at least 40% of the stake.
 *
 * 2. SPENDING MUST BEAT HOARDING.
 *
 *    Lock-in refunds sigils. If the refund equals the tactical utility value,
 *    settling is exactly as good as spending and strictly safer, so the optimal
 *    line is to never use a verb. T6 always had refund < utility; T1-T5 did not.
 *
 * Usage:  php tools/balance_selfcheck.php [--verbose]
 * Exit:   0 = pass, 1 = fail
 */

require_once __DIR__ . '/../includes/config.php';

$verbose = in_array('--verbose', $argv, true);
$pass = 0;
$fail = 0;

function check(string $name, bool $ok, $detail = null): void {
    global $pass, $fail, $verbose;
    if ($ok) {
        $pass++;
        if ($verbose) echo "  ok   {$name}\n";
    } else {
        $fail++;
        echo "  FAIL {$name}";
        if ($detail !== null) echo "  -> " . json_encode($detail);
        echo "\n";
    }
}

/**
 * Mirrors Actions::calculateTheftSuccessChanceFp, which is private, and the
 * copy inlined in the sigil_theft_preview endpoint. Both read the same two
 * constants, so a tuning change moves all three together - but if the *formula*
 * is ever changed in one place and not the other, the preview starts lying to
 * the player about their odds. Worth knowing that duplication exists.
 */
function successFp(int $spend, int $requested): int {
    if ($spend <= 0 || $requested <= 0) return 0;
    $denominator = $spend + ((int)SIGIL_THEFT_VALUE_PRESSURE_MULTIPLIER * $requested);
    if ($denominator <= 0) return 0;
    return (int)min((int)SIGIL_THEFT_SUCCESS_CAP_FP, intdiv($spend * FP_SCALE, $denominator));
}

function p(int $spend, int $requested): float {
    return successFp($spend, $requested) / FP_SCALE;
}

/** My gain, minus their loss, minus what it cost me. */
function swing(int $spend, int $requested): float {
    return 2 * p($spend, $requested) * $requested - $spend;
}

echo "Balance self-check\n";
echo str_repeat('-', 68) . "\n";

$U = SIGIL_UTILITY_VALUE_BY_TIER;
$R = SIGIL_REFERENCE_STARS_BY_TIER;

/* ---------------- theft ---------------- */

// There must exist at least one play worth making, or the verb is dead content.
{
    $best = null;
    foreach (SIGIL_THEFT_SPEND_TIERS as $st) {
        foreach (SIGIL_THEFT_TARGET_TIERS as $rt) {
            $s = swing((int)$U[$st], (int)$U[$rt]);
            if ($best === null || $s > $best['swing']) {
                $best = ['spend' => $st, 'target' => $rt, 'swing' => $s];
            }
        }
    }
    check('some theft is worth making (positive net swing exists)',
        $best !== null && $best['swing'] > 0, $best);
}

// Punching up should pay. That is the catch-up role the verb exists to play.
foreach (SIGIL_THEFT_SPEND_TIERS as $st) {
    foreach (SIGIL_THEFT_TARGET_TIERS as $rt) {
        if ((int)$U[$rt] <= (int)$U[$st]) continue;
        check("punching up pays: T{$st} at T{$rt}",
            swing((int)$U[$st], (int)$U[$rt]) > 0,
            ['swing' => round(swing((int)$U[$st], (int)$U[$rt]))]);
    }
}

// Punching down must NOT pay, or the strong farm the weak.
foreach (SIGIL_THEFT_SPEND_TIERS as $st) {
    foreach (SIGIL_THEFT_TARGET_TIERS as $rt) {
        if ((int)$U[$rt] >= (int)$U[$st]) continue;
        check("punching down does not pay: T{$st} at T{$rt}",
            swing((int)$U[$st], (int)$U[$rt]) < 0,
            ['swing' => round(swing((int)$U[$st], (int)$U[$rt]))]);
    }
}

// Aggression must always cost the aggressor something personally, so theft
// stays a trade rather than a free income stream.
foreach (SIGIL_THEFT_SPEND_TIERS as $st) {
    foreach (SIGIL_THEFT_TARGET_TIERS as $rt) {
        $ev = p((int)$U[$st], (int)$U[$rt]) * (int)$U[$rt] - (int)$U[$st];
        check("theft costs the attacker: T{$st} at T{$rt}", $ev < 0, ['ev' => round($ev)]);
    }
}

// The cap has to actually bind somewhere, or it is decorative.
{
    $capHit = false;
    foreach (SIGIL_THEFT_SPEND_TIERS as $st) {
        foreach (SIGIL_THEFT_TARGET_TIERS as $rt) {
            if (successFp((int)$U[$st], (int)$U[$rt]) >= (int)SIGIL_THEFT_SUCCESS_CAP_FP) $capHit = true;
        }
    }
    check('the success cap binds on at least one play', $capHit);
}

/* ---------------- the low-tier floor ---------------- */

// A T1/T2 Valefor sigil must be stakeable on its family verb. The old T3
// gate made low-tier Larceny inert; the success formula already prices a
// small stake at proportionally small odds, so no gate is needed.
check('theft stakes open at the floor (T1 and T2 spendable)',
    in_array(1, SIGIL_THEFT_SPEND_TIERS, true) && in_array(2, SIGIL_THEFT_SPEND_TIERS, true),
    SIGIL_THEFT_SPEND_TIERS);

// The deflect tier and the windowed ward table are mutually exclusive
// branches in activateWard; a tier in both would make behaviour depend on
// branch order.
check('ward deflect tier is not also a windowed ward tier',
    !isset(WARD_UNITS_X100_BY_TIER[WARD_DEFLECT_TIER]),
    ['deflect_tier' => WARD_DEFLECT_TIER]);

// Every tier does something as a Michael sigil: T1 deflects, the rest are
// windowed. A tier in neither set is inert - which is how this started.
{
    $inert = [];
    for ($t = 1; $t <= 6; $t++) {
        if ($t !== (int)WARD_DEFLECT_TIER && !isset(WARD_UNITS_X100_BY_TIER[$t])) $inert[] = $t;
    }
    check('no ward tier is inert (deflect or windowed)', $inert === [], $inert);
}

/* ---------------- hoard vs spend ---------------- */

// Every tier a verb can actually spend must be worth more spent than settled.
$spendableTiers = array_values(array_unique(array_merge(
    SIGIL_THEFT_SPEND_TIERS,
    SIGIL_FREEZE_SPEND_TIERS,
    SIGIL_MELT_SPEND_TIERS
)));
sort($spendableTiers);

foreach ($spendableTiers as $t) {
    check("spending beats settling at T{$t}",
        (int)$U[$t] > (int)$R[$t],
        ['utility' => $U[$t], 'refund' => $R[$t]]);
}

// The low tiers are taxed at the same ratio as everything else. Leaving them
// untaxed put a cliff at the T2->T3 boundary, and it also assumed nothing
// spends them - which stops being true the moment the family system is enabled,
// since Yield, Time, Market and Sight all spend from T1 upward.
foreach ([1, 2] as $t) {
    check("T{$t} is taxed like every other tier",
        (int)$R[$t] < (int)$U[$t],
        ['utility' => $U[$t], 'refund' => $R[$t]]);
}

// Five T1s must settle for exactly one T2, as they do at utility price. The
// starter grant is five T1s and its lock-in offset only cancels cleanly if
// combining them changes nothing.
check('five T1 settle for exactly one T2',
    5 * (int)$R[1] === (int)$R[2],
    ['5xT1' => 5 * $R[1], 'T2' => $R[2]]);

// Refunds must still be monotonic in tier, or a lower tier settles for more
// than a higher one and combining upward becomes a loss.
{
    $monotonic = true;
    for ($t = 2; $t <= 6; $t++) {
        if ((int)$R[$t] <= (int)$R[$t - 1]) $monotonic = false;
    }
    check('refund value still rises with tier', $monotonic, $R);
}

// Combining is value-destructive on purpose, and always has been: five T4s are
// worth 15000 in utility and become a T5 worth 9000. You combine to reach a
// capability - T4 unlocks freeze, T5 unlocks melt, T6 exists only via the forge
// - not to gain value. An earlier version of this file asserted the opposite
// and failed against a correctly-tuned table, which is worth remembering: the
// combine ratio is a sink, so an assertion that it preserves value will always
// be wrong.
//
// What must hold is that the loss is not *worse* at settle price than at
// utility price, or settling would beat combining by more than the design
// intends at the tiers a new player is actually holding.
{
    $ok = true;
    $detail = [];
    for ($t = 1; $t <= 5; $t++) {
        $utilityRetained = (5 * (int)$U[$t]) > 0 ? (int)$U[$t + 1] / (5 * (int)$U[$t]) : 1.0;
        $settleRetained  = (5 * (int)$R[$t]) > 0 ? (int)$R[$t + 1] / (5 * (int)$R[$t]) : 1.0;
        $detail["T{$t}->T" . ($t + 1)] = sprintf('utility %.0f%%, settle %.0f%%',
            $utilityRetained * 100, $settleRetained * 100);
        // Allow a small tolerance: the settle ladder is a scaled copy of the
        // utility ladder, so rounding can move a step by a point or two.
        if ($settleRetained < $utilityRetained - 0.05) $ok = false;
    }
    check('combining is no more punitive at settle price than at utility price', $ok, $detail);
}

// The tax applied to tactical tiers should be uniform, so no single tier is
// quietly the one worth hoarding.
{
    $ratios = [];
    foreach ($spendableTiers as $t) {
        $ratios[$t] = round((int)$R[$t] / (int)$U[$t], 3);
    }
    $spread = max($ratios) - min($ratios);
    check('the settle-vs-utility ratio is uniform across tactical tiers',
        $spread <= 0.05, $ratios);
}

/* ---------------- legion critical mass ---------------- */

// Every tier must map to an event window, and the windows must rise with the
// tier - a bigger sacrifice buys a longer event, never a shorter one.
{
    $missing = [];
    for ($t = 1; $t <= 6; $t++) {
        if ((int)(LEGION_EVENT_UNITS_X100_BY_TIER[$t] ?? 0) <= 0) $missing[] = $t;
    }
    check('legion: every tier has an event duration', $missing === [], $missing);

    $monotonic = true;
    for ($t = 2; $t <= 6; $t++) {
        if ((int)LEGION_EVENT_UNITS_X100_BY_TIER[$t] <= (int)LEGION_EVENT_UNITS_X100_BY_TIER[$t - 1]) $monotonic = false;
    }
    check('legion: event duration rises with tier', $monotonic, LEGION_EVENT_UNITS_X100_BY_TIER);
}

// Bounds: T1 at least 30 real minutes (an event nobody notices is dead
// content), T6 at most 12 hours (a season-long modifier is a season setting,
// not an event).
{
    $t1 = intdiv((int)LEGION_EVENT_UNITS_X100_BY_TIER[1] * (int)ABILITY_UNIT_DURATION_TICKS, 100);
    $t6 = intdiv((int)LEGION_EVENT_UNITS_X100_BY_TIER[6] * (int)ABILITY_UNIT_DURATION_TICKS, 100);
    check('legion: T1 event >= 30 minutes', $t1 >= ticks_from_real_seconds(1800), ['ticks' => $t1]);
    check('legion: T6 event <= 12 hours', $t6 <= ticks_from_real_seconds(43200), ['ticks' => $t6]);
}

// The mass must exceed the transmute output (2 wildcards), or one transmute
// re-arms an awakening; and it must fit under the per-family holding cap, or
// the critical mass is unreachable and the whole mechanic is dead.
check('legion: critical mass exceeds one transmute output',
    (int)LEGION_CRITICAL_MASS_COUNT > 2, ['mass' => LEGION_CRITICAL_MASS_COUNT]);
check('legion: critical mass reachable under the holding cap',
    (int)LEGION_CRITICAL_MASS_COUNT <= (int)CAPS_PER_FAMILY_HOLDING,
    ['mass' => LEGION_CRITICAL_MASS_COUNT, 'cap' => CAPS_PER_FAMILY_HOLDING]);

// Effect magnitudes: multipliers must actually amplify (> 1x) without being
// absurd (<= 10x), and the frenzy divisor must actually shorten timings.
check('legion: swarm multiplier sane',
    (int)LEGION_SWARM_DROP_MULTIPLIER_FP > FP_SCALE && (int)LEGION_SWARM_DROP_MULTIPLIER_FP <= 10 * FP_SCALE,
    ['fp' => LEGION_SWARM_DROP_MULTIPLIER_FP]);
check('legion: foresight multiplier sane',
    (int)LEGION_FORESIGHT_SIGHT_MULTIPLIER_FP > FP_SCALE && (int)LEGION_FORESIGHT_SIGHT_MULTIPLIER_FP <= 10 * FP_SCALE,
    ['fp' => LEGION_FORESIGHT_SIGHT_MULTIPLIER_FP]);
check('legion: frenzy divisor shortens timings',
    (int)LEGION_FRENZY_TIMING_DIVISOR >= 2, ['divisor' => LEGION_FRENZY_TIMING_DIVISOR]);

// ---------------------------------------------------------------------------
// Drop pity + throttle. These were configured-but-unenforced for months; now
// that the tick engine reads them, their relationships have to stay coherent.
// ---------------------------------------------------------------------------

// The mean gate interval at the base drop chance, in ticks. Pity is a
// backstop against outlier droughts, not a second faucet: it must sit far
// beyond the expected wait, or 'guaranteed' quietly becomes 'usual'.
$meanGateIntervalTicks = intdiv(FP_SCALE, max(1, (int)SIGIL_DROP_CHANCE_FP));
check('pity: threshold positive', (int)SIGIL_PITY_TICKS > 0, ['ticks' => SIGIL_PITY_TICKS]);
check('pity: a backstop, not a faucet (threshold >> mean drop interval)',
    (int)SIGIL_PITY_TICKS >= 3 * $meanGateIntervalTicks,
    ['pity' => SIGIL_PITY_TICKS, 'mean_interval' => $meanGateIntervalTicks]);

check('throttle: window and cap positive',
    (int)SIGIL_DROP_WINDOW_TICKS > 0 && (int)SIGIL_MAX_DROPS_WINDOW >= 1,
    ['window' => SIGIL_DROP_WINDOW_TICKS, 'max' => SIGIL_MAX_DROPS_WINDOW]);

// The throttle is an anti-burst guard, not a baseline tax: ordinary luck at
// the base rate must clear under the cap, while a swarm event (drop chance
// ×LEGION multiplier) must be able to hit it. Both directions matter — a cap
// below the base expectation starves normal play; a cap above the swarm
// expectation is decoration.
//
// Drop chance is per TICK while the window is defined in real time, so how
// many drops a real day holds depends on the tick cadence: a compressed test
// clock (TMC_TICK_REAL_SECONDS=1, TMC_TIME_SCALE=60) deliberately runs a much
// denser economy and would fail these bounds by design. So this pair is
// scoped to the shipped cadence, where the tuning is actually defined.
$atShippedCadence = ((int)TICK_REAL_SECONDS === 60 && (int)TIME_SCALE === 1);
if ($atShippedCadence) {
    $expectedDropsPerWindow = ((int)SIGIL_DROP_WINDOW_TICKS * (int)SIGIL_DROP_CHANCE_FP) / FP_SCALE;
    $expectedUnderSwarm = $expectedDropsPerWindow * ((int)LEGION_SWARM_DROP_MULTIPLIER_FP / FP_SCALE);
    check('throttle: cap clears ordinary base-rate luck',
        (float)SIGIL_MAX_DROPS_WINDOW > $expectedDropsPerWindow,
        ['cap' => SIGIL_MAX_DROPS_WINDOW, 'expected_base' => round($expectedDropsPerWindow, 2)]);
    check('throttle: cap binds under a swarm event',
        (float)SIGIL_MAX_DROPS_WINDOW < $expectedUnderSwarm,
        ['cap' => SIGIL_MAX_DROPS_WINDOW, 'expected_swarm' => round($expectedUnderSwarm, 2)]);
} else {
    echo "  note  drop-rate bounds skipped: not at the shipped tick cadence"
       . " (TICK_REAL_SECONDS=" . TICK_REAL_SECONDS . ", TIME_SCALE=" . TIME_SCALE . ")\n";
}

// The throttle can never mask the pity guarantee: a fully-throttled window
// contributes no attempt ticks, so pity time only accrues when drops are
// actually possible. That holds structurally; what must hold numerically is
// that the pity threshold cannot be reached INSIDE one throttle window with
// the throttle already binding (window ticks < pity ticks).
check('pity/throttle: one window cannot span the whole pity clock',
    (int)SIGIL_DROP_WINDOW_TICKS < (int)SIGIL_PITY_TICKS,
    ['window' => SIGIL_DROP_WINDOW_TICKS, 'pity' => SIGIL_PITY_TICKS]);

// Wiring guards: the tick engine must consume both mechanisms. Config-level
// checks can't see code, so read the source the same way the client harness
// does — if these greps fail, the constants have gone decorative again.
$tickEngineSrc = (string)file_get_contents(__DIR__ . '/../includes/tick_engine.php');
check('pity: tick engine consumes SIGIL_PITY_TICKS',
    strpos($tickEngineSrc, 'SIGIL_PITY_TICKS') !== false
    && strpos($tickEngineSrc, "'PITY'") !== false);
check('throttle: tick engine consumes the drop window',
    strpos($tickEngineSrc, 'SIGIL_MAX_DROPS_WINDOW') !== false
    && strpos($tickEngineSrc, 'SIGIL_DROP_WINDOW_TICKS') !== false);
check('pity: counter accrues from the eligibility out-param',
    strpos($tickEngineSrc, 'wasEligible') !== false);

echo str_repeat('-', 68) . "\n";
echo "{$pass} passed, {$fail} failed\n";
echo $fail === 0
    ? "Result: PASS - theft is worth considering, and spending beats hoarding.\n"
    : "Result: FAIL\n";

exit($fail === 0 ? 0 : 1);
