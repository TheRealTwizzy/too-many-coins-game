<?php
/**
 * Too Many Coins - Documentation Self-Check
 *
 * The player-facing wiki and the README quote hard numbers: how long a season
 * runs, what fraction of your Seasonal Stars survive an early lock-in, what a
 * cosmetic costs. Every one of those is a copy of a value that actually lives in
 * includes/config.php or in a call site. Copies drift. Nothing was watching.
 *
 * This is the same failure the changelog staleness check was written for, one
 * layer down: the changelog went a week stale because nothing read it, and the
 * only reason anyone noticed was that a human happened to look. Documented
 * constants are worse, because a wrong number reads exactly like a right one.
 *
 * The design here is deliberately declarative rather than clever. There is no
 * prose parser - a parser over prose produces false positives (the hoarding
 * sink's "50,000" cap looks a lot like a drop rate if you are grepping) and,
 * worse, false confidence. Instead each documented fact is one entry in
 * DOCUMENTED_FACTS: the authoritative value, the file that quotes it, and the
 * exact strings that file must contain. Change a constant and the check names the
 * page that now lies and the string to look for.
 *
 * Every fact lists ALL of its occurrences, each anchored in enough surrounding
 * text to be unambiguous, and every one of them must be present. A bare
 * substring search over a whole page is not good enough: the wiki says
 * "15 minutes" six times and only one of them is the idle timeout - the rest are
 * ability, freeze and theft timings that have nothing to do with that constant.
 * A file-wide search would let those five unrelated copies vouch for a value
 * nobody had updated. Same trap with "12 hours", where the fourth occurrence is
 * the hoarding sink's safe period, an operator-configurable per-season column.
 * Anchoring each occurrence is what makes a partially-updated page fail.
 *
 * What this check deliberately does NOT do:
 *
 *   - It does not check the design canon in the too-many-coins-api repo. Canon
 *     records design intent and is expected to diverge from the code; that
 *     divergence is documented there and is not a build failure.
 *   - It does not require docs to mention every constant. Adding a constant does
 *     not fail this check. Only quoting one wrongly does.
 *   - It does not touch the database or the network.
 *
 * Adding a fact is one array entry. That is the point: the cost of protecting a
 * documented number should be lower than the cost of it being wrong.
 *
 * Usage:  php tools/docs_selfcheck.php [--verbose]
 * Exit:   0 = pass, 1 = fail
 */

require_once __DIR__ . '/../includes/config.php';

$verbose = in_array('--verbose', $argv, true);

$pass = 0;
$fail = 0;

$ROOT = dirname(__DIR__);

function check(string $name, bool $ok, $detail = null): void {
    global $pass, $fail, $verbose;
    if ($ok) {
        $pass++;
        if ($verbose) echo "  ok   {$name}\n";
    } else {
        $fail++;
        echo "  FAIL {$name}";
        if ($detail !== null) echo "\n       -> " . json_encode($detail, JSON_UNESCAPED_SLASHES);
        echo "\n";
    }
}

/** Ticks are a deployment-relative unit; docs quote wall-clock. Convert back. */
function real_seconds(int $ticks): int {
    return $ticks * (int)TICK_REAL_SECONDS;
}
function real_hours(int $ticks): int  { return intdiv(real_seconds($ticks), 3600); }
function real_days(int $ticks): int   { return intdiv(real_seconds($ticks), 86400); }
function real_minutes(int $ticks): int { return intdiv(real_seconds($ticks), 60); }

/** The wiki writes small counts as words, so "six tiers" has to be derivable. */
function number_word(int $n): string {
    $words = [1 => 'one', 'two', 'three', 'four', 'five',
              'six', 'seven', 'eight', 'nine', 'ten'];
    return $words[$n] ?? (string)$n;
}

$WIKI   = 'public/wiki/assets/wiki-data.js';
$README = 'README.md';

// ---------------------------------------------------------------------------
// The early lock-in rate is a magic literal, not a constant.
//
// Economy::applyGlobalStarsGrantWithCarry() takes a numerator/denominator pair
// and defaults to 100/100. The early-lock-in path passes 65/100 explicitly, in
// two separate call sites that have to agree with each other. A literal repeated
// across files with no constant binding them is the textbook shape of a value
// that drifts, so this reads the rate out of the source rather than trusting a
// number typed into this file.
//
// The default is load-bearing too. The natural-expiry path calls the method with
// two arguments and inherits 100/100, and that inherited default IS the "100% at
// natural expiry" the wiki documents in three places. Nothing bound the two
// together, so changing the signature default silently made every one of those
// pages wrong. It is extracted and checked here rather than assumed.
// ---------------------------------------------------------------------------
function extract_lock_in_rates(string $root): array {
    $sources = ['includes/actions.php', 'includes/economy.php'];
    $rates = [];
    foreach ($sources as $rel) {
        $path = $root . '/' . $rel;
        if (!is_file($path)) continue;
        $src = file_get_contents($path);
        // Only 4-argument calls carry an explicit rate. Two-argument calls take
        // the signature default instead; extract_default_rate() covers those.
        if (preg_match_all(
                '/applyGlobalStarsGrantWithCarry\s*\([^();]*?,\s*(\d+)\s*,\s*(\d+)\s*\)/',
                $src, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $rates[] = [
                    'file'    => $rel,
                    'percent' => (int)round(((int)$hit[1] / max(1, (int)$hit[2])) * 100),
                ];
            }
        }
    }
    return $rates;
}

/**
 * The natural-expiry rate is the signature's default - not a constant, and not
 * anything a call site states out loud. Read it off the signature itself.
 */
function extract_default_rate(string $root): ?int {
    $path = $root . '/includes/economy.php';
    if (!is_file($path)) return null;
    if (!preg_match(
            '/function\s+applyGlobalStarsGrantWithCarry\s*\([^)]*?\$numerator\s*=\s*(\d+)\s*,'
            . '\s*int\s+\$denominator\s*=\s*(\d+)/',
            file_get_contents($path), $m)) {
        return null;
    }
    return (int)round(((int)$m[1] / max(1, (int)$m[2])) * 100);
}

/**
 * That default only governs anything while some call site still inherits it. If
 * every caller starts passing an explicit rate, the documented 100% stops coming
 * from the signature and this check has to be re-pointed.
 */
function default_rate_is_load_bearing(string $root): bool {
    foreach (['includes/actions.php', 'includes/economy.php'] as $rel) {
        $path = $root . '/' . $rel;
        if (!is_file($path)) continue;
        if (preg_match('/applyGlobalStarsGrantWithCarry\s*\(\s*[^();,]+\s*,\s*[^();,]+\s*\)/',
                       file_get_contents($path))) {
            return true;
        }
    }
    return false;
}

echo "Documentation self-check\n";
echo str_repeat('-', 66) . "\n";

$lockInRates = extract_lock_in_rates($ROOT);

check('an explicit early lock-in rate exists in the source',
    count($lockInRates) > 0,
    'no 4-argument applyGlobalStarsGrantWithCarry call found - if the rate moved '
    . 'to a constant, point this check at it instead');

$lockInPercent = null;
if ($lockInRates) {
    $distinct = array_values(array_unique(array_column($lockInRates, 'percent')));
    check('every early lock-in call site uses the same rate',
        count($distinct) === 1,
        ['sites' => $lockInRates,
         'fix'   => 'the lock-in payout differs depending on which path a player '
                  . 'takes; reconcile the call sites before touching the docs']);
    $lockInPercent = $distinct[0];
}

$defaultRate = extract_default_rate($ROOT);

check('the natural-expiry rate is readable from the method signature',
    $defaultRate !== null,
    'could not read the $numerator/$denominator defaults from '
    . 'Economy::applyGlobalStarsGrantWithCarry(); if the signature changed, point '
    . 'this check at wherever the natural-expiry rate now lives');

check('a call site still inherits the natural-expiry default',
    default_rate_is_load_bearing($ROOT),
    'no 2-argument applyGlobalStarsGrantWithCarry call remains, so the signature '
    . 'default no longer decides the natural-expiry rate; re-point this check at '
    . 'the explicit rate the expiry path now passes');

// ---------------------------------------------------------------------------
// Documented facts. Each entry says: this value lives in code, this file quotes
// it, and these are the strings that must ALL appear - one per place the page
// states the fact, anchored in surrounding text so that an unrelated line
// carrying the same number cannot vouch for a copy nobody updated.
// ---------------------------------------------------------------------------
$seasonDays   = real_days((int)SEASON_DURATION);
$cadenceDays  = real_days((int)SEASON_CADENCE);
$blackoutHrs  = real_hours((int)BLACKOUT_DURATION);
$lockInHrs    = real_hours((int)MIN_SEASONAL_LOCK_IN_TICKS);
$idleMins     = real_minutes((int)IDLE_TIMEOUT_TICKS);
$tierWord     = number_word((int)SIGIL_MAX_TIER);

$priceList = (function () {
    $t = array_map(fn($v) => number_format((int)$v), COSMETIC_PRICE_TIERS);
    $last = array_pop($t);
    return implode(', ', $t) . ' and ' . $last;
})();

$DOCUMENTED_FACTS = [
    [
        'label'  => 'season duration (wiki)',
        'doc'    => $WIKI,
        'expect' => ['A season runs **' . $seasonDays . ' days**'],
        'source' => 'SEASON_DURATION',
        'why'    => 'the headline number a player plans around',
    ],
    [
        'label'  => 'season duration (README)',
        'doc'    => $README,
        'expect' => [$seasonDays . '-day competitive seasons'],
        'source' => 'SEASON_DURATION',
        'why'    => 'first paragraph anyone reads about the game',
    ],
    [
        'label'  => 'season cadence',
        'doc'    => $WIKI,
        'expect' => ['a new one starts every **' . $cadenceDays . ' days**'],
        'source' => 'SEASON_CADENCE',
        'why'    => 'determines how many seasons overlap at once',
    ],
    [
        'label'  => 'blackout duration',
        'doc'    => $WIKI,
        'expect' => [
            'The last **' . $blackoutHrs . ' hours** of every season',
            'The final **' . $blackoutHrs . ' hours** of a season',
            '| Blackout | ' . $blackoutHrs . ' hours |',
            '| Blackout duration | ' . $blackoutHrs . ' hours |',
        ],
        'source' => 'BLACKOUT_DURATION',
        'why'    => 'a player who mistimes this cannot lock in at all',
    ],
    [
        'label'  => 'minimum participation before lock-in',
        'doc'    => $WIKI,
        'expect' => [
            'cumulative **' . $lockInHrs . ' hours** in a season',
            'cumulative **' . $lockInHrs . ' hours** in the season',
            '| Minimum participation for lock-in | ' . $lockInHrs . ' hours |',
        ],
        'source' => 'MIN_SEASONAL_LOCK_IN_TICKS',
        'why'    => 'quoted in three places, and the hoarding sink quotes the same '
                  . 'number for an unrelated per-season setting',
    ],
    [
        'label'  => 'idle timeout',
        'doc'    => $WIKI,
        'expect' => ['| Idle timeout | ' . $idleMins . ' minutes |'],
        'source' => 'IDLE_TIMEOUT_TICKS',
        'why'    => 'the boundary between full and 30% UBI; five unrelated ability '
                  . 'timings quote the same number, so this must be anchored',
    ],
    [
        'label'  => 'sigil tier count',
        'doc'    => $WIKI,
        'expect' => [
            'There are **' . $tierWord . ' tiers**',
            'the ' . $tierWord . ' tiers, combining',
        ],
        'source' => 'SIGIL_MAX_TIER',
        'why'    => 'a whole tier going undocumented is how canon got to five',
    ],
    [
        'label'  => 'cosmetic price tiers',
        'doc'    => $WIKI,
        'expect' => ['priced in ' . number_word(count(COSMETIC_PRICE_TIERS))
                     . ' tiers: **' . $priceList . '**'],
        'source' => 'COSMETIC_PRICE_TIERS',
        'why'    => 'the one price list a player spends permanent currency against',
    ],
];

if ($lockInPercent !== null) {
    $DOCUMENTED_FACTS[] = [
        'label'  => 'early lock-in conversion rate',
        'doc'    => $WIKI,
        'expect' => ['into permanent Global Stars at **' . $lockInPercent . '%**'],
        'source' => 'applyGlobalStarsGrantWithCarry call sites',
        'why'    => 'a magic literal in two files with no constant binding them',
    ];
}

if ($defaultRate !== null) {
    $expiry = [
        '| Let it expire | ' . $defaultRate . '% |',
        '| Natural expiry conversion | ' . $defaultRate . '% |',
    ];
    // The page states both rates in one breath. Deriving the sentence from both
    // ties them together, so moving either one alone fails here.
    if ($lockInPercent !== null) {
        $expiry[] = 'converts at **' . $defaultRate . '%** instead of '
                  . $lockInPercent . '%';
    }
    $DOCUMENTED_FACTS[] = [
        'label'  => 'natural-expiry conversion rate',
        'doc'    => $WIKI,
        'expect' => $expiry,
        'source' => 'applyGlobalStarsGrantWithCarry() signature default',
        'why'    => 'inherited from a default nothing else pins; the wiki calls it '
                  . 'the most consequential arithmetic in the game',
    ];
}

// ---------------------------------------------------------------------------

$contents = [];
foreach (array_unique(array_column($DOCUMENTED_FACTS, 'doc')) as $rel) {
    $path = $ROOT . '/' . $rel;
    $contents[$rel] = is_file($path) ? file_get_contents($path) : null;
    check("documented file exists: {$rel}", $contents[$rel] !== null);
}

foreach ($DOCUMENTED_FACTS as $fact) {
    $body = $contents[$fact['doc']] ?? null;
    if ($body === null) continue;  // already reported as a missing file

    $missing = array_values(array_filter(
        $fact['expect'],
        fn(string $needle) => !str_contains($body, $needle)
    ));

    check(
        $fact['label'],
        $missing === [],
        [
            'doc'      => $fact['doc'],
            'missing'  => $missing,
            'of'       => count($fact['expect']) . ' documented occurrence(s)',
            'source'   => $fact['source'],
            'why'      => $fact['why'],
            'fix'      => 'the code changed and this page did not - update every '
                        . 'occurrence listed above, or update this check if the '
                        . 'wording moved. Updating only some of them is the exact '
                        . 'failure this check exists to catch',
        ]
    );
}

// ---------------------------------------------------------------------------
// A tick is the one documented value that is genuinely deployment-relative, so
// the wiki must not quote it as fixed. This is the inverse failure: not a stale
// number, but a number presented as more stable than it is.
// ---------------------------------------------------------------------------
if (($wiki = $contents[$WIKI] ?? null) !== null) {
    check('the wiki flags tick cadence as a deployment setting',
        str_contains($wiki, 'deployment setting')
        || str_contains($wiki, 'not** fixed'),
        ['fix' => 'every "per tick" figure is meaningless without this caveat; '
                . 'see TICK_REAL_SECONDS in includes/config.php']);
}

echo str_repeat('-', 66) . "\n";
echo "{$pass} passed, {$fail} failed\n";
echo $fail === 0
    ? "Result: PASS - every documented constant matches the code.\n"
    : "Result: FAIL - a page quotes a number the code no longer uses.\n";

exit($fail === 0 ? 0 : 1);
