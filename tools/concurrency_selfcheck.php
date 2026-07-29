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
 *
 * Exit: 0 = no value created, 1 = integrity violation.
 */

$opts = getopt('', ['base::', 'parallel::', 'verbose']);
$base = rtrim($opts['base'] ?? 'http://localhost:8080', '/');
$parallel = max(2, (int)($opts['parallel'] ?? 15));
$verbose = isset($opts['verbose']);
$api = $base . '/api/index.php';

if (!function_exists('curl_multi_init')) {
    fwrite(STDERR, "ext-curl with curl_multi is required.\n");
    exit(1);
}

$failures = [];
$notes = [];

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

// --- THE TEST --------------------------------------------------------------
// Ask for a purchase that consumes most of the balance, N times at once. Only
// one can legitimately succeed; the rest must be rejected. Pre-fix, all of them
// committed.
$before = $readMe();
$price = max(1, $before['price']);
$starsEach = max(1, intdiv($before['coins'], $price));   // ~the whole balance
$costEach = $starsEach * $price;

echo "before:   coins={$before['coins']} stars={$before['stars']} price={$price}\n";
echo "firing:   {$parallel} x purchase_stars({$starsEach}) @ {$costEach} coins each\n";

$results = reqParallel($api, 'purchase_stars', ['stars_requested' => $starsEach], $token, $parallel);
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

echo "results:  {$ok} succeeded, {$rejected} rejected as insufficient";
if ($other) { echo ", " . count($other) . " other (" . implode('; ', array_unique(array_slice($other, 0, 3))) . ")"; }
echo "\n";
echo "after:    coins={$after['coins']} stars={$after['stars']}\n\n";

// --- assertions ------------------------------------------------------------
// 1. The balance must never go negative. This is the headline invariant.
if ($after['coins'] < 0) {
    $failures[] = "coins went NEGATIVE ({$after['coins']}) - concurrent requests each passed the "
                . "affordability check and all committed";
}

// 2. No value creation: stars gained must be paid for in coins burned.
$starsGained = $after['stars'] - $before['stars'];
$coinsBurned = $before['coins'] - $after['coins'];
$expectedBurn = $starsGained * $price;
// UBI keeps accruing during the run, so coins burned is a lower bound.
if ($starsGained > 0 && $coinsBurned + 1 < $expectedBurn) {
    $failures[] = "minted {$starsGained} stars but only {$coinsBurned} coins were burned "
                . "(expected at least {$expectedBurn}) - stars created from nothing";
}

// 3. At most one of a set of mutually-exclusive full-balance purchases.
if ($ok > 1 && $starsGained * $price > $before['coins']) {
    $failures[] = "{$ok} full-balance purchases succeeded against a single balance";
}
if ($ok === 0) {
    $notes[] = "no purchase succeeded - the run proved nothing; check rate limiting "
             . "(a 429 storm looks like rejection) and re-run with a smaller --parallel";
}

echo str_repeat('-', 62) . "\n";
foreach ($notes as $n) { echo "note: {$n}\n"; }
if (empty($failures)) {
    echo "Result: PASS - balance never negative, no stars minted without coins burned.\n";
    exit(0);
}
echo "Result: FAIL\n";
foreach ($failures as $f) { echo "  - {$f}\n"; }
echo "\nExpected fix: read inside the transaction with FOR UPDATE, guard the write\n";
echo "with \"AND coins >= ?\", and assert rowCount() === 1.\n";
exit(1);
