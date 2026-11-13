#!/usr/bin/env php
<?php
/**
 * Too Many Coins - Season Config Immutability Self-Check
 *
 *   php tools/season_config_immutability_selfcheck.php
 *
 * A season's economy configuration is fixed when GameTime::ensureSeasons()
 * inserts the row. Until it was removed, GameTime::rebalanceExistingSeasons()
 * rewrote twenty-odd of those columns on every boot and every tick, with no
 * status predicate and no audit - an Active season's UBI rate, hoarding table
 * and star-price table could change mid-flight. It was guarded only by
 * "WHERE base_ubi_active_per_tick = 100 AND target_spend_rate_per_tick = 50",
 * which matched nothing by the time it was deleted.
 *
 * This check keeps it deleted. It reads every UPDATE seasons statement in the
 * runtime code (includes/, api/, worker/) and fails if one assigns a config
 * column. Runtime state on the same row - supply totals, current price, EMAs,
 * status, timing - is not on the list and stays writable.
 *
 * A future change to season config belongs in a migration (one-time, recorded
 * in schema_migrations) or in the parameter design of
 * docs/editor_design_proposal.md, not in the request or tick path.
 *
 * No server and no database required.
 *
 * Exit: 0 = every check passed, 1 = at least one is wrong.
 */

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

/**
 * Columns written once, by the season INSERT, and read as configuration.
 * Keep in step with the INSERT in GameTime::ensureSeasons().
 */
const SEASON_CONFIG_COLUMNS = [
    'base_ubi_active_per_tick', 'base_ubi_idle_factor_fp', 'ubi_min_per_tick',
    'inflation_table', 'target_spend_rate_per_tick',
    'hoarding_window_ticks', 'hoarding_min_factor_fp', 'hoarding_sink_enabled',
    'hoarding_safe_hours', 'hoarding_safe_min_coins',
    'hoarding_tier1_excess_cap', 'hoarding_tier2_excess_cap',
    'hoarding_tier1_rate_hourly_fp', 'hoarding_tier2_rate_hourly_fp', 'hoarding_tier3_rate_hourly_fp',
    'hoarding_sink_cap_ratio_fp', 'hoarding_idle_multiplier_fp',
    'starprice_table', 'star_price_cap', 'starprice_idle_weight_fp', 'starprice_active_only',
    'starprice_max_upstep_fp', 'starprice_max_downstep_fp',
    'starprice_model_version', 'starprice_reactivation_window_ticks', 'starprice_demand_table',
    'market_affordability_bias_fp', 'vault_config',
];

/**
 * Every UPDATE seasons statement in $src, as [line, set-clause] pairs.
 *
 * The SQL lives in double-quoted PHP strings, so a statement ends at WHERE or
 * at the closing double quote. Single quotes are SQL literals ('Expired') and
 * must not end it, or a config column after one would be missed.
 */
function seasonUpdates(string $src): array {
    $found = [];
    if (!preg_match_all('/\bUPDATE\s+seasons\b(.*?)(?:\bWHERE\b|")/is', $src, $m, PREG_OFFSET_CAPTURE)) {
        return $found;
    }
    foreach ($m[1] as $i => [$set, $offset]) {
        $line = substr_count(substr($src, 0, $m[0][$i][1]), "\n") + 1;
        $found[] = [$line, $set];
    }
    return $found;
}

/** Config columns assigned in a SET clause. */
function assignedConfigColumns(string $set): array {
    preg_match_all('/\b([a-z_][a-z0-9_]*)\s*=(?!=)/i', $set, $m);
    $assigned = array_map('strtolower', $m[1]);
    return array_values(array_intersect(SEASON_CONFIG_COLUMNS, $assigned));
}

echo "The detector itself\n";
$legacy = '"UPDATE seasons
             SET base_ubi_active_per_tick = 30,
                 target_spend_rate_per_tick = 15,
                 starprice_table = ?,
                 current_star_price = LEAST(6000, GREATEST(current_star_price, 100))
             WHERE base_ubi_active_per_tick = 100"';
$hits = seasonUpdates($legacy);
check('finds an UPDATE seasons statement', count($hits) === 1, $hits);
check('flags the deleted rebalance statement',
    assignedConfigColumns($hits[0][1] ?? '') === ['base_ubi_active_per_tick', 'target_spend_rate_per_tick', 'starprice_table'],
    assignedConfigColumns($hits[0][1] ?? ''));
check('ignores config columns that appear only in WHERE',
    assignedConfigColumns((seasonUpdates('"UPDATE seasons SET status = ? WHERE base_ubi_active_per_tick = 1"')[0][1]) ?? '') === []);
check('a quoted SQL literal does not hide a later assignment',
    assignedConfigColumns((seasonUpdates('"UPDATE seasons SET status = \'Expired\', vault_config = ?"')[0][1]) ?? '') === ['vault_config']);
check('does not flag runtime state',
    assignedConfigColumns('SET current_star_price = ?, total_coins_supply = total_coins_supply - ?') === []);

echo "\nRuntime code\n";
$root = dirname(__DIR__);
$files = [];
foreach (['includes', 'api', 'worker'] as $dir) {
    if (!is_dir("{$root}/{$dir}")) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$dir}", FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') $files[] = $f->getPathname();
    }
}
sort($files);

$statements = 0;
foreach ($files as $file) {
    $rel = ltrim(str_replace('\\', '/', substr($file, strlen($root))), '/');
    foreach (seasonUpdates((string)file_get_contents($file)) as [$line, $set]) {
        $statements++;
        $bad = assignedConfigColumns($set);
        check("{$rel}:{$line} leaves season config alone", $bad === [], 'assigns ' . implode(', ', $bad));
    }
}
// A detector that matches nothing passes everything; the tick engine alone has several.
check('found the runtime UPDATE seasons statements', $statements >= 5, "{$statements} found");

$gameTime = (string)file_get_contents("{$root}/includes/game_time.php");
check('rebalanceExistingSeasons is gone from GameTime', strpos($gameTime, 'rebalanceExistingSeasons') === false);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
