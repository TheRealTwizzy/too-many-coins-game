<?php
return [
    'name' => 'demote',
    'order' => 40,
    'usage' => "  php tools/admin.php demote <handle> [--reason=\"...\"] [--force]\n"
             . "      Return an account to Player. Refuses to remove the last Admin\n"
             . "      unless --force is given.\n",
    'run' => function ($db, array $args, array $flags): int {
        $handle = $args[0] ?? null;
        $reason = adminReason($flags);
        $force = isset($flags['force']);

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
    },
];
