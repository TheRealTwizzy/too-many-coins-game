<?php
return [
    'name' => 'promote',
    'order' => 30,
    'usage' => "  php tools/admin.php promote <handle> [Admin|Moderator] [--reason=\"...\"]\n"
             . "      Grant staff powers. Defaults to Admin.\n",
    'run' => function ($db, array $args, array $flags): int {
        $handle = $args[0] ?? null;
        $role = $args[1] ?? null;
        $reason = adminReason($flags);

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
    },
];
