<?php
/**
 * Too Many Coins - Starter Grant Self-Check
 *
 * A first-time player is handed STARTER_GRANT_COUNT Tier STARTER_GRANT_TIER
 * sigils so the forge is legible on arrival. Lock-in refunds sigils at
 * SIGIL_REFERENCE_STARS_BY_TIER and converts the total to global stars, and a
 * granted sigil is indistinguishable from an earned one at that point - so
 * without an offset, a first join is a signup bonus paid in global stars, the
 * permanent cross-season currency.
 *
 * This drives the real Economy::computeEarlyLockInPayout and asserts the offset
 * behaves: it cancels the grant exactly, never eats earned progress, and never
 * turns a payout negative.
 *
 * Usage:  php tools/starter_grant_selfcheck.php [--verbose]
 * Exit:   0 = pass, 1 = fail
 */

require_once __DIR__ . '/../includes/economy.php';
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

/** Tier costs exactly as lockIn() builds them. */
function tierCosts(): array {
    $out = [];
    for ($t = 1; $t <= 6; $t++) {
        $out[] = (int)(SIGIL_REFERENCE_STARS_BY_TIER[$t] ?? 0);
    }
    return $out;
}

echo "Starter grant self-check\n";
echo str_repeat('-', 66) . "\n";

$costs = tierCosts();
$grantTier  = (int)STARTER_GRANT_TIER;
$grantCount = (int)STARTER_GRANT_COUNT;
$grantValue = $grantCount * (int)(SIGIL_REFERENCE_STARS_BY_TIER[$grantTier] ?? 0);

// The grant has to be worth something, or the offset is untestable and the
// onboarding is pointless.
check('grant is a positive number of sigils', $grantCount > 0, $grantCount);
check('grant tier is a real tier', $grantTier >= 1 && $grantTier <= 6, $grantTier);
check('grant has a non-zero star value', $grantValue > 0, $grantValue);

// A player holding ONLY their starter sigils and nothing else must lock in for
// exactly what they would have got with no grant at all. This is the whole
// point: the grant must be economically invisible at settlement.
{
    $counts = [0, 0, 0, 0, 0, 0];
    $counts[$grantTier - 1] = $grantCount;

    $withGrant = Economy::computeEarlyLockInPayout(0, $counts, $costs, $grantValue);
    $withNothing = Economy::computeEarlyLockInPayout(0, [0, 0, 0, 0, 0, 0], $costs, 0);

    check('starter sigils alone pay out exactly as holding nothing would',
        $withGrant['global_stars_gained'] === $withNothing['global_stars_gained'],
        ['withGrant' => $withGrant['global_stars_gained'], 'withNothing' => $withNothing['global_stars_gained']]);

    check('the offset is reported for auditability',
        ($withGrant['starter_offset_stars'] ?? null) === $grantValue,
        $withGrant['starter_offset_stars'] ?? null);
}

// Combining is the case a per-tier subtraction would have got wrong: five
// granted T1s become one T2 and there would be no T1s left to subtract.
// Reference value is preserved by that combine, so the offset still cancels.
{
    $combined = [0, 0, 0, 0, 0, 0];
    $combined[1] = 1;   // one T2, forged from the five granted T1s

    $payout = Economy::computeEarlyLockInPayout(0, $combined, $costs, $grantValue);
    check('combining the grant up a tier does not escape the offset',
        $payout['global_stars_gained'] === 0,
        $payout);
}

// Earned progress must survive. A player who bought stars with their own coins
// keeps every one of them; the offset only ever touches the sigil refund.
{
    $earnedStars = 5000;
    $counts = [0, 0, 0, 0, 0, 0];
    $counts[$grantTier - 1] = $grantCount;

    $withGrant = Economy::computeEarlyLockInPayout($earnedStars, $counts, $costs, $grantValue);
    $noGrant   = Economy::computeEarlyLockInPayout($earnedStars, [0, 0, 0, 0, 0, 0], $costs, 0);

    check('purchased stars are untouched by the offset',
        $withGrant['global_stars_gained'] === $noGrant['global_stars_gained'],
        ['withGrant' => $withGrant['global_stars_gained'], 'noGrant' => $noGrant['global_stars_gained']]);
}

// An offset larger than the refund must clamp, not go negative. This is the
// case where a player combined the grant upward at a value loss, or spent the
// starter sigils on a ward and has nothing left to net against.
{
    $payout = Economy::computeEarlyLockInPayout(100, [0, 0, 0, 0, 0, 0], $costs, 999999);
    check('an offset larger than the refund clamps to zero',
        $payout['sigil_refund_stars'] === 0, $payout['sigil_refund_stars']);
    check('...and never claws back seasonal stars',
        $payout['total_seasonal_stars'] === 100, $payout['total_seasonal_stars']);
    check('...and never produces a negative payout',
        $payout['global_stars_gained'] >= 0, $payout['global_stars_gained']);
}

// A negative offset must not be usable to inflate a payout.
{
    $counts = [1, 0, 0, 0, 0, 0];
    $normal   = Economy::computeEarlyLockInPayout(0, $counts, $costs, 0);
    $negative = Economy::computeEarlyLockInPayout(0, $counts, $costs, -100000);
    check('a negative offset cannot inflate the refund',
        $negative['sigil_refund_stars'] === $normal['sigil_refund_stars'],
        ['negative' => $negative['sigil_refund_stars'], 'normal' => $normal['sigil_refund_stars']]);
}

// Earned sigils beyond the grant still refund normally - the offset cancels the
// grant, not sigil refunds in general.
{
    $counts = [0, 0, 0, 0, 0, 0];
    $counts[$grantTier - 1] = $grantCount + 10;

    $payout = Economy::computeEarlyLockInPayout(0, $counts, $costs, $grantValue);
    $expected = 10 * (int)(SIGIL_REFERENCE_STARS_BY_TIER[$grantTier] ?? 0);
    check('sigils earned beyond the grant still refund in full',
        $payout['sigil_refund_stars'] === $expected,
        ['got' => $payout['sigil_refund_stars'], 'expected' => $expected]);
}

// Default argument: every pre-existing caller must be unaffected.
{
    $counts = [2, 1, 0, 0, 0, 0];
    $explicit = Economy::computeEarlyLockInPayout(500, $counts, $costs, 0);
    $implicit = Economy::computeEarlyLockInPayout(500, $counts, $costs);
    check('omitting the offset behaves exactly as passing zero',
        $explicit === $implicit);
}

echo str_repeat('-', 66) . "\n";
echo "{$pass} passed, {$fail} failed\n";
echo $fail === 0
    ? "Result: PASS - the starter grant is economically invisible at lock-in.\n"
    : "Result: FAIL\n";

exit($fail === 0 ? 0 : 1);
