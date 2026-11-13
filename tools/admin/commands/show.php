<?php
return [
    'name' => 'show',
    'order' => 20,
    'readOnly' => true,
    'usage' => "  php tools/admin.php show <handle>\n"
             . "      Show one account's role and status.\n",
    'run' => function ($db, array $args, array $flags): int {
        $handle = $args[0] ?? null;
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
    },
];
