<?php
/**
 * Shared helpers for tools/admin.php and the command files in tools/admin/commands/.
 *
 * Each command file returns an array:
 *
 *   return [
 *       'name'     => 'season',          // must match the filename
 *       'order'    => 100,               // position in the help text (lower first)
 *       'readOnly' => true,              // run() receives an AdminReadOnlyDb
 *       'usage'    => "  php tools/admin.php season list\n      ...\n",
 *       'run'      => function ($db, array $args, array $flags): int { ... },
 *   ];
 *
 * $args are the positionals after the command name; $flags come from --flag
 * and --flag=value. Adding a command means adding one file: the dispatcher and
 * the help text pick it up, and tools/admin_selfcheck.php checks its shape.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

const ADMIN_ROLES = ['Admin', 'Moderator'];
const ADMIN_COMMANDS_DIR = __DIR__ . '/commands';

/**
 * A Database that can only read.
 *
 * Read-only commands get this instead of the real connection, so "read-only"
 * is something the code enforces rather than something a docstring promises.
 * It also refuses SQL that is not a SELECT/SHOW/DESCRIBE/EXPLAIN, because
 * fetch() on a raw connection would happily run an UPDATE.
 */
final class AdminReadOnlyDb {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function fetch($sql, $params = []) {
        self::assertReadSql($sql);
        return $this->db->fetch($sql, $params);
    }

    public function fetchAll($sql, $params = []) {
        self::assertReadSql($sql);
        return $this->db->fetchAll($sql, $params);
    }

    public function columnExists($table, $column) {
        return $this->db->columnExists($table, $column);
    }

    public static function assertReadSql(string $sql): void {
        $head = strtoupper((string)strtok(ltrim($sql), " \t\r\n("));
        if (!in_array($head, ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN'], true)) {
            // WITH is left out on purpose: MySQL 8 accepts WITH ... UPDATE.
            throw new LogicException("Read-only admin command tried to run non-read SQL: {$head}");
        }
        // A trailing statement after a semicolon is the one way past the check above.
        if (preg_match('/;\s*\S/', $sql)) {
            throw new LogicException('Read-only admin command tried to run more than one statement.');
        }
    }
}

/**
 * Load every command file, keyed by name, sorted by help-text order then name.
 *
 * Throws on a malformed file rather than skipping it: a command that silently
 * vanishes from the CLI is worse than one that refuses to start.
 */
function adminLoadCommands(string $dir = ADMIN_COMMANDS_DIR): array {
    $commands = [];
    foreach (glob($dir . '/*.php') ?: [] as $file) {
        $spec = require $file;
        $expected = basename($file, '.php');
        $problem = adminCommandProblem($spec, $expected);
        if ($problem !== null) {
            throw new RuntimeException(basename($file) . ": {$problem}");
        }
        if (isset($commands[$spec['name']])) {
            throw new RuntimeException("Duplicate admin command \"{$spec['name']}\".");
        }
        $commands[$spec['name']] = $spec + ['order' => 100, 'readOnly' => false];
    }
    uasort($commands, function ($a, $b) {
        return [$a['order'], $a['name']] <=> [$b['order'], $b['name']];
    });
    return $commands;
}

/** Why a command spec is malformed, or null if it is fine. */
function adminCommandProblem($spec, string $expectedName): ?string {
    if (!is_array($spec)) return 'does not return an array';
    if (($spec['name'] ?? null) !== $expectedName) return "name must be \"{$expectedName}\" to match the filename";
    if (!isset($spec['usage']) || !is_string($spec['usage']) || trim($spec['usage']) === '') return 'has no usage text';
    if (substr($spec['usage'], -1) !== "\n") return 'usage text must end with a newline';
    if (!isset($spec['run']) || !is_callable($spec['run'])) return 'has no callable run';
    if (isset($spec['order']) && !is_int($spec['order'])) return 'order must be an int';
    if (isset($spec['readOnly']) && !is_bool($spec['readOnly'])) return 'readOnly must be a bool';
    return null;
}

function adminUsageText(array $commands): string {
    return "Too Many Coins operator CLI\n\n"
        . implode("\n", array_map(function ($c) { return $c['usage']; }, $commands));
}

/** Pull --flag / --flag=value out of argv, returning [positionals, flags]. */
function parseArgs(array $argv): array {
    $positional = [];
    $flags = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (strpos($arg, '--') === 0) {
            $body = substr($arg, 2);
            $eq = strpos($body, '=');
            if ($eq === false) {
                $flags[$body] = true;
            } else {
                $flags[substr($body, 0, $eq)] = substr($body, $eq + 1);
            }
            continue;
        }
        $positional[] = $arg;
    }
    return [$positional, $flags];
}

/** The --reason value, if one was given as --reason="...". */
function adminReason(array $flags): ?string {
    return isset($flags['reason']) && is_string($flags['reason']) ? $flags['reason'] : null;
}

function findPlayer($db, string $handle): ?array {
    return $db->fetch(
        "SELECT player_id, handle, role, email_verified_at, profile_deleted_at,
                created_at, last_seen_at, maintenance_access
         FROM players WHERE handle_lower = LOWER(?)",
        [$handle]
    ) ?: null;
}

/**
 * Fail with a pointer to the migration rather than a raw SQL error.
 *
 * The tester commands are the only thing here that needs the column, so an
 * operator on an un-migrated database should learn that from one line, not
 * from a PDOException naming a column they have never heard of.
 */
function requireMaintenanceAccessColumn($db): bool {
    if ($db->columnExists('players', 'maintenance_access')) {
        return true;
    }
    fwrite(STDERR, "This database has no players.maintenance_access column yet.\n");
    fwrite(STDERR, "Apply migrations/migration_20260731_maintenance_access.sql (php tools/migrate.php,\n");
    fwrite(STDERR, "or a redeploy does it when TMC_AUTO_SQL_MIGRATIONS is on) and try again.\n");
    return false;
}

/**
 * One word for the account's state.
 *
 * "unverified" means no confirmed email address on file, and that flag is now
 * load-bearing: the account signs in, then is refused every action except
 * logout, confirming, asking for another link, and reading enough state to be
 * told so. The gate lives in api/index.php ahead of the action switch, not in
 * Auth::login, which is why sign-in itself still succeeds.
 *
 * So "unverified" reads as a stuck account, not a cosmetic note. Staff are not
 * exempt - an unverified Admin can sign in and reach nothing. If the
 * confirmation mail never arrived, the verify command is the way out; if a
 * whole lane reads unverified, that is a mail misconfiguration, and the web
 * service log will have a [mail-dev] WARNING: line for every send.
 *
 * This docstring has been wrong in both directions now: it first claimed these
 * accounts could not sign in, was corrected to say the flag blocked nothing,
 * and then the email confirmation release made it block almost everything.
 */
function accountState(array $player): string {
    if (!empty($player['profile_deleted_at'])) return 'deleted';
    if (empty($player['email_verified_at'])) return 'unverified';
    return 'ok';
}

/** Marker appended to staff listings so gate access is visible where it matters. */
function gateAccessNote(array $player): string {
    return !empty($player['maintenance_access']) ? '  gate-access' : '';
}

function adminCount($db): int {
    $row = $db->fetch("SELECT COUNT(*) AS n FROM players WHERE role = 'Admin'");
    return (int)($row['n'] ?? 0);
}
