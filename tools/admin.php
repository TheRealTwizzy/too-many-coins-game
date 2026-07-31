#!/usr/bin/env php
<?php
/**
 * Too Many Coins - Operator CLI
 *
 *   php tools/admin.php staff
 *   php tools/admin.php show <handle>
 *   php tools/admin.php promote <handle> [Admin|Moderator] [--reason="..."]
 *   php tools/admin.php demote <handle> [--reason="..."] [--force]
 *   php tools/admin.php gate status|on|off [--reason="..."]
 *
 * Two things cannot be done from inside the game, and both previously meant
 * hand-writing SQL in a container with no mysql client:
 *
 *   - The first Admin. admin_role_update requires an existing Admin, so the
 *     first one has to be made from outside.
 *   - Break-glass on the maintenance gate, for when no Admin can sign in.
 *
 * Hand-written SQL for those is not just awkward, it is silent: an UPDATE
 * matching zero rows reports success, and nothing lands in the audit log. Every
 * command here reports what actually changed and records an audit row with a
 * NULL actor, which is the honest representation - a change made by whoever
 * had shell access, not by a player.
 *
 * Prefer the in-game staff screen once an Admin exists. It does the same work
 * with a real actor attached.
 *
 * Exit: 0 on success, 1 on failure or a refused operation.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/audit.php';

const ADMIN_ROLES = ['Admin', 'Moderator'];

function usage(): void {
    fwrite(STDERR, <<<TXT
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

TXT);
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

function findPlayer(Database $db, string $handle): ?array {
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
function requireMaintenanceAccessColumn(Database $db): bool {
    if ($db->columnExists('players', 'maintenance_access')) {
        return true;
    }
    fwrite(STDERR, "This database has no players.maintenance_access column yet.\n");
    fwrite(STDERR, "Apply migration_20260731_maintenance_access.sql (a redeploy does it\n");
    fwrite(STDERR, "automatically when TMC_AUTO_SQL_MIGRATIONS is on) and try again.\n");
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
 * confirmation mail never arrived, cmdVerify below is the way out; if a whole
 * lane reads unverified, that is a mail misconfiguration, and the web service
 * log will have a [mail-dev] WARNING: line for every send.
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

function adminCount(Database $db): int {
    $row = $db->fetch("SELECT COUNT(*) AS n FROM players WHERE role = 'Admin'");
    return (int)($row['n'] ?? 0);
}

function cmdStaff(Database $db): int {
    $rows = $db->fetchAll(
        "SELECT handle, role, email_verified_at, profile_deleted_at
         FROM players WHERE role <> 'Player' ORDER BY role, handle"
    );
    if (!$rows) {
        echo "No staff accounts exist.\n";
        echo "The in-game staff screen needs an Admin before it can grant anything,\n";
        echo "so make the first one here:  php tools/admin.php promote <handle>\n";
        return 0;
    }

    $unverified = 0;
    foreach ($rows as $row) {
        $state = accountState($row);
        if ($state === 'unverified') $unverified++;
        printf("%-18s %-10s %s\n", $row['handle'], $row['role'], $state);
    }
    printf("\n%d staff account(s), %d Admin.\n", count($rows), adminCount($db));
    if ($unverified > 0) {
        printf("%d with no confirmed email address. Those accounts sign in but are\n", $unverified);
        echo "refused every action except logout, confirming and resending - staff\n";
        echo "powers included. If the mail never arrived:  admin.php verify <handle>\n";
    }
    return 0;
}

function cmdShow(Database $db, ?string $handle): int {
    if ($handle === null || $handle === '') {
        fwrite(STDERR, "show needs a handle.\n");
        return 1;
    }
    $player = findPlayer($db, $handle);
    if (!$player) {
        fwrite(STDERR, "No account with handle \"{$handle}\".\n");
        return 1;
    }
    printf("handle:    %s\n", $player['handle']);
    printf("role:      %s\n", $player['role']);
    printf("state:     %s\n", accountState($player));
    printf("verified:  %s\n", $player['email_verified_at'] ?: 'never');
    printf("created:   %s\n", $player['created_at'] ?: 'unknown');
    printf("last seen: %s\n", $player['last_seen_at'] ?: 'never');
    return 0;
}

function cmdPromote(Database $db, ?string $handle, ?string $role, ?string $reason): int {
    if ($handle === null || $handle === '') {
        fwrite(STDERR, "promote needs a handle.\n");
        return 1;
    }

    $role = $role === null || $role === '' ? 'Admin' : ucfirst(strtolower($role));
    if (!in_array($role, ADMIN_ROLES, true)) {
        fwrite(STDERR, "Role must be Admin or Moderator, got \"{$role}\".\n");
        return 1;
    }

    $player = findPlayer($db, $handle);
    if (!$player) {
        // Deliberately not "no rows updated" - a typo'd handle and an account
        // that is already at the target role are different outcomes, and a bare
        // UPDATE cannot tell them apart.
        fwrite(STDERR, "No account with handle \"{$handle}\". Nothing changed.\n");
        return 1;
    }

    if ($player['role'] === $role) {
        echo "{$player['handle']} is already {$role}. Nothing to do.\n";
        return 0;
    }

    $before = $player['role'];
    $changed = $db->query(
        "UPDATE players SET role = ? WHERE player_id = ?",
        [$role, (int)$player['player_id']]
    )->rowCount();

    if ($changed !== 1) {
        fwrite(STDERR, "Update matched {$changed} rows; expected 1. Nothing to trust here - check the account by hand.\n");
        return 1;
    }

    Audit::record(
        null, (int)$player['player_id'], 'cli_role_update',
        $reason ?? 'Role change via tools/admin.php',
        ['role' => $before], ['role' => $role]
    );

    echo "{$player['handle']}: {$before} -> {$role}\n";
    if ($role === 'Admin' && $before !== 'Admin') {
        echo "Sign in as {$player['handle']} to reach the staff screen; further role changes belong there.\n";
    }
    return 0;
}

function cmdDemote(Database $db, ?string $handle, ?string $reason, bool $force): int {
    if ($handle === null || $handle === '') {
        fwrite(STDERR, "demote needs a handle.\n");
        return 1;
    }

    $player = findPlayer($db, $handle);
    if (!$player) {
        fwrite(STDERR, "No account with handle \"{$handle}\". Nothing changed.\n");
        return 1;
    }

    if ($player['role'] === 'Player') {
        echo "{$player['handle']} is already a Player. Nothing to do.\n";
        return 0;
    }

    // Removing the last Admin locks everyone out of the staff screen, which
    // includes the ability to put an Admin back. Recoverable only by returning
    // to this CLI, so it is refused rather than warned about.
    if ($player['role'] === 'Admin' && adminCount($db) === 1 && !$force) {
        fwrite(STDERR, "{$player['handle']} is the only Admin. Demoting them leaves nobody able to\n");
        fwrite(STDERR, "use the staff screen, including to appoint a replacement.\n");
        fwrite(STDERR, "Promote someone else first, or pass --force if that is genuinely intended.\n");
        return 1;
    }

    $before = $player['role'];
    $changed = $db->query(
        "UPDATE players SET role = 'Player' WHERE player_id = ?",
        [(int)$player['player_id']]
    )->rowCount();

    if ($changed !== 1) {
        fwrite(STDERR, "Update matched {$changed} rows; expected 1. Check the account by hand.\n");
        return 1;
    }

    Audit::record(
        null, (int)$player['player_id'], 'cli_role_update',
        $reason ?? 'Role change via tools/admin.php',
        ['role' => $before], ['role' => 'Player']
    );

    echo "{$player['handle']}: {$before} -> Player\n";
    return 0;
}

function cmdGate(Database $db, ?string $mode, ?string $reason): int {
    $current = (string)($db->fetch("SELECT server_mode FROM server_state WHERE id = 1")['server_mode'] ?? 'NORMAL');
    $mode = $mode === null ? 'status' : strtolower($mode);

    if ($mode === 'status') {
        echo "server_mode: {$current}\n";
        echo $current === 'MAINTENANCE_LOCKDOWN'
            ? "Non-staff see the construction page; auth and read-only actions stay open.\n"
            : "The game is open to everyone.\n";
        return 0;
    }

    if ($mode !== 'on' && $mode !== 'off') {
        fwrite(STDERR, "gate takes status, on, or off.\n");
        return 1;
    }

    $target = $mode === 'on' ? 'MAINTENANCE_LOCKDOWN' : 'NORMAL';
    if ($current === $target) {
        echo "server_mode is already {$target}. Nothing to do.\n";
        return 0;
    }

    $db->query("UPDATE server_state SET server_mode = ? WHERE id = 1", [$target]);
    $now = (string)($db->fetch("SELECT server_mode FROM server_state WHERE id = 1")['server_mode'] ?? '');

    if ($now !== $target) {
        fwrite(STDERR, "Wrote {$target} but the row reads {$now}. Nothing changed reliably.\n");
        return 1;
    }

    Audit::record(
        null, null, 'cli_server_mode',
        $reason ?? 'Server mode change via tools/admin.php',
        ['server_mode' => $current], ['server_mode' => $target]
    );

    echo "server_mode: {$current} -> {$target}\n";
    echo $target === 'MAINTENANCE_LOCKDOWN'
        ? "Players are now blocked. Undo with: php tools/admin.php gate off\n"
        : "The game is open. Undo with: php tools/admin.php gate on\n";
    return 0;
}

function cmdVerify(Database $db, ?string $handle, ?string $reason): int {
    if ($handle === null || $handle === '') {
        fwrite(STDERR, "verify needs a handle.\n");
        return 1;
    }

    $player = findPlayer($db, $handle);
    if (!$player) {
        fwrite(STDERR, "No account with handle \"{$handle}\". Nothing changed.\n");
        return 1;
    }

    if (!empty($player['email_verified_at'])) {
        echo "{$player['handle']} was already confirmed at {$player['email_verified_at']}. Nothing to do.\n";
        return 0;
    }

    // Guarded on the NULL rather than blind, so a concurrent confirmation
    // cannot be silently overwritten with a later timestamp.
    $changed = $db->query(
        "UPDATE players SET email_verified_at = NOW()
         WHERE player_id = ? AND email_verified_at IS NULL",
        [(int)$player['player_id']]
    )->rowCount();

    if ($changed !== 1) {
        fwrite(STDERR, "Update matched {$changed} rows; expected 1. Check the account by hand.\n");
        return 1;
    }

    Audit::record(
        null, (int)$player['player_id'], 'cli_email_verified',
        $reason ?? 'Email marked confirmed via tools/admin.php',
        ['email_verified_at' => null], ['email_verified_at' => 'now']
    );

    echo "{$player['handle']}: email marked confirmed\n";
    echo "This asserts control of the address on the operator's word - it bypasses\n";
    echo "the confirmation mail rather than proving anything. Use it when mail is\n";
    echo "broken, not instead of fixing mail.\n";
    return 0;
}

function cmdTester(Database $db, ?string $sub, ?string $handle, ?string $email, ?string $reason): int {
    if (!requireMaintenanceAccessColumn($db)) {
        return 1;
    }

    $sub = $sub === null ? 'list' : strtolower($sub);

    if ($sub === 'list') {
        $rows = $db->fetchAll(
            "SELECT handle, role, email_verified_at, profile_deleted_at
             FROM players WHERE maintenance_access = 1 ORDER BY handle"
        );
        if (!$rows) {
            echo "No accounts are allowed through the maintenance gate.\n";
            echo "Staff always pass it, but staff cannot join a season - make a\n";
            echo "play-test account with:  php tools/admin.php tester create <handle>\n";
            return 0;
        }
        foreach ($rows as $row) {
            printf("%-18s %-10s %s\n", $row['handle'], $row['role'], accountState($row));
        }
        printf("\n%d account(s) allowed through the gate.\n", count($rows));
        return 0;
    }

    if ($sub === 'grant' || $sub === 'revoke') {
        if ($handle === null || $handle === '') {
            fwrite(STDERR, "tester {$sub} needs a handle.\n");
            return 1;
        }
        $player = findPlayer($db, $handle);
        if (!$player) {
            fwrite(STDERR, "No account with handle \"{$handle}\". Nothing changed.\n");
            return 1;
        }

        $target = $sub === 'grant' ? 1 : 0;
        $before = (int)($player['maintenance_access'] ?? 0);
        if ($before === $target) {
            echo "{$player['handle']} already " . ($target ? "has" : "does not have")
                . " gate access. Nothing to do.\n";
            return 0;
        }

        $changed = $db->query(
            "UPDATE players SET maintenance_access = ? WHERE player_id = ?",
            [$target, (int)$player['player_id']]
        )->rowCount();
        if ($changed !== 1) {
            fwrite(STDERR, "Update matched {$changed} rows; expected 1. Check the account by hand.\n");
            return 1;
        }

        Audit::record(
            null, (int)$player['player_id'], 'cli_maintenance_access',
            $reason ?? 'Gate access change via tools/admin.php',
            ['maintenance_access' => $before], ['maintenance_access' => $target]
        );

        echo "{$player['handle']}: gate access " . ($target ? "granted" : "revoked") . "\n";
        return 0;
    }

    if ($sub === 'create') {
        if ($handle === null || $handle === '') {
            fwrite(STDERR, "tester create needs a handle.\n");
            return 1;
        }
        if (!preg_match(HANDLE_PATTERN, $handle)
            || strlen($handle) < HANDLE_MIN_LENGTH || strlen($handle) > HANDLE_MAX_LENGTH) {
            fwrite(STDERR, "Handle must be " . HANDLE_MIN_LENGTH . "-" . HANDLE_MAX_LENGTH
                . " characters of A-Z, a-z, 0-9 or underscore.\n");
            return 1;
        }
        if (in_array(strtolower($handle), array_map('strtolower', RESERVED_HANDLES), true)) {
            fwrite(STDERR, "\"{$handle}\" is a reserved handle.\n");
            return 1;
        }

        $handleLower = strtolower($handle);
        if ($db->fetch("SELECT player_id FROM players WHERE handle_lower = ?", [$handleLower])
            || $db->fetch("SELECT handle_lower FROM handle_registry WHERE handle_lower = ?", [$handleLower])) {
            fwrite(STDERR, "Handle \"{$handle}\" is already taken or previously used.\n");
            return 1;
        }

        $email = $email !== null && $email !== '' ? strtolower(trim($email)) : $handleLower . '@playtest.invalid';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            fwrite(STDERR, "\"{$email}\" is not a valid email address.\n");
            return 1;
        }
        if ($db->fetch("SELECT player_id FROM players WHERE email = ?", [$email])) {
            fwrite(STDERR, "Email \"{$email}\" is already registered.\n");
            return 1;
        }

        // Generated rather than chosen: a play-test account that outlives the
        // test is a real account, and one created with a password an operator
        // reuses is a real problem. Printed once, never stored in plaintext.
        $password = bin2hex(random_bytes(9));
        $hash = password_hash($password, PASSWORD_BCRYPT);

        $db->beginTransaction();
        try {
            // Pre-verified on purpose. The whole point is testing a gated
            // build, which is exactly the situation where outbound mail may
            // not be configured yet - requiring an email round trip would make
            // the command useless when it is most needed.
            $playerId = $db->insert(
                "INSERT INTO players
                    (handle, handle_lower, email, password_hash, email_verified_at,
                     maintenance_access, online_current, last_seen_at)
                 VALUES (?, ?, ?, ?, NOW(), 1, 0, NOW())",
                [$handle, $handleLower, $email, $hash]
            );
            $db->query(
                "INSERT INTO handle_registry (handle_lower, player_id) VALUES (?, ?)",
                [$handleLower, $playerId]
            );
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            fwrite(STDERR, "Could not create the account: " . $e->getMessage() . "\n");
            return 1;
        }

        Audit::record(
            null, (int)$playerId, 'cli_tester_create',
            $reason ?? 'Play-test account created via tools/admin.php',
            null, ['handle' => $handle, 'maintenance_access' => 1]
        );

        echo "Created play-test account.\n\n";
        printf("  handle:   %s\n", $handle);
        printf("  email:    %s\n", $email);
        printf("  password: %s\n\n", $password);
        echo "Shown once - it is stored only as a hash. Role is Player, so this\n";
        echo "account can join seasons; gate access is on, so it can play while\n";
        echo "maintenance is up. Revoke with: php tools/admin.php tester revoke {$handle}\n";
        return 0;
    }

    fwrite(STDERR, "tester takes list, grant, revoke, or create.\n");
    return 1;
}

[$args, $flags] = parseArgs($argv);
$command = $args[0] ?? null;
$reason = isset($flags['reason']) && is_string($flags['reason']) ? $flags['reason'] : null;

if ($command === null || isset($flags['help'])) {
    usage();
    exit($command === null ? 1 : 0);
}

$db = Database::getInstance();

switch ($command) {
    case 'staff':
        exit(cmdStaff($db));
    case 'show':
        exit(cmdShow($db, $args[1] ?? null));
    case 'promote':
        exit(cmdPromote($db, $args[1] ?? null, $args[2] ?? null, $reason));
    case 'demote':
        exit(cmdDemote($db, $args[1] ?? null, $reason, isset($flags['force'])));
    case 'verify':
        exit(cmdVerify($db, $args[1] ?? null, $reason));
    case 'gate':
        exit(cmdGate($db, $args[1] ?? null, $reason));
    case 'tester':
        $email = isset($flags['email']) && is_string($flags['email']) ? $flags['email'] : null;
        exit(cmdTester($db, $args[1] ?? null, $args[2] ?? null, $email, $reason));
    default:
        fwrite(STDERR, "Unknown command \"{$command}\".\n\n");
        usage();
        exit(1);
}
