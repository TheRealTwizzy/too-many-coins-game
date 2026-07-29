<?php
/**
 * Too Many Coins - Concurrency Self-Check
 *
 * Proves the resource-integrity fixes against a RUNNING server. The static
 * checker (tools/integrity_selfcheck.php) proves every decrement is shaped
 * correctly; this proves the shape actually holds under real concurrent load,
 * which is the part that cannot be argued from reading SQL.
 *
 * The bug it exists to catch: reads and affordability checks happened outside
 * the transaction and writes were relative with no guard, so N concurrent
 * requests all passed validation and all committed. Balances are signed, so
 * they went negative instead of erroring, and the tick engine then erased the
 * debt with GREATEST(0, ...). Seasonal Stars convert to Global Stars at
 * lock-in, so it laundered into permanent currency.
 *
 * Requires: a running server + database. Creates a throwaway account.
 *
 *   php tools/concurrency_selfcheck.php --base=http://localhost:8080
 *   php tools/concurrency_selfcheck.php --base=http://localhost:8080 --parallel=25
 *   php tools/concurrency_selfcheck.php --selftest    # no server; judges recorded runs
 *
 * Exit: 0 = no value created, 1 = integrity violation, 2 = inconclusive.
 */

$opts = getopt('', ['base::', 'parallel::', 'verbose', 'selftest']);
$base = rtrim($opts['base'] ?? 'http://localhost:8080', '/');
$parallel = max(2, (int)($opts['parallel'] ?? 15));
$verbose = isset($opts['verbose']);
$api = $base . '/api/index.php';

/**
 * Decide what a completed race proves. Pure: no I/O, no globals, no clock - so
 * it can be replayed against recorded observations by --selftest.
 *
 * This lives in its own function because the judgement, not the plumbing, is
 * where this tool has repeatedly been wrong: it once reported PASS on a run
 * where every request died at a gate, and once reported FAIL on a textbook
 * correct run because it allowed 1 coin of slack for UBI on a 5s tick that
 * pays 2.5. An assertion set that cannot itself be tested will keep doing that.
 *
 * @param array{ok:int,stars_each:int,cost_each:int,coins_before:int,coins_after:int,
 *               stars_before:int,stars_after:int,ubi_per_second:float,window_seconds:float} $o
 * @return array{failures:string[],notes:string[],inconclusive:bool,summary:string}
 */
function tmcEvaluateRun(array $o): array {
    $failures = [];
    $notes = [];

    $ok          = (int)$o['ok'];
    $starsEach   = (int)$o['stars_each'];
    $costEach    = (int)$o['cost_each'];
    $coinsBefore = (int)$o['coins_before'];
    $coinsAfter  = (int)$o['coins_after'];
    $committed   = $ok * $costEach;
    $starsGained = (int)$o['stars_after'] - (int)$o['stars_before'];
    $coinsBurned = $coinsBefore - $coinsAfter;

    // 1. The balance must never go negative. This is the headline invariant:
    //    coins is a signed BIGINT with no CHECK, so the pre-fix double-spend
    //    drove it below zero rather than erroring.
    if ($coinsAfter < 0) {
        $failures[] = "coins went NEGATIVE ({$coinsAfter}) - concurrent requests each passed "
                    . "the affordability check and all committed";
    }

    // 2. Nothing was committed that did not exist. This is the invariant the
    //    double-spend actually violated - N racers each passed the affordability
    //    check against the SAME balance and all committed - and it is stated in
    //    terms of what the server AGREED to rather than a coin delta, so UBI
    //    cannot perturb it. Ignoring inflow here makes the check stricter, never
    //    falser: income only ever adds headroom the purchases did not have.
    if ($committed > $coinsBefore) {
        $failures[] = "{$ok} purchases committed {$committed} coins against a balance of "
                    . "{$coinsBefore} - more was spent than existed";
    }

    // 3. Exactly the stars that were bought, and no more.
    $expectedStars = $ok * $starsEach;
    if ($starsGained !== $expectedStars) {
        $failures[] = "{$ok} successful purchases of {$starsEach} stars should have minted "
                    . "{$expectedStars}, but seasonal_stars moved by {$starsGained}";
    }

    // 4. The coins were actually taken. A guard that grants stars but skips the
    //    decrement creates value just as surely as one that lets both racers
    //    spend - and assertions 1, 2 and 3 all pass in that case.
    //
    //    This is the only assertion that must reason about a coin delta, so it
    //    is the only one UBI can distort: income landing between the two reads
    //    makes the observed burn look SMALLER than it was. The allowance is
    //    deliberately generous, because a real skipped decrement is short by a
    //    WHOLE purchase while UBI over a few seconds is a couple of coins.
    //    Two orders of magnitude separate signal from noise here, so margin is
    //    free - and being stingy with it is exactly what produced a false FAIL.
    $ubiAllowance = (int)ceil((float)$o['ubi_per_second'] * (float)$o['window_seconds'] * 3) + 2;
    if ($ok > 0 && $coinsBurned < $committed - $ubiAllowance) {
        $failures[] = "minted {$starsGained} stars but only {$coinsBurned} coins were burned "
                    . "(expected {$committed}, allowing {$ubiAllowance} for UBI landing "
                    . "mid-run) - stars created from nothing";
    }

    // An inconclusive run must NOT report PASS. A green light from a test that
    // exercised nothing is worse than a red one - it retires the question while
    // leaving the bug in place.
    $inconclusive = false;
    if ($ok === 0) {
        $inconclusive = true;
        $notes[] = "NO purchase succeeded, so the guarded write was never reached and "
                 . "nothing was proven. Common causes: an unmet gate (the response "
                 . "'error' above names it), or a 429 storm from rate limiting, which "
                 . "looks identical to correct rejection. Re-run with --parallel=8.";
    } elseif ($ubiAllowance >= max(1, intdiv($costEach, 2))) {
        // Assertion 4 cannot separate inflow from a skipped decrement at this
        // ratio. Say so rather than passing on a check that has gone blind.
        $inconclusive = true;
        $notes[] = "UBI inflow over the race window ({$ubiAllowance} coins) is too large a "
                 . "share of one purchase ({$costEach} coins) for the burn check to tell "
                 . "income apart from a skipped decrement. Let the account get richer "
                 . "before racing, so one purchase dwarfs a few seconds of income.";
    }

    return [
        'failures'     => $failures,
        'notes'        => $notes,
        'inconclusive' => $inconclusive,
        'summary'      => "{$committed} coins committed against {$coinsBefore} available, "
                        . "{$coinsBurned} burned, {$starsGained} stars minted "
                        . "(UBI allowance {$ubiAllowance}).",
    ];
}

// --- --selftest: prove the judgement above, with no server ------------------
if (isset($opts['selftest'])) {
    // Scenario 1 is the real observation from the run that produced a false
    // FAIL: balance 129, one winner buying 4 stars at 32, 14 correctly rejected,
    // ending balance 3 because two coins of UBI landed mid-race.
    $base129 = [
        'ok' => 1, 'stars_each' => 4, 'cost_each' => 128,
        'coins_before' => 129, 'coins_after' => 3,
        'stars_before' => 0, 'stars_after' => 4,
        'ubi_per_second' => 0.5, 'window_seconds' => 4.0,
    ];
    $cases = [
        ['correct run, UBI landing mid-race (the false FAIL)', $base129, 'pass'],
        ['correct run, no UBI at all',
            ['coins_after' => 1, 'ubi_per_second' => 0.0] + $base129, 'pass'],
        ['double-spend: both committed, balance negative',
            ['ok' => 2, 'coins_after' => -127, 'stars_after' => 8] + $base129, 'fail'],
        ['double-spend: more committed than existed, balance still positive',
            ['ok' => 2, 'coins_after' => 1, 'stars_after' => 8] + $base129, 'fail'],
        ['skipped decrement: stars granted, coins untouched',
            ['coins_after' => 131] + $base129, 'fail'],
        ['stars minted beyond what was bought',
            ['stars_after' => 9] + $base129, 'fail'],
        ['no winner: gate rejected everything',
            ['ok' => 0, 'coins_after' => 131, 'stars_after' => 0] + $base129, 'inconclusive'],
        ['UBI too large a share of one purchase to judge the burn',
            ['cost_each' => 8, 'stars_each' => 1, 'coins_before' => 9, 'coins_after' => 3,
             'stars_after' => 1, 'ubi_per_second' => 2.0] + $base129, 'inconclusive'],
    ];

    echo "Concurrency self-check - judgement selftest\n" . str_repeat('-', 62) . "\n";
    $bad = 0;
    foreach ($cases as [$label, $obs, $want]) {
        $v = tmcEvaluateRun($obs);
        $got = $v['failures'] ? 'fail' : ($v['inconclusive'] ? 'inconclusive' : 'pass');
        $good = ($got === $want);
        if (!$good) { $bad++; }
        printf("  %-4s %-52s want=%-12s got=%s\n", $good ? 'ok' : 'BAD', $label, $want, $got);
        if (!$good) {
            foreach ($v['failures'] as $f) { echo "         ! {$f}\n"; }
            foreach ($v['notes'] as $n)    { echo "         ~ {$n}\n"; }
        }
    }
    echo str_repeat('-', 62) . "\n";
    if ($bad) {
        echo "Result: FAIL - {$bad} of " . count($cases) . " scenarios judged wrongly.\n";
        exit(1);
    }
    echo "Result: PASS (" . count($cases) . " scenarios judged correctly)\n";
    exit(0);
}

if (!function_exists('curl_multi_init')) {
    fwrite(STDERR, "ext-curl with curl_multi is required.\n");
    exit(1);
}

function req(string $api, string $action, array $body = [], ?string $token = null): array {
    $ch = curl_init($api . '?action=' . urlencode($action));
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_POSTFIELDS => json_encode(['action' => $action] + $body),
        CURLOPT_HTTPHEADER => array_filter([
            'Content-Type: application/json',
            $token ? 'X-Session-Token: ' . $token : null,
        ]),
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        return ['error' => 'transport: ' . $err];
    }
    return json_decode((string)$raw, true) ?? ['error' => 'unparseable: ' . substr((string)$raw, 0, 120)];
}

/** Fire N identical requests genuinely in parallel via curl_multi. */
function reqParallel(string $api, string $action, array $body, string $token, int $n): array {
    $mh = curl_multi_init();
    $handles = [];
    for ($i = 0; $i < $n; $i++) {
        $ch = curl_init($api . '?action=' . urlencode($action));
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_POSTFIELDS => json_encode(['action' => $action] + $body),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Session-Token: ' . $token,
            ],
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[] = $ch;
    }
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        if ($running) { curl_multi_select($mh, 1.0); }
    } while ($running > 0);

    $out = [];
    foreach ($handles as $ch) {
        $raw = curl_multi_getcontent($ch);
        $out[] = json_decode((string)$raw, true) ?? ['error' => 'unparseable'];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

echo "Concurrency self-check\n" . str_repeat('-', 62) . "\n";
echo "target:   {$api}\nparallel: {$parallel}\n\n";

// --- reachability ----------------------------------------------------------
$state = req($api, 'game_state');
if (!empty($state['error'])) {
    fwrite(STDERR, "Server not reachable or not ready: {$state['error']}\n");
    fwrite(STDERR, "Start it first:  docker compose up   (or)  php -S 0.0.0.0:8080 router.php\n");
    exit(1);
}

// --- throwaway account -----------------------------------------------------
$suffix = substr(bin2hex(random_bytes(4)), 0, 6);
$handle = 'cc_' . $suffix;
$reg = req($api, 'register', [
    'handle' => $handle,
    'email' => $handle . '@example.invalid',
    'password' => 'test-' . $suffix,
]);
if (empty($reg['token'])) {
    fwrite(STDERR, "Could not register a test account: " . json_encode($reg) . "\n");
    exit(1);
}
$token = $reg['token'];
echo "test account: {$handle}\n";

// --- join a joinable season ------------------------------------------------
$seasons = $state['seasons'] ?? [];
$seasonId = null;
foreach ($seasons as $s) {
    if (($s['computed_status'] ?? '') === 'Active' && empty($s['is_blackout'])) {
        $seasonId = (int)$s['season_id'];
        break;
    }
}
if ($seasonId === null) {
    fwrite(STDERR, "No Active, non-blackout season to join. Cannot run.\n");
    exit(1);
}
$join = req($api, 'season_join', ['season_id' => $seasonId], $token);
if (!empty($join['error'])) {
    fwrite(STDERR, "season_join failed: {$join['error']}\n");
    exit(1);
}
echo "joined season: {$seasonId}\n\n";

/** Read this player's live participation numbers. */
$readMe = function () use ($api, $token, $seasonId): array {
    $gs = req($api, 'game_state', [], $token);
    $p = $gs['player'] ?? [];
    $part = $p['participation'] ?? $p;
    // Find the season we joined by id. seasons[0] is not reliable - there are
    // normally several visible at once (one Active, the rest Scheduled), and
    // reading the wrong one gives a price that has nothing to do with our
    // purchases.
    $price = 0;
    foreach (($gs['seasons'] ?? []) as $s) {
        if ((int)($s['season_id'] ?? 0) === $seasonId) {
            $price = (int)($s['current_star_price'] ?? 0);
            break;
        }
    }
    return [
        'coins' => (int)($part['coins'] ?? 0),
        'stars' => (int)($part['seasonal_stars'] ?? 0),
        'price' => $price,
    ];
};

// --- wait for a spendable balance -----------------------------------------
//
// The target has to be derived from the STAR PRICE, not a fixed number. The
// first version waited for 50 coins, which on a v1 season (price pinned at 32)
// is not even two stars - every one of the N racers would have been rejected
// for insufficient funds and the run would have "passed" while proving nothing.
//
// We want enough that a full-balance purchase is several stars, so exactly one
// racer can legitimately win and the rest must be turned away by the guard.
$me = $readMe();
$price = max(1, (int)$me['price']);
$target = $price * 4;

$rateHint = 'base UBI is 30 coins/min, so this takes about '
          . max(1, (int)ceil(($target - $me['coins']) / 30)) . ' min';
echo "star price {$price}, need {$target} coins to make the race meaningful ({$rateHint})\n";
echo "waiting for UBI to accrue";

// Generous ceiling: at 30 coins/min a 128-coin target is ~4 minutes, and a
// player who starts Idle accrues at 30% of that.
$deadline = time() + 900;
$lastShown = -1;
while ($me['coins'] < $target && time() < $deadline) {
    sleep(5);
    $me = $readMe();
    if ($me['coins'] !== $lastShown) {
        echo '.';
        $lastShown = $me['coins'];
    }
}
echo "\n";

if ($me['coins'] < $target) {
    fwrite(STDERR, "\nOnly reached {$me['coins']} of {$target} coins before the deadline.\n");
    if ($me['coins'] > 0) {
        // It IS accruing - just not fast enough. Do not blame the worker.
        fwrite(STDERR, "Coins ARE accruing, so the tick worker is running; it is simply slow at\n"
                     . "this rate. Re-run and let it sit, or lower the bar with --parallel=8.\n");
    } else {
        fwrite(STDERR, "No coins at all. Check the tick worker and that the account is Active.\n");
    }
    exit(1);
}

// --- measure the UBI inflow rate -------------------------------------------
//
// The balance is read once before the race and once after, and UBI lands in
// between. That inflow makes the observed burn look SMALLER than it really was.
// The first version allowed a flat 1 coin for it - less than a single tick
// delivers. At 30 coins/min on the deployed 5s cadence each tick pays ~2.5
// coins, so a perfectly correct run under-reported its burn by 2 and was
// reported as "stars created from nothing".
//
// Measure the real inflow instead of guessing at it. Sampling over longer than
// one tick means we see whole ticks, not a fraction of one.
$rateSampleSeconds = 6;
$rs0 = $readMe();
sleep($rateSampleSeconds);
$rs1 = $readMe();
$ubiPerSecond = max(0.0, ($rs1['coins'] - $rs0['coins']) / $rateSampleSeconds);
printf("ubi rate: %.2f coins/sec (measured over %ds)\n", $ubiPerSecond, $rateSampleSeconds);

// --- THE TEST --------------------------------------------------------------
// Ask for a purchase that consumes most of the balance, N times at once. Only
// one can legitimately succeed; the rest must be rejected. Pre-fix, all of them
// committed.
$windowStart = microtime(true);
$before = $readMe();
$price = max(1, $before['price']);
$starsEach = max(1, intdiv($before['coins'], $price));   // ~the whole balance
$costEach = $starsEach * $price;

echo "before:   coins={$before['coins']} stars={$before['stars']} price={$price}\n";
echo "firing:   {$parallel} x purchase_stars({$starsEach}) @ {$costEach} coins each\n";

// confirm_economic_impact is required by gatedStarPurchase: a medium/high risk
// purchase - which spending the whole balance always is - returns
// 'confirmation_required' and never reaches the transactional code. Without it
// all N requests died at the gate and the race was never run.
$results = reqParallel(
    $api,
    'purchase_stars',
    ['stars_requested' => $starsEach, 'confirm_economic_impact' => true],
    $token,
    $parallel
);
$ok = 0; $rejected = 0; $other = [];
foreach ($results as $r) {
    if (!empty($r['success'])) { $ok++; }
    elseif (!empty($r['error'])) {
        if (stripos($r['error'], 'insufficient') !== false) { $rejected++; }
        else { $other[] = $r['error']; }
    }
}
sleep(1);
$after = $readMe();
$windowSeconds = microtime(true) - $windowStart;

echo "results:  {$ok} succeeded, {$rejected} rejected as insufficient";
if ($other) { echo ", " . count($other) . " other (" . implode('; ', array_unique(array_slice($other, 0, 3))) . ")"; }
echo "\n";
echo "after:    coins={$after['coins']} stars={$after['stars']}\n\n";

// --- verdict ---------------------------------------------------------------
$verdict = tmcEvaluateRun([
    'ok'             => $ok,
    'stars_each'     => $starsEach,
    'cost_each'      => $costEach,
    'coins_before'   => $before['coins'],
    'coins_after'    => $after['coins'],
    'stars_before'   => $before['stars'],
    'stars_after'    => $after['stars'],
    'ubi_per_second' => $ubiPerSecond,
    'window_seconds' => $windowSeconds,
]);

echo str_repeat('-', 62) . "\n";
foreach ($verdict['notes'] as $n) { echo "note: {$n}\n"; }
if ($verdict['failures']) {
    echo "Result: FAIL\n";
    foreach ($verdict['failures'] as $f) { echo "  - {$f}\n"; }
    echo "\nExpected fix: read inside the transaction with FOR UPDATE, guard the write\n";
    echo "with \"AND coins >= ?\", and assert rowCount() === 1.\n";
    exit(1);
}
if ($verdict['inconclusive']) {
    echo "Result: INCONCLUSIVE - the integrity guard was never exercised.\n";
    exit(2);
}
// What a PASS actually establishes: under genuine concurrency against one
// balance, no value was created - the balance never went negative and every
// star minted was paid for. It does not distinguish WHICH check rejected each
// loser; a request that reads after the winner commits is turned away by the
// pre-transaction check, and only one that reads before it reaches the guarded
// UPDATE. Both are correct outcomes, and no-value-created is the property the
// double-spend violated.
echo "Result: PASS - {$verdict['summary']}\n";
exit(0);
