<?php
/**
 * Too Many Coins - purge throwaway self-check accounts
 *
 * tools/concurrency_selfcheck.php registers a real account and joins a real
 * season on every run. Until it grew a --cleanup pass it never removed them, so
 * they accumulate: they appear in the leaderboard, inflate the season's
 * participant count, and count toward the supply telemetry that star price
 * model v2 reads. On v1 the price is pinned at 32 so the telemetry is inert,
 * which is exactly why this went unnoticed.
 *
 * Dry run by default. Nothing is deleted without --apply.
 *
 *   php tools/purge_test_accounts.php                    # show what would go
 *   php tools/purge_test_accounts.php --apply            # delete it
 *   php tools/purge_test_accounts.php --handle=cc_5e14c6 # one account
 *
 * Exit: 0 = nothing to do or purge succeeded, 1 = error, 2 = matches found in
 * dry run (so CI can flag leftovers without deleting them).
 */

require_once __DIR__ . '/lib/test_accounts.php';

$opts = getopt('', ['apply', 'handle::']);
$apply = isset($opts['apply']);
$onlyHandle = $opts['handle'] ?? null;

try {
    $pdo = tmcOpenPdo();
} catch (Throwable $e) {
    fwrite(STDERR, "Cannot connect: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Run this inside web-app or worker-app, where DB_* is configured.\n");
    exit(1);
}

echo "Purge test accounts" . ($apply ? " (APPLY)" : " (dry run)") . "\n";
echo str_repeat('-', 66) . "\n";
echo "matching: handle ^cc_[0-9a-f]{6}$ AND email @example.invalid\n";
if ($onlyHandle) { echo "limited to: {$onlyHandle}\n"; }
echo "\n";

try {
    $accounts = tmcFindTestAccounts($pdo, $onlyHandle);
} catch (Throwable $e) {
    fwrite(STDERR, "Query failed: " . $e->getMessage() . "\n");
    exit(1);
}

if (!$accounts) {
    echo "No test accounts found. Nothing to do.\n";
    exit(0);
}

foreach ($accounts as $a) {
    printf("  #%-6s %-12s %-28s created %s\n",
        $a['player_id'], $a['handle'], $a['email'], $a['created_at']);
}
echo "\n";

$ids = array_column($accounts, 'player_id');
try {
    $res = tmcPurgePlayers($pdo, $ids, $apply);
} catch (Throwable $e) {
    fwrite(STDERR, "Purge failed, rolled back: " . $e->getMessage() . "\n");
    exit(1);
}

if ($res['rows']) {
    echo ($apply ? "Deleted:\n" : "Would delete:\n");
    foreach ($res['rows'] as $where => $n) { printf("  %-46s %d\n", $where, $n); }
} else {
    echo ($apply ? "No dependent rows.\n" : "No dependent rows to delete.\n");
}
printf("  %-46s %d\n", 'players', $res['players']);

if ($res['supply']) {
    // Only the relative accumulators need touching. coins_active_total,
    // coins_idle_total and effective_price_supply are rewritten to absolute
    // values every tick and repair themselves within 5 seconds.
    echo "\n" . ($apply ? "Supply corrected:\n" : "Would correct season supply:\n");
    foreach ($res['supply'] as $s) {
        printf("  season %-4d total_coins_supply -%d\n", $s['season_id'], $s['coins']);
    }
}
echo str_repeat('-', 66) . "\n";

if (!$apply) {
    printf("Dry run. %d account(s), %d dependent row(s). Re-run with --apply to delete.\n",
        count($accounts), $res['total']);
    exit(2);
}

printf("Purged %d account(s) and %d dependent row(s). Absolute telemetry "
     . "(coins_active_total, effective_price_supply) re-derives on the next tick.\n",
    $res['players'], $res['total']);
exit(0);
