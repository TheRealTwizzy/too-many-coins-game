<?php
return [
    'name' => 'staff',
    'order' => 10,
    'readOnly' => true,
    'usage' => "  php tools/admin.php staff\n"
             . "      List every account that is not a plain Player.\n",
    'run' => function ($db, array $args, array $flags): int {
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
    },
];
