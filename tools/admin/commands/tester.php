<?php
return [
    'name' => 'tester',
    'order' => 70,
    'usage' => "  php tools/admin.php tester list\n"
             . "      Accounts allowed through the gate without staff powers.\n"
             . "\n"
             . "  php tools/admin.php tester grant|revoke <handle> [--reason=\"...\"]\n"
             . "      Allow or stop allowing an existing account through the gate.\n"
             . "\n"
             . "  php tools/admin.php tester create <handle> [--email=...] [--reason=\"...\"]\n"
             . "      Make a throwaway play-test account: pre-verified, gate-allowed,\n"
             . "      role Player. Prints a generated password once.\n",
    'run' => function ($db, array $args, array $flags): int {
        $sub = $args[0] ?? null;
        $handle = $args[1] ?? null;
        $email = isset($flags['email']) && is_string($flags['email']) ? $flags['email'] : null;
        $reason = adminReason($flags);

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
    },
];
