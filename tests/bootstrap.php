<?php
/**
 * PHPUnit bootstrap for Too Many Coins test suite.
 *
 * Pre-sets DB_* env vars to safe stub values BEFORE any test class loads
 * config.php. This prevents env_first() in config.php from falling through
 * to HOSTINGER_DB_*, MYSQLHOST, or other inherited remote/tunneled DB
 * environment variables.
 *
 * DB-backed fresh-run tests (@group freshdb) override these stubs with
 * explicit TMC_FRESHDB_TEST_* coordinates in their own setUp().
 *
 * NOTE: PHP constants defined by config.php are immutable once set, so this
 * bootstrap must run first to ensure the constants bind to stubs and not to
 * any inherited production/tunneled env vars.
 */

// Pre-set safe DB stubs — env_first() will stop at these and never reach
// HOSTINGER_DB_* or other platform aliases.
$_stubDbVars = [
    'DB_HOST' => 'stub',
    'DB_PORT' => 'stub',
    'DB_NAME' => 'stub',
    'DB_USER' => 'stub',
    'DB_PASS' => 'stub',
];

foreach ($_stubDbVars as $key => $value) {
    // Only set if not already explicitly provided by the test runner
    if (getenv($key) === false || getenv($key) === '') {
        putenv("$key=$value");
    }
}

unset($_stubDbVars);

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// ---------------------------------------------------------------------------
// Pre-load the include chain (config → database → game_time) so that:
//   1. PHP's require_once cache is primed — test files' own require_once calls
//      become harmless no-ops.
//   2. We can pre-set a deterministic GameTime server epoch, preventing
//      GameTime::getServerEpoch() from ever calling Database::getInstance()
//      during non-DB tests.  Without this, any code path that reaches
//      GameTime::now() without a simulation tick override would attempt a real
//      PDO connection to host="stub", which hangs on DNS resolution.
//
// Loading database.php only *defines* the Database class; no PDO connection
// is made until Database::getInstance() is called, which non-DB tests avoid.
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/game_time.php';

/**
 * Deterministic test epoch: 2024-01-01 00:00:00 UTC (Unix 1704067200).
 *
 * Using a fixed value (not time()) ensures:
 *   - No wall-clock drift across tests in the same run.
 *   - Fully deterministic GameTime::now() / tickStartRealUnix() results.
 *   - Tests that manipulate and restore the epoch always return to a known state.
 */
define('TMC_TEST_EPOCH', 1704067200);
GameTime::setServerEpoch(TMC_TEST_EPOCH);
