<?php
/**
 * Too Many Coins - Configuration
 * Reads from environment variables with fallback to local defaults
 */

function env_first(array $keys, $default = null) {
    foreach ($keys as $key) {
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return $value;
        }
    }
    return $default;
}

function ticks_from_real_seconds($seconds) {
    $tickRealSeconds = max(1, (int)(getenv('TMC_TICK_REAL_SECONDS') ?: 60));
    return max(1, (int)ceil($seconds / $tickRealSeconds));
}

function game_ticks_to_real_seconds($gameTicks) {
    if ($gameTicks <= 0) return 0;
    $scale = max(1, (int)(getenv('TMC_TIME_SCALE') ?: 1));
    $tickRealSeconds = max(1, (int)(getenv('TMC_TICK_REAL_SECONDS') ?: 60));
    return max(0, intdiv(((int)$gameTicks) * $tickRealSeconds, $scale));
}

function get_timing_diagnostics(?int $workerIntervalSeconds = null) {
    $warnings = [];
    if ((int)TIME_SCALE !== 1) {
        $warnings[] = 'time_scale_not_one';
    }
    if ($workerIntervalSeconds !== null && $workerIntervalSeconds < (int)TICK_REAL_SECONDS) {
        $warnings[] = 'worker_interval_faster_than_tick_real_seconds';
    }

    return [
        'time_scale' => (int)TIME_SCALE,
        'tick_real_seconds' => (int)TICK_REAL_SECONDS,
        'idle_timeout_ticks' => (int)IDLE_TIMEOUT_TICKS,
        'idle_timeout_real_seconds' => game_ticks_to_real_seconds((int)IDLE_TIMEOUT_TICKS),
        'forced_offline_idle_hold_ticks' => (int)FORCED_OFFLINE_IDLE_HOLD_TICKS,
        'forced_offline_idle_hold_real_seconds' => game_ticks_to_real_seconds((int)FORCED_OFFLINE_IDLE_HOLD_TICKS),
        'presence_stale_offline_seconds' => (int)TMC_PRESENCE_STALE_OFFLINE_SECONDS,
        'tick_on_request' => (bool)TMC_TICK_ON_REQUEST,
        'presence_touch_seconds' => (int)TMC_PRESENCE_TOUCH_SECONDS,
        'minute_to_second_migration_enabled' => (bool)TMC_MINUTE_TO_SECOND_MIGRATION,
        'minute_to_second_migration_dry_run' => (bool)TMC_MINUTE_TO_SECOND_MIGRATION_DRY_RUN,
        'worker_interval_seconds' => $workerIntervalSeconds,
        'warnings' => $warnings,
    ];
}

// Database variables (prefer DB_*; support common platform aliases)
define('DB_HOST', env_first(['DB_HOST', 'MYSQLHOST', 'MYSQL_HOST', 'HOSTINGER_DB_HOST'], ''));
define('DB_PORT', env_first(['DB_PORT', 'MYSQLPORT', 'MYSQL_PORT', 'HOSTINGER_DB_PORT'], ''));
define('DB_NAME', env_first(['DB_NAME', 'MYSQLDATABASE', 'MYSQL_DATABASE', 'HOSTINGER_DB_NAME'], ''));
define('DB_USER', env_first(['DB_USER', 'MYSQLUSER', 'MYSQL_USER', 'HOSTINGER_DB_USER'], ''));
define('DB_PASS', env_first(['DB_PASS', 'MYSQLPASSWORD', 'MYSQL_PASSWORD', 'HOSTINGER_DB_PASSWORD'], ''));

// Season timing constants
define('SEASON_ANCHOR', 345600);        // 1970-01-05T00:00:00Z in seconds
define('SEASON_DURATION', ticks_from_real_seconds(1209600));   // 14 days
define('SEASON_CADENCE', ticks_from_real_seconds(604800));     // 7 days
define('BLACKOUT_DURATION', ticks_from_real_seconds(259200));  // 72 hours

// Time scale multiplier applied after tick quantization. Keep at 1 in production.
define('TIME_SCALE', max(1, (int)(getenv('TMC_TIME_SCALE') ?: 1)));

// Real seconds represented by one base tick. Set TMC_TICK_REAL_SECONDS=1 for 1s/tick runtime.
define('TICK_REAL_SECONDS', max(1, (int)(getenv('TMC_TICK_REAL_SECONDS') ?: 60)));

// Tick processing controls
define('TMC_TICK_ON_REQUEST', filter_var(getenv('TMC_TICK_ON_REQUEST') ?: '0', FILTER_VALIDATE_BOOLEAN));
define('TMC_TICK_SECRET', env_first(['TMC_TICK_SECRET', 'TICK_SECRET'], ''));
define('TMC_AUTO_SQL_MIGRATIONS', filter_var(env_first(['TMC_AUTO_SQL_MIGRATIONS', 'TMC_AUTO_SQL_HOTFIX'], '1'), FILTER_VALIDATE_BOOLEAN));
define('TMC_PRESENCE_TOUCH_SECONDS', max(5, (int)(getenv('TMC_PRESENCE_TOUCH_SECONDS') ?: 30)));
define('TMC_AUTH_TRACE', filter_var(getenv('TMC_AUTH_TRACE') ?: '0', FILTER_VALIDATE_BOOLEAN));
define('TMC_TICK_SLOW_MS', max(50, (int)(getenv('TMC_TICK_SLOW_MS') ?: 500)));
define('TMC_TICK_TRACE', filter_var(getenv('TMC_TICK_TRACE') ?: '0', FILTER_VALIDATE_BOOLEAN));
define('TMC_TICK_LOCK_MISS_LOG_EVERY', max(1, (int)(getenv('TMC_TICK_LOCK_MISS_LOG_EVERY') ?: 12)));
define('TMC_MINUTE_TO_SECOND_MIGRATION', filter_var(getenv('TMC_MINUTE_TO_SECOND_MIGRATION') ?: '0', FILTER_VALIDATE_BOOLEAN));
define('TMC_MINUTE_TO_SECOND_MIGRATION_DRY_RUN', filter_var(getenv('TMC_MINUTE_TO_SECOND_MIGRATION_DRY_RUN') ?: '0', FILTER_VALIDATE_BOOLEAN));

// Request limiting and proxy trust controls.
define('TMC_RATE_LIMIT_WINDOW_SECONDS', max(1, (int)(getenv('TMC_RATE_LIMIT_WINDOW_SECONDS') ?: 60)));
define('TMC_RATE_LIMIT_ANON_PER_WINDOW', max(10, (int)(getenv('TMC_RATE_LIMIT_ANON_PER_WINDOW') ?: 120)));
define('TMC_RATE_LIMIT_AUTH_PER_WINDOW', max((int)TMC_RATE_LIMIT_ANON_PER_WINDOW, (int)(getenv('TMC_RATE_LIMIT_AUTH_PER_WINDOW') ?: 300)));
define('TMC_RATE_LIMIT_TRACE', filter_var(getenv('TMC_RATE_LIMIT_TRACE') ?: '0', FILTER_VALIDATE_BOOLEAN));
define('TMC_RATE_LIMIT_TRACE_SAMPLE_PCT', max(0, min(100, (int)(getenv('TMC_RATE_LIMIT_TRACE_SAMPLE_PCT') ?: 10))));
define('TMC_TRUST_PROXY_HEADERS', filter_var(getenv('TMC_TRUST_PROXY_HEADERS') ?: '0', FILTER_VALIDATE_BOOLEAN));
define('TMC_TRUSTED_PROXIES', trim((string)(getenv('TMC_TRUSTED_PROXIES') ?: '')));
define('TMC_RATE_LIMIT_DIAGNOSTICS', filter_var(getenv('TMC_RATE_LIMIT_DIAGNOSTICS') ?: '0', FILTER_VALIDATE_BOOLEAN));

// Account/social email controls.
define('TMC_MAIL_FROM', env_first(['TMC_MAIL_FROM'], 'no-reply@too-many-coins.com'));
define('TMC_MAIL_FROM_NAME', env_first(['TMC_MAIL_FROM_NAME'], 'Too Many Coins'));
define('TMC_MAIL_DEV_LOG', filter_var(getenv('TMC_MAIL_DEV_LOG') ?: '1', FILTER_VALIDATE_BOOLEAN));
define('TMC_PUBLIC_BASE_URL', rtrim((string)env_first(['TMC_PUBLIC_BASE_URL'], ''), '/'));
define('TMC_VERIFICATION_TOKEN_MINUTES', max(5, (int)(getenv('TMC_VERIFICATION_TOKEN_MINUTES') ?: 30)));

// SMTP transport. With TMC_SMTP_HOST empty the mailer falls back to mail(),
// which has no transport inside the container image.
define('TMC_SMTP_HOST', trim((string)env_first(['TMC_SMTP_HOST'], '')));
define('TMC_SMTP_PORT', max(1, (int)env_first(['TMC_SMTP_PORT'], 587)));
define('TMC_SMTP_USER', (string)env_first(['TMC_SMTP_USER'], ''));
define('TMC_SMTP_PASS', (string)env_first(['TMC_SMTP_PASS'], ''));
define('TMC_SMTP_SECURE', strtolower(trim((string)env_first(['TMC_SMTP_SECURE'], 'tls')))); // tls | ssl | none
define('TMC_SMTP_TIMEOUT_SECONDS', max(2, (int)env_first(['TMC_SMTP_TIMEOUT_SECONDS'], 10)));
define('TMC_SMTP_FALLBACK_TO_MAIL', filter_var(env_first(['TMC_SMTP_FALLBACK_TO_MAIL'], '0'), FILTER_VALIDATE_BOOLEAN));
define('TMC_MAIL_TRACE', filter_var(env_first(['TMC_MAIL_TRACE'], '0'), FILTER_VALIDATE_BOOLEAN));

// Activity
define('IDLE_TIMEOUT_TICKS', ticks_from_real_seconds(900));  // 15 real minutes
define('FORCED_OFFLINE_IDLE_HOLD_TICKS', ticks_from_real_seconds(2700)); // 45 real minutes after Idle
define('TMC_PRESENCE_STALE_OFFLINE_SECONDS', max((int)TMC_PRESENCE_TOUCH_SECONDS, (int)(getenv('TMC_PRESENCE_STALE_OFFLINE_SECONDS') ?: 120)));

// Economy v2 scaffolding
// Star price model versions.
//
// v1 applies the market affordability bias AFTER the velocity clamp, to the
// value then written back as current_star_price - which the clamp reads as its
// reference next tick. The bias therefore compounds while the up-clamp can only
// add max(1, intdiv(prev * 83, 1e6)) = 1 below price 12,048, giving the fixed
// point p = floor(0.97 * (p + 1)) = 32. Every v1 season's price converges to 32
// regardless of coin supply.
//
// v2 applies the bias to the raw table target, before the clamp, so the price
// tracks supply and the bias means what its name says.
//
// v1 is kept ONLY so seasons already in flight are not repriced underneath their
// players: a live season would jump from a flat 32 toward its table value (up to
// ~4,074 at high supply) over a few hours, devaluing every held coin balance
// with no warning. New seasons are created at v2; this branch can be deleted
// once every pre-cutover season has expired.
// A star can never be free. Stars are the score, so a zero price would let a
// player mint unbounded score from any coin balance.
define('STAR_PRICE_ABSOLUTE_FLOOR', 1);

define('STARPRICE_MODEL_V1_BIAS_AFTER_CLAMP', 1);
define('STARPRICE_MODEL_V2_BIAS_BEFORE_CLAMP', 2);
define('STARPRICE_MODEL_VERSION_DEFAULT', max(1, (int)(getenv('TMC_STARPRICE_MODEL_VERSION_DEFAULT') ?: STARPRICE_MODEL_V2_BIAS_BEFORE_CLAMP)));
define('STARPRICE_REACTIVATION_WINDOW_TICKS_DEFAULT', ticks_from_real_seconds(4860)); // 81 real minutes (conservative-v2)

// Maximum ticks a single processSeasonTick call will advance.
//
// Without a cap, an outage produces a catch-up batch whose per-tick sigil-drop
// loop cannot finish inside one transaction. It times out, the transaction rolls
// back, last_processed_tick is never advanced, and the next cycle retries an even
// larger batch - so a long enough outage wedges the game permanently while the
// worker holds GET_LOCK('tmc_tick_worker'). With a cap, each cycle commits a
// bounded chunk and progress is monotonic.
define('TICK_MAX_CATCHUP_TICKS', max(1, (int)(getenv('TMC_TICK_MAX_CATCHUP_TICKS') ?: ticks_from_real_seconds(1800))));

// Economy tuning windows
define('HOARDING_WINDOW_TICKS', ticks_from_real_seconds(86400));  // 24 real hours

// Lock-In
define('MIN_PARTICIPATION_TICKS', 1);
// Minimum TOTAL ticks across all season runs required before a player can lock in.
// Prevents skip-rejoin-quick-lockin exploit: a player cannot leave mid-season and
// re-enter to immediately lock in — they must accumulate this many cumulative ticks.
// Set to 12 real hours of ticks. At 1 tick/second: 43,200 ticks.
define('MIN_SEASONAL_LOCK_IN_TICKS', ticks_from_real_seconds(43200));

// Sigil drops
// Deterministic per-tick drop model:
// - One drop attempt per tick
// - Activity, inventory pressure, and boost pressure scale the gate chance
// - Season phase gates tier availability and weights
define('SIGIL_DROP_CHANCE_FP', 3500); // 0.35% base gate chance
define('SIGIL_ACTIVITY_MULTIPLIER_FP', [
    'Active' => 1000000,
    'Idle' => 500000,
    'Offline' => 0,
]);
define('SIGIL_SEASON_PHASE_EARLY', 'EARLY');
define('SIGIL_SEASON_PHASE_MID', 'MID');
define('SIGIL_SEASON_PHASE_LATE_ACTIVE', 'LATE_ACTIVE');
define('SIGIL_SEASON_PHASE_BLACKOUT', 'BLACKOUT');
define('SIGIL_SEASON_PHASE_LATE_BLACKOUT', SIGIL_SEASON_PHASE_BLACKOUT);
define('SIGIL_BLACKOUT_DURATION_TICKS', ticks_from_real_seconds(259200)); // Final 3 real days
define('SIGIL_LATE_ACTIVE_DURATION_TICKS', SIGIL_BLACKOUT_DURATION_TICKS);
// was 500000 — at 14 days that put 39% of the season in a phase where
// tiers 4-6 cannot drop. 400000 gives four phases of comparable weight.
define('SIGIL_EARLY_PHASE_FRACTION_FP', 400000);
define('SIGIL_PHASE_AVAILABLE_TIERS', [
    SIGIL_SEASON_PHASE_EARLY => [1, 2, 3],
    SIGIL_SEASON_PHASE_MID => [1, 2, 3, 4, 5],
    SIGIL_SEASON_PHASE_LATE_ACTIVE => [1, 2, 3, 4, 5, 6],
    SIGIL_SEASON_PHASE_BLACKOUT => [1, 2, 3, 4, 5],
]);
define('SIGIL_PHASE_TIER_WEIGHTS', [
    SIGIL_SEASON_PHASE_EARLY => [
        1 => 1000,
        2 => 450,
        3 => 90,
    ],
    SIGIL_SEASON_PHASE_MID => [
        1 => 1000,
        2 => 600,
        3 => 260,
        4 => 45,
        5 => 8,
    ],
    SIGIL_SEASON_PHASE_LATE_ACTIVE => [
        1 => 960,
        2 => 620,
        3 => 320,
        4 => 120,
        5 => 22,
        6 => 3,
    ],
    SIGIL_SEASON_PHASE_BLACKOUT => [
        1 => 960,
        2 => 620,
        3 => 320,
        4 => 120,
        5 => 22,
    ],
]);
define('SIGIL_DROP_ALGORITHM_VERSION', 'deterministic_v4');

// Legacy queued/pity sigil controls are retained for compatibility but are
// no longer part of the active deterministic drop pipeline.
// Per-tier effective drop rates at zero sigil power (parts per 10,000; T1=8.75% down to T5=0.06%)
define('SIGIL_TIER_DROP_RATES', [
    1 => 875,  // 8.75%
    2 => 250,  // 2.50%
    3 => 100,  // 1.00%
    4 =>  19,  // 0.19%
    5 =>   6,  // 0.06%
]);
define('SIGIL_DROP_RATE', 8);            // 1 in 8 (~12.5% combined base drop rate at 0 sigil power)
define('SIGIL_DROP_RATE_MAX_POWER', 16); // 1 in 16 (~6.25% combined drop rate at full sigil power)
define('SIGIL_PITY_TICKS', ticks_from_real_seconds(120000));
define('SIGIL_MAX_DROPS_WINDOW', 8);
define('SIGIL_DROP_WINDOW_TICKS', ticks_from_real_seconds(86400));
// Delivery pacing to prevent bursty notification batches during tick catch-up.
// Earned drops are queued and released at most one per processing cycle.
define('SIGIL_PACING_ENABLED', true);

// Per-player dynamic sigil drop rate configuration
//
// Inventory-based dampening:
//   For every SIGIL_INVENTORY_ADJ_THRESHOLD sigils of a given tier already owned, that
//   tier's conditional drop odds are reduced by SIGIL_INVENTORY_ADJ_STEP_FP (parts per
//   1,000,000), up to a maximum of SIGIL_INVENTORY_ADJ_MAX_STEPS reductions.  This
//   prevents a single tier from over-dropping once a player has accumulated many of them
//   while still keeping drops the primary sigil acquisition source.
define('SIGIL_INVENTORY_ADJ_THRESHOLD', 8);    // Trigger every N same-tier sigils owned
define('SIGIL_INVENTORY_ADJ_STEP_FP',   7500);  // Reduce odds by 0.75% (7,500 / 1,000,000) per trigger
define('SIGIL_INVENTORY_ADJ_MAX_STEPS', 8);    // Cap: maximum 8 triggers (≤6% total reduction per tier)

// Boost-based drop frequency adjustment (negative pressure):
//   High boost activity signals an active sigil economy; the Bernoulli denominator is
//   INCREASED (drop frequency decreases) to prevent runaway accumulation while boosts
//   are running.  Every SIGIL_BOOST_DROP_RATE_STEP_FP of active boost modifier raises the
//   denominator by 1, capped at SIGIL_BOOST_DROP_RATE_MAX_PENALTY steps.
//   SIGIL_BOOST_DROP_RATE_FLOOR / CEILING clamp the final denominator so drops remain
//   viable in all states and never become excessively rare.
//   Example: base denominator 8, 40% boost → min(floor(40/3), 3) = +3 → 11
//   (~9.09% vs ~12.5% base rate).
define('SIGIL_BOOST_DROP_RATE_STEP_FP',     30000); // 3% boost per denominator-penalty step
define('SIGIL_BOOST_DROP_RATE_MAX_PENALTY',      3); // Maximum denominator increase from boost activity
define('SIGIL_BOOST_DROP_RATE_FLOOR',            5); // Absolute minimum denominator (most generous rate)
define('SIGIL_BOOST_DROP_RATE_CEILING',         20); // Absolute maximum denominator (most restrictive rate)
define('SIGIL_BOOST_DROP_PRESSURE_STEP_FP', 100000); // Each 10% active boost modifier adds pressure
define('SIGIL_BOOST_DROP_PRESSURE_STEP_PENALTY_FP', 100000); // Each pressure step removes 10% drop chance
define('SIGIL_BOOST_DROP_PRESSURE_MIN_FP', 100000); // Boosted drops never fall below 10% of pre-boost chance
define('SIGIL_PACING_JITTER_MIN_FP',        500000); // 50% of base interval
define('SIGIL_PACING_JITTER_MAX_FP',       1500000); // 150% of base interval

// Inventory-based uplift (empty/low inventory => more drops):
//   When a player holds fewer than SIGIL_INVENTORY_UPLIFT_THRESHOLD sigils of a given
//   tier, that tier's conditional odds are increased by SIGIL_INVENTORY_UPLIFT_STEP_FP per
//   step below the threshold, capped at SIGIL_INVENTORY_UPLIFT_MAX_STEPS total steps.
//   This preserves active-player engagement: players who spend sigils on boost activation
//   quickly find their inventories replenished.  Anti-inversion is re-enforced after the
//   uplift so T1 >= T2 >= T3 >= T4 >= T5 is never violated.
define('SIGIL_INVENTORY_UPLIFT_THRESHOLD',  3);     // Uplift applies when tier count < this value
define('SIGIL_INVENTORY_UPLIFT_STEP_FP',   10000);  // Add 1.0% (10,000 / 1,000,000) per uplift step
define('SIGIL_INVENTORY_UPLIFT_MAX_STEPS',      3); // Cap: at most 3 uplift steps per tier

// Per-tier conditional-odds bounds (parts per 1,000,000).
// Dynamic adjustments are clamped within [MIN, MAX] for each tier, then monotonic
// ordering (T1 >= T2 >= T3 >= T4 >= T5) is enforced so lower-tier sigils can never
// become rarer than higher-tier sigils.
define('SIGIL_TIER_ODDS_MIN', [
    1 => 500000,  // T1 (Common)    – never below 50% of conditional drops
    2 =>  80000,  // T2 (Uncommon)  – never below  8%
    3 =>  30000,  // T3 (Rare)      – never below  3%
    4 =>   5000,  // T4 (Epic)      – never below  0.5%
    5 =>   2000,  // T5 (Legendary) – never below  0.2%
]);
define('SIGIL_TIER_ODDS_MAX', [
    1 => 850000,  // T1 – never above 85% of conditional drops
    2 => 350000,  // T2 – never above 35%
    3 => 150000,  // T3 – never above 15%
    4 =>  30000,  // T4 – never above  3%
    5 =>  15000,  // T5 – never above  1.5%
]);

// Sigil progression and crafting
define('SIGIL_MAX_TIER', 6);
define('SIGIL_INVENTORY_TOTAL_CAP', 25);
define('SIGIL_INVENTORY_TIER_CAPS', [
    1 => 0,
    2 => 0,
    3 => 0,
    4 => 0,
    5 => 0,
    6 => 0,
]);
define('SIGIL_INVENTORY_DROP_PRESSURE_START', 10);
define('SIGIL_INVENTORY_DROP_PRESSURE_FULL', SIGIL_INVENTORY_TOTAL_CAP);
define('SIGIL_COMBINE_RECIPES', [
    1 => 5,
    2 => 5,
    3 => 3,
    4 => 3,
    5 => 2,
]);

// Canonical star valuation used for lock-in calculations.
define('SIGIL_REFERENCE_STARS_BY_TIER', [
    1 => 50,
    2 => 250,
    3 => 1000,
    4 => 3000,
    5 => 9000,
    6 => 12000,   // was 0 — the rarest sigil settled for nothing
]);

// Utility valuation is separate from lock-in reference values.
// It is used for tactical ability and theft calculations. Tier 6 utility
// deliberately exceeds its lock-in reference so spending a Tier 6 stays
// strictly better than holding it for lock-in.
define('SIGIL_UTILITY_VALUE_BY_TIER', [
    1 => 50,
    2 => 250,
    3 => 1000,
    4 => 3000,
    5 => 9000,
    6 => 18000,
]);

// Shared tactical timing unit (15 real minutes).
define('ABILITY_UNIT_DURATION_TICKS', ticks_from_real_seconds(900));

// Ability tier gates.
define('SIGIL_FREEZE_SPEND_TIERS', [4, 5, 6]);
define('SIGIL_MELT_SPEND_TIERS', [5, 6]);
define('SIGIL_THEFT_SPEND_TIERS', [3, 4, 5]);
define('SIGIL_THEFT_TARGET_TIERS', [1, 2, 3, 4, 5, 6]);

// Freeze durations by consumed tier. Tier 6 preserves the original premium freeze.
define('SIGIL_FREEZE_DURATION_TICKS_BY_TIER', [
    4 => max(1, intdiv((int)ABILITY_UNIT_DURATION_TICKS, 2)),
    5 => ABILITY_UNIT_DURATION_TICKS,
    6 => ABILITY_UNIT_DURATION_TICKS * 2,
]);
define('SIGIL_FREEZE_STACK_EXTENSION_TICKS_BY_TIER', [
    4 => max(1, intdiv((int)ABILITY_UNIT_DURATION_TICKS, 4)),
    5 => max(1, intdiv((int)ABILITY_UNIT_DURATION_TICKS, 2)),
    6 => ABILITY_UNIT_DURATION_TICKS,
]);

// Melt reductions by consumed tier.
define('SIGIL_MELT_REDUCTION_TICKS_BY_TIER', [
    5 => ABILITY_UNIT_DURATION_TICKS,
    6 => ABILITY_UNIT_DURATION_TICKS * 2,
]);

// Sigil theft tuning.
define('SIGIL_THEFT_SUCCESS_CAP_FP', 600000); // 60% hard cap
define('SIGIL_THEFT_VALUE_PRESSURE_MULTIPLIER', 3);
define('SIGIL_THEFT_COOLDOWN_TICKS', ABILITY_UNIT_DURATION_TICKS);
define('SIGIL_THEFT_PROTECTION_TICKS', ABILITY_UNIT_DURATION_TICKS);

// Tier-odds scaling by sigil power. Tier 6 is intentionally excluded from RNG drops.
define('SIGIL_POWER_FULL_SHIFT', 40);
define('SIGIL_TIER_ODDS_MAX_POWER', [
    1 => 700000,
    2 => 200000,
    3 =>  80000,
    4 =>  15000,
    5 =>   5000,
]);

// Freeze mechanics (legacy Tier 6 aliases)
define('FREEZE_BASE_DURATION_TICKS', ABILITY_UNIT_DURATION_TICKS * 2); // 30 minutes
define('FREEZE_STACK_EXTENSION_TICKS', ABILITY_UNIT_DURATION_TICKS); // +15 minutes per additional freeze

// Blackout-phase pacing (50% of Active timers for cut-throat end-game pressure).
define('SIGIL_THEFT_BLACKOUT_COOLDOWN_TICKS', intdiv((int)SIGIL_THEFT_COOLDOWN_TICKS, 2));
define('SIGIL_THEFT_BLACKOUT_PROTECTION_TICKS', intdiv((int)SIGIL_THEFT_PROTECTION_TICKS, 2));

// Freeze pacing, symmetric with theft.
//
// Freeze previously had NO attacker cooldown, NO per-target protection window,
// and no cap on stacked duration - so a group could hold one player at zero
// income indefinitely, and the victim was never told who was doing it. Theft
// already had both limiters; freeze is the harsher verb and had neither.
define('SIGIL_FREEZE_COOLDOWN_TICKS', ABILITY_UNIT_DURATION_TICKS);
define('SIGIL_FREEZE_PROTECTION_TICKS', ABILITY_UNIT_DURATION_TICKS);
define('SIGIL_FREEZE_BLACKOUT_COOLDOWN_TICKS', intdiv((int)SIGIL_FREEZE_COOLDOWN_TICKS, 2));
define('SIGIL_FREEZE_BLACKOUT_PROTECTION_TICKS', intdiv((int)SIGIL_FREEZE_PROTECTION_TICKS, 2));
define('SIGIL_FREEZE_BLACKOUT_DURATION_TICKS_BY_TIER', [
    4 => max(1, intdiv((int)SIGIL_FREEZE_DURATION_TICKS_BY_TIER[4], 2)),
    5 => max(1, intdiv((int)SIGIL_FREEZE_DURATION_TICKS_BY_TIER[5], 2)),
    6 => max(1, intdiv((int)SIGIL_FREEZE_DURATION_TICKS_BY_TIER[6], 2)),
]);
define('SIGIL_FREEZE_BLACKOUT_STACK_EXTENSION_TICKS_BY_TIER', [
    4 => max(1, intdiv((int)SIGIL_FREEZE_STACK_EXTENSION_TICKS_BY_TIER[4], 2)),
    5 => max(1, intdiv((int)SIGIL_FREEZE_STACK_EXTENSION_TICKS_BY_TIER[5], 2)),
    6 => max(1, intdiv((int)SIGIL_FREEZE_STACK_EXTENSION_TICKS_BY_TIER[6], 2)),
]);
define('FREEZE_BLACKOUT_BASE_DURATION_TICKS', intdiv((int)FREEZE_BASE_DURATION_TICKS, 2));
define('FREEZE_BLACKOUT_STACK_EXTENSION_TICKS', intdiv((int)FREEZE_STACK_EXTENSION_TICKS, 2));

// Guaranteed boost floor: +1 whole coin per tick for each 10% effective boost.
// Set cap to 0 for no cap.
define('BOOST_GUARANTEED_FLOOR_STEP_PERCENT', 10);
define('BOOST_GUARANTEED_FLOOR_STEP_COINS', 1);
define('BOOST_GUARANTEED_FLOOR_CAP_COINS', 5);

// Sigil tier odds (fixed-point, sum = 1,000,000)
// Proportional to SIGIL_TIER_DROP_RATES: T1=8.75%, T2=2.5%, T3=1.0%, T4=0.19%, T5=0.06% of total 12.5%
define('SIGIL_TIER_ODDS', [
    1 => 700000,  // ~8.75% effective T1 at 0 sigil power (70% of all drops)
    2 => 200000,  // ~2.50% effective T2 (20% of all drops)
    3 =>  80000,  // ~1.00% effective T3  (8% of all drops)
    4 =>  15000,  // ~0.19% effective T4 (1.5% of all drops)
    5 =>   5000,  // ~0.06% effective T5 (0.5% of all drops)
]);

// ============================================================
// Sigil families (P0 config keys — see Sigil Systems Spec §10)
// The entire family system is flag-gated: with TMC_SIGIL_FAMILIES_ENABLED
// off (default) every constant below is inert and behavior is identical
// to the pre-family build. Enabling is a per-phase operator decision that
// per AGENTS.md requires an effective-config + simulation run first.
// ============================================================
define('TMC_SIGIL_FAMILIES_ENABLED', filter_var(env_first(['TMC_SIGIL_FAMILIES_ENABLED'], '0'), FILTER_VALIDATE_BOOLEAN));

// Material family weights for the post-tier family roll (fp, sum 1,000,000).
// Sight is not in this table: it rolls separately and displaces nothing.
define('SIGIL_FAMILY_WEIGHTS_FP', [
    'yield'   => 200000,
    'time'    => 200000,
    'ward'    => 200000,
    'larceny' => 200000,
    'market'  => 200000,
]);

// Affinity: picked at join, +8pp to the chosen material family, -2pp to each
// of the other four. Net expected value per tick is unchanged.
define('SIGIL_AFFINITY_BONUS_PP', 8);
define('SIGIL_AFFINITY_PENALTY_PP', 2);
define('SIGIL_AFFINITY_REPICK_PHASE', 'BLACKOUT'); // one free public re-pick

// Sight trickle: separate roll appended to a material drop (fp).
define('SIGIL_SIGHT_TRICKLE_CHANCE_FP', 333000);

// Ward protection units per tier (x100; 100 = one ABILITY_UNIT_DURATION_TICKS
// window = one blocked theft attempt). No Ward at tier 1 by design.
define('WARD_UNITS_X100_BY_TIER', [2 => 25, 3 => 100, 4 => 300, 5 => 900, 6 => 1800]);
define('WARD_MAX_FRACTION_OF_REMAINING_FP', 250000); // <= 25% of remaining season, non-stacking

// Market: one star-purchase discount, rate-relative and self-scoped.
// coins_saved = VP(tier) x rate_hours x own gross hourly rate, capped below.
define('MARKET_RATE_HOURS_PER_VP_FP', 1000000);   // 1.0 hour of own income per VP
define('MARKET_MAX_DISCOUNT_FRACTION_FP', 500000); // cap at 50% of the purchase
define('MARKET_WINDOW_TICKS', SIGIL_DROP_WINDOW_TICKS); // 1 use per 24h window

// Caps introduced with families.
define('CAPS_PER_FAMILY_HOLDING', 8);      // aligns with the dampening threshold
define('CAPS_MODIFIER_CEILING_PCT', 150);  // active-boost ceiling once families enable

// Forge additions: conversion between families enables last (phase 4).
define('FORGE_TRANSMUTE_ENABLED', filter_var(env_first(['TMC_FORGE_TRANSMUTE_ENABLED'], '0'), FILTER_VALIDATE_BOOLEAN));
define('FORGE_DISTIL_ENABLED', filter_var(env_first(['TMC_FORGE_DISTIL_ENABLED'], '0'), FILTER_VALIDATE_BOOLEAN));

// Drama budget: the ticker publishes the act, never the number.
define('EVENTS_PUBLIC_TICKER_ENABLED', filter_var(env_first(['TMC_EVENTS_PUBLIC_TICKER_ENABLED'], '1'), FILTER_VALIDATE_BOOLEAN));
define('EVENTS_PUBLISH_AMOUNTS', filter_var(env_first(['TMC_EVENTS_PUBLISH_AMOUNTS'], '0'), FILTER_VALIDATE_BOOLEAN));

// Participation bonus
define('PARTICIPATION_BONUS_DIVISOR', ticks_from_real_seconds(3600));
define('PARTICIPATION_BONUS_CAP', 56);

// Placement bonus
define('PLACEMENT_BONUS', [1 => 100, 2 => 60, 3 => 40]);

// Cosmetic price tiers
define('COSMETIC_PRICE_TIERS', [25, 80, 250, 800, 2400]);

// Handle rules
define('HANDLE_MIN_LENGTH', 3);
define('HANDLE_MAX_LENGTH', 16);
define('HANDLE_PATTERN', '/^[A-Za-z0-9_]+$/');
define('HANDLE_COOLDOWN_DAYS', 30);
define('RESERVED_HANDLES', ['admin', 'moderator', 'mod', 'support', 'system', 'official']);

// Chat limits
define('CHAT_MAX_ROWS', 200);
define('CHAT_MAX_LENGTH', 500);

// Session
define('SESSION_LIFETIME', 86400);

// Fixed-point scale
define('FP_SCALE', 1000000);
