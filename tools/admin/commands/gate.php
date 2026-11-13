<?php
return [
    'name' => 'gate',
    'order' => 60,
    'usage' => "  php tools/admin.php gate status|on|off [--reason=\"...\"]\n"
             . "      Read or set the maintenance lockdown gate.\n",
    'run' => function ($db, array $args, array $flags): int {
        $mode = $args[0] ?? null;
        $reason = adminReason($flags);

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
    },
];
