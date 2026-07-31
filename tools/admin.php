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

  php tools/admin.php gate status|on|off [--reason="..."]
      Read or set the maintenance lockdown gate.

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
                created_at, last_seen_at
         FROM players WHERE handle_lower = LOWER(?)",
        [$handle]
    ) ?: null;
}

/**
 * One word for the account's usable state.
 *
 * "unverified" is the one worth surfacing: a promoted account that never
 * confirmed its email cannot sign in, so an Admin created before SMTP worked
 * looks correct in the role column and is still unusable.
 */
function accountState(array $player): string {
    if (!empty($player['profile_deleted_at'])) return 'deleted';
    if (empty($player['email_verified_at'])) return 'unverified';
    return 'ok';
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
        printf("%d cannot sign in yet: email unverified.\n", $unverified);
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
    case 'gate':
        exit(cmdGate($db, $args[1] ?? null, $reason));
    default:
        fwrite(STDERR, "Unknown command \"{$command}\".\n\n");
        usage();
        exit(1);
}
