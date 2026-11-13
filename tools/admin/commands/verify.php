<?php
return [
    'name' => 'verify',
    'order' => 50,
    'usage' => "  php tools/admin.php verify <handle> [--reason=\"...\"]\n"
             . "      Mark an account's email address as confirmed. There is no\n"
             . "      self-service verification flow yet, so this is the only way\n"
             . "      an account gets a confirmed address.\n",
    'run' => function ($db, array $args, array $flags): int {
        $handle = $args[0] ?? null;
        $reason = adminReason($flags);

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
    },
];
