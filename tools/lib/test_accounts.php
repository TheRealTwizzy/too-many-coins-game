<?php
/**
 * Too Many Coins - throwaway test account identification and purge.
 *
 * tools/concurrency_selfcheck.php registers a real account and joins a real
 * season every time it runs, and used to leave it there forever. Three of them
 * accumulated in season 1 before anyone noticed: they sit in the leaderboard,
 * inflate participant counts, and count toward the season supply telemetry that
 * star price model v2 reads.
 *
 * Shared by tools/purge_test_accounts.php and the self-check's --cleanup.
 */

/**
 * SQL matching ONLY accounts the self-check created.
 *
 * Both halves are required, and either alone would be too loose. The handle is
 * 'cc_' + exactly six lowercase hex characters, and the address lives under
 * .invalid - a TLD RFC 2606 reserves precisely so it can never resolve, so no
 * real signup can hold one. A human picking the handle 'cc_abc123' is not
 * matched unless they also have an impossible email.
 *
 * @return array{0:string,1:array<int,string>} [sql fragment, bind params]
 */
function tmcTestAccountPredicate(?string $onlyHandle = null): array {
    $sql = "handle REGEXP '^cc_[0-9a-f]{6}$' AND email LIKE '%@example.invalid'";
    $params = [];
    if ($onlyHandle !== null && $onlyHandle !== '') {
        $sql .= " AND handle = ?";
        $params[] = $onlyHandle;
    }
    return [$sql, $params];
}

/** Player rows matching the predicate, newest first. */
function tmcFindTestAccounts(PDO $pdo, ?string $onlyHandle = null): array {
    [$where, $params] = tmcTestAccountPredicate($onlyHandle);
    $st = $pdo->prepare(
        "SELECT player_id, handle, email, created_at, global_stars
         FROM players WHERE {$where} ORDER BY player_id DESC"
    );
    $st->execute($params);
    return $st->fetchAll();
}

/**
 * Every column in the database that points at players.player_id.
 *
 * Discovered at runtime rather than hardcoded, as the union of two sources,
 * because NEITHER is sufficient alone:
 *
 *  - Foreign keys are authoritative but incomplete. handle_registry.player_id,
 *    economy_ledger.player_id and chat_messages.recipient_id all reference a
 *    player with no constraint declared, so an FK-only sweep silently leaves
 *    orphans - including the handle_registry row that would block re-using the
 *    handle.
 *  - Name matching catches those three, but would miss any future column with
 *    an unconventional name that does declare an FK.
 *
 * Runtime discovery also means a table added later is handled without editing
 * this file, which matters because the failure mode of missing one is a silent
 * orphan rather than an error.
 *
 * @return array<int,array{table:string,column:string,via:string}>
 */
function tmcPlayerReferenceColumns(PDO $pdo): array {
    $found = [];

    $fk = $pdo->query(
        "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE()
           AND REFERENCED_TABLE_NAME = 'players'
           AND REFERENCED_COLUMN_NAME = 'player_id'"
    )->fetchAll();
    foreach ($fk as $r) {
        $found[$r['TABLE_NAME'] . '.' . $r['COLUMN_NAME']] =
            ['table' => $r['TABLE_NAME'], 'column' => $r['COLUMN_NAME'], 'via' => 'fk'];
    }

    // Columns named like a player reference, whether or not an FK exists.
    // Base tables only - information_schema.COLUMNS also lists views, and a
    // DELETE against one would abort the transaction.
    $named = $pdo->query(
        "SELECT c.TABLE_NAME, c.COLUMN_NAME FROM information_schema.COLUMNS c
         JOIN information_schema.TABLES t
           ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
         WHERE c.TABLE_SCHEMA = DATABASE()
           AND t.TABLE_TYPE = 'BASE TABLE'
           AND c.TABLE_NAME <> 'players'
           AND (c.COLUMN_NAME = 'player_id'
                OR c.COLUMN_NAME LIKE '%\_player_id'
                OR c.COLUMN_NAME IN ('player_a','player_b','from_player','to_player',
                                     'sender_id','recipient_id','blocker_id','blocked_id',
                                     'revoked_by','opened_by','closed_by'))"
    )->fetchAll();
    foreach ($named as $r) {
        $key = $r['TABLE_NAME'] . '.' . $r['COLUMN_NAME'];
        if (!isset($found[$key])) {
            $found[$key] = ['table' => $r['TABLE_NAME'], 'column' => $r['COLUMN_NAME'], 'via' => 'name'];
        }
    }

    ksort($found);
    return array_values($found);
}

/**
 * Coins these players hold, per season - the amount the season's running supply
 * total needs decrementing by when they are removed.
 *
 * Necessary because seasons carries two KINDS of telemetry and only one of them
 * repairs itself (tick_engine.php:406-412). coins_active_total, coins_idle_total
 * and effective_price_supply are rewritten to absolute values every tick, so
 * they correct within 5 seconds of a delete. total_coins_supply and
 * total_coins_supply_end_of_tick are relative accumulators - "+= this tick's
 * mint" - so deleting a participant silently leaves their coins in the running
 * total forever. Both are read by live game logic: the inflation dampener
 * (economy.php:745) and the star price supply fallback (economy.php:940).
 *
 * @param int[] $playerIds
 * @return array<int,array{season_id:int,coins:int}>
 */
function tmcSeasonSupplyDeltas(PDO $pdo, array $playerIds): array {
    $in = implode(',', array_fill(0, count($playerIds), '?'));
    $st = $pdo->prepare(
        "SELECT season_id, SUM(coins) AS coins FROM season_participation
         WHERE player_id IN ({$in}) GROUP BY season_id HAVING SUM(coins) <> 0"
    );
    $st->execute($playerIds);
    return array_map(
        fn($r) => ['season_id' => (int)$r['season_id'], 'coins' => (int)$r['coins']],
        $st->fetchAll()
    );
}

/**
 * Count or delete every row belonging to the given players, and correct the
 * season supply totals they contributed to.
 *
 * Children are cleared before the players themselves so foreign keys are never
 * violated mid-transaction, and the whole sweep is one transaction: a partial
 * purge would leave exactly the orphan rows this is meant to prevent.
 *
 * @param int[] $playerIds
 * @return array{rows:array<string,int>,total:int,players:int,supply:array}
 */
function tmcPurgePlayers(PDO $pdo, array $playerIds, bool $apply): array {
    $playerIds = array_values(array_unique(array_map('intval', $playerIds)));
    if (!$playerIds) {
        return ['rows' => [], 'total' => 0, 'players' => 0, 'supply' => []];
    }
    $in = implode(',', array_fill(0, count($playerIds), '?'));
    $refs = tmcPlayerReferenceColumns($pdo);

    $rows = [];
    $total = 0;
    if ($apply) { $pdo->beginTransaction(); }
    try {
        // Read the supply contribution BEFORE the participation rows go.
        $supply = tmcSeasonSupplyDeltas($pdo, $playerIds);

        foreach ($refs as $ref) {
            $t = $ref['table'];
            $c = $ref['column'];
            $st = $pdo->prepare("SELECT COUNT(*) AS n FROM `{$t}` WHERE `{$c}` IN ({$in})");
            $st->execute($playerIds);
            $n = (int)($st->fetch()['n'] ?? 0);
            if ($n === 0) { continue; }
            if ($apply) {
                $del = $pdo->prepare("DELETE FROM `{$t}` WHERE `{$c}` IN ({$in})");
                $del->execute($playerIds);
                $n = $del->rowCount();
            }
            $rows["{$t}.{$c}"] = $n;
            $total += $n;
        }

        $playersHit = count($playerIds);
        if ($apply) {
            $del = $pdo->prepare("DELETE FROM players WHERE player_id IN ({$in})");
            $del->execute($playerIds);
            $playersHit = $del->rowCount();

            // Relative, matching how the tick engine mutates these columns, so
            // the correction composes with a tick landing concurrently instead
            // of clobbering it. GREATEST(0, ...) mirrors the engine's own floor.
            $fix = $pdo->prepare(
                "UPDATE seasons SET
                   total_coins_supply = GREATEST(0, total_coins_supply - ?),
                   total_coins_supply_end_of_tick = GREATEST(0, total_coins_supply_end_of_tick - ?)
                 WHERE season_id = ?"
            );
            foreach ($supply as $s) {
                $fix->execute([$s['coins'], $s['coins'], $s['season_id']]);
            }
            $pdo->commit();
        }
        return ['rows' => $rows, 'total' => $total, 'players' => $playersHit, 'supply' => $supply];
    } catch (Throwable $e) {
        if ($apply && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}

/**
 * Open a connection using the app's own DB_* settings.
 *
 * Deliberately not Database::getInstance(): that constructor calls
 * applyPendingMigrations(), so a cleanup pass would apply migrations as a side
 * effect wherever TMC_AUTO_SQL_MIGRATIONS is on.
 */
function tmcOpenPdo(): PDO {
    require_once __DIR__ . '/../../includes/config.php';
    return new PDO(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}
