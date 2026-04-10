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
