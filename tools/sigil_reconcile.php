<?php
/**
 * Too Many Coins - Sigil Mirror Reconciliation
 *
 * The companion to tools/sigil_mirror_selfcheck.php. That one is static and
 * proves no code path can drift the mirror from here on. This one is live and
 * finds rows that ALREADY drifted before the fix landed.
 *
 * THE INVARIANT
 *
 *   SUM(season_sigil_holdings.count) per (player, season, tier)
 *       EXCLUDING the Sight family
 *       <=  season_participation.sigils_t{tier}
 *
 * The Sight exclusion is not a detail. Sight is holdings-only: its trickle in
 * TickEngine rides along on another family's drop and adds a mirror row without
 * incrementing any tier column - "holdings-only, outside the positional array
 * and the 25-cap", as the comment there puts it. So a player holding Sight
 * sigils legitimately has more in the mirror than the tier columns show, and at
 * a 33% trickle rate that is most active players, not an edge case.
 *
 * Counting Sight here would therefore report healthy inventory as drift, and
 * --apply would "repair" it by deleting real material sigils. Sight is still
 * shown in the report below, so an operator can see the gap it accounts for
 * rather than having to re-derive this.
 *
 * Less-than is normal and expected: sigils granted before families were enabled
 * were never written to the mirror, so the mirror legitimately lags the tier
 * totals. SigilFamilies::syncSpendTier says as much - "pre-family sigils may be
 * untracked, so this decrements at most what the mirror holds."
 *
 * GREATER-than is the bug. It means the tier column was decremented by a spend
 * that did not touch the mirror, so the mirror still believes the player owns a
 * sigil they have already spent - and the family verbs spend from the mirror.
 * That sigil can be spent a second time on a ward or a market prime.
 *
 * freezePlayerUbi() and selfMeltFreeze() both did exactly that until the fix,
 * so any freeze or melt cast between families being enabled and that deploy
 * left a row over.
 *
 * Dry run by default. Nothing is written without --apply.
 *
 *   php tools/sigil_reconcile.php            # report drift
 *   php tools/sigil_reconcile.php --apply    # trim the mirror back to truth
 *
 * Exit: 0 = no drift (or repaired), 1 = error, 2 = drift found in a dry run.
 */

require_once __DIR__ . '/lib/test_accounts.php';   // for tmcOpenPdo()

$opts = getopt('', ['apply', 'season::']);
$apply = isset($opts['apply']);
$onlySeason = isset($opts['season']) ? (int)$opts['season'] : null;

// Mirrors SigilFamilies::SIGHT_ID. Hardcoded rather than required, because this
// tool deliberately runs on raw PDO without booting the app - it has to work
// against a database whose code it may not match.
const SIGHT_FAMILY_ID = 6;

try {
    $pdo = tmcOpenPdo();
} catch (Throwable $e) {
    fwrite(STDERR, "Cannot connect: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Run this inside web-app or worker-app, where DB_* is configured.\n");
    exit(1);
}

echo "Sigil mirror reconciliation" . ($apply ? " (APPLY)" : " (dry run)") . "\n";
echo str_repeat('-', 72) . "\n";

// If families were never enabled here, the mirror is empty by design and there
// is nothing to reconcile. Say so rather than reporting a clean run that
// examined nothing.
try {
    $hasTable = (int)$pdo->query(
        "SELECT COUNT(*) AS n FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'season_sigil_holdings'"
    )->fetch()['n'];
} catch (Throwable $e) {
    fwrite(STDERR, "Could not inspect schema: " . $e->getMessage() . "\n");
    exit(1);
}
if (!$hasTable) {
    echo "season_sigil_holdings does not exist - families were never installed here.\n";
    echo "Nothing to reconcile.\n";
    exit(0);
}

$mirrorRows = (int)$pdo->query("SELECT COUNT(*) AS n FROM season_sigil_holdings")->fetch()['n'];
if ($mirrorRows === 0) {
    echo "The mirror is empty - families have not granted anything here yet.\n";
    echo "Nothing to reconcile.\n";
    exit(0);
}

// Compare per (player, season, tier). UNION the six tier columns into rows so
// the comparison is one query rather than six.
$tierUnion = [];
for ($t = 1; $t <= 6; $t++) {
    $tierUnion[] = "SELECT player_id, season_id, {$t} AS tier, sigils_t{$t} AS tier_count
                    FROM season_participation";
}
$seasonFilter = $onlySeason !== null ? " WHERE p.season_id = " . (int)$onlySeason : "";

// Filtered with WHERE on a derived table rather than HAVING. HAVING without a
// GROUP BY is accepted by MySQL but is exactly the shape ONLY_FULL_GROUP_BY
// objects to, and this tool has to run against whatever sql_mode the deployment
// happens to have set.
$sql = "
    SELECT * FROM (
        SELECT p.player_id, p.season_id, p.tier, p.tier_count,
               COALESCE(h.mirror_count, 0) AS mirror_count,
               COALESCE(h.sight_count, 0) AS sight_count,
               pl.handle
        FROM ( " . implode("\n UNION ALL\n", $tierUnion) . " ) p
        LEFT JOIN (
            SELECT player_id, season_id, tier,
                   SUM(CASE WHEN family_id <> " . SIGHT_FAMILY_ID . " THEN count ELSE 0 END) AS mirror_count,
                   SUM(CASE WHEN family_id =  " . SIGHT_FAMILY_ID . " THEN count ELSE 0 END) AS sight_count
            FROM season_sigil_holdings GROUP BY player_id, season_id, tier
        ) h ON h.player_id = p.player_id AND h.season_id = p.season_id AND h.tier = p.tier
        JOIN players pl ON pl.player_id = p.player_id
        {$seasonFilter}
    ) cmp
    WHERE cmp.mirror_count > cmp.tier_count
    ORDER BY cmp.season_id, cmp.player_id, cmp.tier
";

try {
    $drift = $pdo->query($sql)->fetchAll();
} catch (Throwable $e) {
    fwrite(STDERR, "Comparison failed: " . $e->getMessage() . "\n");
    exit(1);
}

if (!$drift) {
    echo "No drift. Every (player, season, tier) has mirror <= tier total.\n";
    echo "Scanned {$mirrorRows} mirror row(s).\n";
    exit(0);
}

$totalExcess = 0;
echo "DRIFT - the mirror claims sigils the tier columns say were already spent.\n";
echo "Each excess sigil below is one that could be spent a second time on a\n";
echo "family verb (ward, market prime, transmute).\n\n";
echo "Sight is listed but excluded from the comparison - it is holdings-only and\n";
echo "legitimately has no tier column behind it.\n\n";
printf("  %-14s %-7s %-5s %-8s %-8s %-7s %s\n",
    'handle', 'season', 'tier', 'tier', 'mirror', 'sight', 'excess');
foreach ($drift as $d) {
    $excess = (int)$d['mirror_count'] - (int)$d['tier_count'];
    $totalExcess += $excess;
    printf("  %-14s %-7d %-5d %-8d %-8d %-7d +%d\n",
        substr((string)$d['handle'], 0, 14), $d['season_id'], $d['tier'],
        $d['tier_count'], $d['mirror_count'], $d['sight_count'], $excess);
}
echo "\n";

if (!$apply) {
    echo str_repeat('-', 72) . "\n";
    printf("Dry run. %d row(s), %d excess sigil(s). Re-run with --apply to repair.\n",
        count($drift), $totalExcess);
    exit(2);
}

// Repair direction: the TIER COLUMN is authoritative. Every code path has
// always written it correctly - it is what lock-in pays out, what drop pressure
// reads, and what the guarded spends check. Only the mirror drifted, and only
// upward. So trim the mirror down; never inflate the tier columns to match, or
// a double-spend would be laundered into a real sigil.
//
// Trim largest-holding family first, which is what syncSpendTier does, so the
// repaired distribution matches what the spend would have produced.
$pdo->beginTransaction();
try {
    $repaired = 0;
    foreach ($drift as $d) {
        $excess = (int)$d['mirror_count'] - (int)$d['tier_count'];
        while ($excess > 0) {
            // Sight is excluded here as well as in the comparison above. It is
            // not part of the excess, so trimming it would destroy valid
            // inventory while leaving the actual drift in place - and since
            // Sight is often the largest holding at a tier, an ORDER BY count
            // that included it would pick it first almost every time.
            $st = $pdo->prepare(
                "SELECT family_id, count FROM season_sigil_holdings
                 WHERE season_id = ? AND player_id = ? AND tier = ? AND count > 0
                   AND family_id <> " . SIGHT_FAMILY_ID . "
                 ORDER BY count DESC, family_id ASC LIMIT 1"
            );
            $st->execute([$d['season_id'], $d['player_id'], $d['tier']]);
            $row = $st->fetch();
            if (!$row) { break; }
            $take = min((int)$row['count'], $excess);
            $upd = $pdo->prepare(
                "UPDATE season_sigil_holdings SET count = count - ?
                 WHERE season_id = ? AND player_id = ? AND family_id = ? AND tier = ? AND count >= ?"
            );
            $upd->execute([$take, $d['season_id'], $d['player_id'], $row['family_id'], $d['tier'], $take]);
            if ($upd->rowCount() !== 1) { break; }
            $excess -= $take;
            $repaired += $take;
        }
    }
    $pdo->commit();
    echo str_repeat('-', 72) . "\n";
    printf("Repaired %d excess sigil(s) across %d row(s). Re-run to confirm clean.\n",
        $repaired, count($drift));
    exit(0);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    fwrite(STDERR, "Repair failed, rolled back: " . $e->getMessage() . "\n");
    exit(1);
}
