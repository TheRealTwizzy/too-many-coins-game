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

// Activity
define('IDLE_TIMEOUT_TICKS', ticks_from_real_seconds(900));  // 15 real minutes
define('FORCED_OFFLINE_IDLE_HOLD_TICKS', ticks_from_real_seconds(2700)); // 45 real minutes after Idle
define('TMC_PRESENCE_STALE_OFFLINE_SECONDS', max((int)TMC_PRESENCE_TOUCH_SECONDS, (int)(getenv('TMC_PRESENCE_STALE_OFFLINE_SECONDS') ?: 120)));

// Economy v2 scaffolding
define('STARPRICE_MODEL_VERSION_DEFAULT', max(1, (int)(getenv('TMC_STARPRICE_MODEL_VERSION_DEFAULT') ?: 1)));
define('STARPRICE_REACTIVATION_WINDOW_TICKS_DEFAULT', ticks_from_real_seconds(4500)); // 75 real minutes

// Economy tuning windows
define('HOARDING_WINDOW_TICKS', ticks_from_real_seconds(86400));  // 24 real hours

// Lock-In
define('MIN_PARTICIPATION_TICKS', 1);

// Sigil drops
// Deterministic per-tick drop model:
// - One drop attempt per tick
// - Activity scales only the gate chance
// - Season phase gates tier availability and weights
define('SIGIL_DROP_CHANCE_FP', 125000); // 12.5% base gate chance
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
define('SIGIL_EARLY_PHASE_FRACTION_FP', 500000); // First half of non-blackout window
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
define('SIGIL_DROP_ALGORITHM_VERSION', 'deterministic_v3');

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
    6 => 0,
]);

// Utility valuation is separate from lock-in reference values.
// It is used for tactical ability and theft calculations, where Tier 6 must
// carry explicit value instead of inheriting the lock-in-only zero.
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
define('SIGIL_FREEZE_SPEND_TIERS', [6]);
define('SIGIL_MELT_SPEND_TIERS', [5, 6]);
define('SIGIL_THEFT_SPEND_TIERS', [4, 5]);
define('SIGIL_THEFT_TARGET_TIERS', [1, 2, 3, 4, 5, 6]);

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

// Freeze mechanics (Tier 6 sigil action)
define('FREEZE_BASE_DURATION_TICKS', ABILITY_UNIT_DURATION_TICKS * 2); // 30 minutes
define('FREEZE_STACK_EXTENSION_TICKS', ABILITY_UNIT_DURATION_TICKS); // +15 minutes per additional freeze

// Blackout-phase pacing (50% of Active timers for cut-throat end-game pressure).
define('SIGIL_THEFT_BLACKOUT_COOLDOWN_TICKS', intdiv((int)SIGIL_THEFT_COOLDOWN_TICKS, 2));
define('SIGIL_THEFT_BLACKOUT_PROTECTION_TICKS', intdiv((int)SIGIL_THEFT_PROTECTION_TICKS, 2));
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
