<?php
/**
 * Fresh-run simulation safety boundary.
 *
 * Fail-closed validation that prevents fresh-run simulation from executing
 * writes against unsafe, ambiguous, or production/test DB targets.
 *
 * Every check in this class MUST fail closed: if a condition is ambiguous,
 * missing, or unrecognised, the simulation MUST abort before any write.
 */

class FreshRunSafety
{
    /** DB-name prefixes considered safe for disposable fresh-run databases. */
    private const SAFE_DB_NAME_PREFIXES = [
        'tmc_sim_',
        'tmc_fresh_',
        'tmc_test_sim_',
    ];

    /** Exact DB names that are NEVER allowed regardless of prefix match. */
    private const PROTECTED_DB_NAMES = [
        'too_many_coins',
        'too_many_coins_test',
        'too_many_coins_live',
        'too_many_coins_staging',
        'tmc_production',
        'tmc_live',
    ];

    /** Hosts considered safe for fresh-run local DB connections. */
    private const SAFE_HOSTS = [
        '127.0.0.1',
        'localhost',
        '::1',
    ];

    /** Environment variable that must be set to 'fresh-run' to enable fresh-run mode. */
    public const ENV_SIMULATION_MODE = 'TMC_SIMULATION_MODE';

    /** Environment variable that must be set to '1' for destructive bootstrap/reset. */
    public const ENV_DESTRUCTIVE_RESET = 'TMC_FRESH_RUN_DESTRUCTIVE_RESET';

    /**
     * Validate all safety preconditions for a fresh-run simulation.
     *
     * Returns an array of error strings. Empty array means all checks passed.
     * Callers MUST abort if the array is non-empty.
     *
     * @param string $dbHost  Database host
     * @param string $dbPort  Database port
     * @param string $dbName  Database name
     * @param string $dbUser  Database user
     * @param bool   $destructiveReset  Whether the caller intends destructive bootstrap/reset
     * @return string[]  List of validation errors (empty = safe to proceed)
     */
    public static function validate(
        string $dbHost,
        string $dbPort,
        string $dbName,
        string $dbUser,
        bool $destructiveReset = false
    ): array {
        $errors = [];

        // 1. Simulation mode flag MUST be exactly 'fresh-run'
        $simMode = getenv(self::ENV_SIMULATION_MODE);
        if ($simMode !== 'fresh-run') {
            $errors[] = sprintf(
                'Environment %s must be set to "fresh-run" (got: %s)',
                self::ENV_SIMULATION_MODE,
                $simMode === false ? '<unset>' : var_export($simMode, true)
            );
        }

        // 2. If destructive reset is requested, the destructive-reset flag MUST be '1'
        if ($destructiveReset) {
            $resetFlag = getenv(self::ENV_DESTRUCTIVE_RESET);
            if ($resetFlag !== '1') {
                $errors[] = sprintf(
                    'Destructive reset requested but %s is not "1" (got: %s)',
                    self::ENV_DESTRUCTIVE_RESET,
                    $resetFlag === false ? '<unset>' : var_export($resetFlag, true)
                );
            }
        }

        // 3. DB host MUST be a known safe local target
        if (!self::isSafeHost($dbHost)) {
            $errors[] = sprintf(
                'DB host "%s" is not a safe local target. Allowed: %s',
                $dbHost,
                implode(', ', self::SAFE_HOSTS)
            );
        }

        // 4. DB name MUST NOT be a known protected name
        $dbNameLower = strtolower(trim($dbName));
        if (in_array($dbNameLower, self::PROTECTED_DB_NAMES, true)) {
            $errors[] = sprintf(
                'DB name "%s" is a protected production/test database and cannot be used for fresh-run simulation',
                $dbName
            );
        }

        // 5. DB name MUST match a safe prefix pattern
        if (!self::isSafeDbName($dbName)) {
            $errors[] = sprintf(
                'DB name "%s" does not match any safe fresh-run prefix (%s)',
                $dbName,
                implode(', ', self::SAFE_DB_NAME_PREFIXES)
            );
        }

        // 6. DB name and port must not be empty
        if ($dbNameLower === '') {
            $errors[] = 'DB name is empty';
        }
        if (trim($dbPort) === '') {
            $errors[] = 'DB port is empty';
        }
        if (trim($dbUser) === '') {
            $errors[] = 'DB user is empty';
        }

        return $errors;
    }

    /**
     * Run validation and abort with exit code 1 if any check fails.
     * Prints each error to STDERR before exiting.
     */
    public static function validateOrDie(
        string $dbHost,
        string $dbPort,
        string $dbName,
        string $dbUser,
        bool $destructiveReset = false
    ): void {
        $errors = self::validate($dbHost, $dbPort, $dbName, $dbUser, $destructiveReset);
        if (!empty($errors)) {
            fwrite(STDERR, "FRESH-RUN SAFETY ABORT — cannot proceed:\n");
            foreach ($errors as $error) {
                fwrite(STDERR, "  • $error\n");
            }
            exit(1);
        }
    }

    /**
     * Check whether a host is considered safe for fresh-run DB connections.
     */
    public static function isSafeHost(string $host): bool
    {
        return in_array(strtolower(trim($host)), self::SAFE_HOSTS, true);
    }

    /**
     * Check whether a DB name matches the safe fresh-run allowlist pattern.
     */
    public static function isSafeDbName(string $dbName): bool
    {
        $lower = strtolower(trim($dbName));
        if ($lower === '') {
            return false;
        }
        // Must not be a protected name (double-check)
        if (in_array($lower, self::PROTECTED_DB_NAMES, true)) {
            return false;
        }
        foreach (self::SAFE_DB_NAME_PREFIXES as $prefix) {
            if (strpos($lower, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }
}
