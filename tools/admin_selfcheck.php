#!/usr/bin/env php
<?php
/**
 * Too Many Coins - Operator CLI Self-Check
 *
 *   php tools/admin_selfcheck.php
 *
 * tools/admin.php is a dispatcher over tools/admin/commands/*.php. This checks
 * the parts that break quietly when a command file is added or edited:
 *
 *   - every command file loads and has the documented shape
 *   - the help text for the original seven commands is byte-for-byte what it
 *     was before the split, so operator muscle memory and pasted docs still hold
 *   - every command's usage names the command it belongs to
 *   - a command marked readOnly cannot write: the wrapper refuses non-read SQL,
 *     and its source does not reach for query()/insert()/transactions
 *   - the dispatcher itself prints help and rejects unknown commands without
 *     touching a database
 *
 * No server and no database required.
 *
 * Exit: 0 = every check passed, 1 = at least one is wrong.
 */

require_once __DIR__ . '/admin/lib.php';

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

/** Throws-or-not, as a bool, so a check can assert either outcome. */
function throws(callable $fn): bool {
    try {
        $fn();
        return false;
    } catch (Throwable $e) {
        return true;
    }
}

/** Minimal stand-in for Database: records every call, returns canned rows. */
final class FakeDb {
    public $calls = [];
    private $rows;

    public function __construct(array $rows = []) {
        $this->rows = $rows;
    }

    public function fetch($sql, $params = []) {
        $this->calls[] = ['fetch', $sql];
        return $this->rows[0] ?? false;
    }

    public function fetchAll($sql, $params = []) {
        $this->calls[] = ['fetchAll', $sql];
        return $this->rows;
    }

    public function columnExists($table, $column) {
        $this->calls[] = ['columnExists', "{$table}.{$column}"];
        return true;
    }

    public function query($sql, $params = []) {
        $this->calls[] = ['query', $sql];
        throw new LogicException('FakeDb::query reached');
    }
}

// The help text as it was before tools/admin.php was split into command files.
const ORIGINAL_USAGE = <<<TXT
Too Many Coins operator CLI

  php tools/admin.php staff
      List every account that is not a plain Player.

  php tools/admin.php show <handle>
      Show one account's role and status.

  php tools/admin.php promote <handle> [Admin|Moderator] [--reason="..."]
      Grant staff powers. Defaults to Admin.

  php tools/admin.php demote <handle> [--reason="..."] [--force]
      Return an account to Player. Refuses to remove the last Admin
      unless --force is given.

  php tools/admin.php verify <handle> [--reason="..."]
      Mark an account's email address as confirmed. There is no
      self-service verification flow yet, so this is the only way
      an account gets a confirmed address.

  php tools/admin.php gate status|on|off [--reason="..."]
      Read or set the maintenance lockdown gate.

  php tools/admin.php tester list
      Accounts allowed through the gate without staff powers.

  php tools/admin.php tester grant|revoke <handle> [--reason="..."]
      Allow or stop allowing an existing account through the gate.

  php tools/admin.php tester create <handle> [--email=...] [--reason="..."]
      Make a throwaway play-test account: pre-verified, gate-allowed,
      role Player. Prints a generated password once.

TXT;
const ORIGINAL_COMMANDS = ['staff', 'show', 'promote', 'demote', 'verify', 'gate', 'tester'];

echo "Loading command files\n";
$commands = [];
check('every command file loads with a valid shape', !throws(function () use (&$commands) {
    $commands = adminLoadCommands();
}));

foreach (ORIGINAL_COMMANDS as $name) {
    check("command \"{$name}\" is registered", isset($commands[$name]));
}

echo "\nHelp text\n";
$original = array_intersect_key($commands, array_flip(ORIGINAL_COMMANDS));
check('original seven commands keep their exact help text and order',
    // Line endings normalised: a Windows checkout with autocrlf turns this
    // file's heredoc into CRLF while the command files' "\n" strings stay LF.
    adminUsageText($original) === str_replace("\r\n", "\n", ORIGINAL_USAGE),
    'adminUsageText() for the original commands differs from the pre-split text');

foreach ($commands as $name => $spec) {
    check("usage for \"{$name}\" names it",
        strpos($spec['usage'], "php tools/admin.php {$name}") !== false);
}

$full = adminUsageText($commands);
foreach ($commands as $name => $spec) {
    check("full help lists \"{$name}\"", strpos($full, $spec['usage']) !== false);
}

echo "\nSpec validation catches malformed command files\n";
$run = function ($db, array $a, array $f): int { return 0; };
$good = ['name' => 'x', 'usage' => "  php tools/admin.php x\n", 'run' => $run];
check('a well-formed spec passes', adminCommandProblem($good, 'x') === null);
check('a non-array is rejected', adminCommandProblem(null, 'x') !== null);
check('a name that does not match the file is rejected', adminCommandProblem($good, 'y') !== null);
check('missing usage is rejected', adminCommandProblem(['usage' => ''] + $good, 'x') !== null);
check('usage without a trailing newline is rejected',
    adminCommandProblem(['usage' => '  php tools/admin.php x'] + $good, 'x') !== null);
check('a non-callable run is rejected', adminCommandProblem(['run' => 'nope_not_a_function'] + $good, 'x') !== null);
check('a non-int order is rejected', adminCommandProblem(['order' => '5'] + $good, 'x') !== null);
check('a non-bool readOnly is rejected', adminCommandProblem(['readOnly' => 1] + $good, 'x') !== null);

echo "\nArgument parsing\n";
[$pos, $flags] = parseArgs(['admin.php', 'tester', 'create', 'bob', '--email=a@b.c', '--force', '--reason=x=y']);
check('positionals keep their order', $pos === ['tester', 'create', 'bob'], $pos);
check('--flag=value and bare --flag both parse',
    ($flags['email'] ?? null) === 'a@b.c' && ($flags['force'] ?? null) === true, $flags);
check('only the first = splits a flag', ($flags['reason'] ?? null) === 'x=y', $flags);
check('adminReason ignores a bare --reason', adminReason(['reason' => true]) === null);

echo "\nRead-only enforcement\n";
$ro = new AdminReadOnlyDb(new FakeDb());
foreach ([
    'SELECT 1',
    "  select handle FROM players",
    "(SELECT 1) UNION (SELECT 2)",
    'SHOW TABLES',
    'DESCRIBE players',
    'EXPLAIN SELECT 1',
] as $sql) {
    check("allows: {$sql}", !throws(function () use ($ro, $sql) { $ro->fetchAll($sql); }));
}
foreach ([
    "UPDATE players SET role = 'Admin'",
    'INSERT INTO players VALUES (1)',
    'DELETE FROM players',
    'WITH x AS (SELECT 1) UPDATE players SET role = x',
    'SELECT 1; DROP TABLE players',
    'REPLACE INTO players VALUES (1)',
] as $sql) {
    check("refuses: {$sql}", throws(function () use ($ro, $sql) { $ro->fetch($sql); }));
}
check('the wrapper exposes no query()', !method_exists($ro, 'query'));
check('the wrapper exposes no insert()', !method_exists($ro, 'insert'));
check('the wrapper exposes no beginTransaction()', !method_exists($ro, 'beginTransaction'));

foreach ($commands as $name => $spec) {
    if (!$spec['readOnly']) continue;
    $src = (string)file_get_contents(ADMIN_COMMANDS_DIR . "/{$name}.php");
    check("readOnly \"{$name}\" never calls a write method",
        !preg_match('/->\s*(query|insert|beginTransaction|commit|rollback)\s*\(/', $src));
}

echo "\nRead-only commands run against the wrapper\n";
ob_start();
$code = ($commands['staff']['run'])(new AdminReadOnlyDb(new FakeDb([])), [], []);
$out = ob_get_clean();
check('staff with no staff accounts exits 0 and says so',
    $code === 0 && strpos($out, 'No staff accounts exist.') !== false, $out);

$fake = new FakeDb([[
    'handle' => 'alice', 'role' => 'Admin', 'email_verified_at' => '2026-01-01 00:00:00',
    'profile_deleted_at' => null, 'created_at' => '2026-01-01', 'last_seen_at' => null,
    'maintenance_access' => 0, 'n' => 1,
]]);
ob_start();
$code = ($commands['show']['run'])(new AdminReadOnlyDb($fake), ['alice'], []);
$out = ob_get_clean();
check('show prints the account through the wrapper',
    $code === 0 && strpos($out, 'handle:    alice') !== false && strpos($out, 'state:     ok') !== false, $out);

echo "\nDispatcher (no database)\n";
function runAdmin(array $argv): array {
    $cmd = array_merge([PHP_BINARY, __DIR__ . '/admin.php'], $argv);
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) return [-1, '', ''];
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($proc), $stdout, $stderr];
}

[$code, , $err] = runAdmin([]);
check('no arguments prints the full help to stderr and exits 1',
    $code === 1 && $err === $full, ['code' => $code, 'stderr_head' => substr($err, 0, 80)]);

[$code, , $err] = runAdmin(['staff', '--help']);
check('--help after a command exits 0 with the full help', $code === 0 && $err === $full, $code);

[$code, , $err] = runAdmin(['no-such-command']);
check('an unknown command exits 1 and names it',
    $code === 1 && strpos($err, 'Unknown command "no-such-command"') === 0, substr($err, 0, 80));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
