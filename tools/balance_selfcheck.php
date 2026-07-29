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

echo str_repeat('-', 68) . "\n";
echo "{$pass} passed, {$fail} failed\n";
echo $fail === 0
    ? "Result: PASS - theft is worth considering, and spending beats hoarding.\n"
    : "Result: FAIL\n";

exit($fail === 0 ? 0 : 1);
