<?php
/**
 * Too Many Coins - Schema-Pending Self-Check
 *
 * Production runs with TMC_AUTO_SQL_MIGRATIONS=false, so code can be live
 * before its migration is applied. #90 (active-return pacing) writes
 * season_participation columns added by
 * migrations/migration_20260429_active_return_gameplay_flow.sql from three
 * hot paths:
 *
 *   - Actions::seasonJoin -> ParticipationPacing::markMeaningfulEconomyEvent
 *   - TickEngine::awardSigilDrop -> markMeaningfulEconomyEvent (every drop)
 *   - TickEngine tick loop -> ParticipationPacing::grantActivePulseForPlayer
 *
 * With the migration pending, each of those throws "Unknown column", which
 * fails every season join and rolls back every tick batch that has a
 * participant (no UBI, no sigils, no progress). See
 * docs/economy-bugs-rootcause.md.
 *
 * This drives ParticipationPacing against tools/lib/fake_db.php with the new
 * columns marked missing and requires that nothing throws and no pulse is
 * granted. It FAILS on main as of bde1df9; the E3 fix task makes it pass and
 * adds it to .github/workflows/selfcheck.yml.
 *
 * Usage:  php tools/schema_pending_selfcheck.php [--verbose]
 * Exit:   0 = pass, 1 = fail
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/economy.php';
require_once __DIR__ . '/../includes/participation_pacing.php';
require_once __DIR__ . '/lib/fake_db.php';

$verbose = in_array('--verbose', $argv, true);
$pass = 0;
$fail = 0;

function check(string $name, bool $ok, $detail = null): void {
    global $pass, $fail, $verbose;
    if ($ok) {
        $pass++;
        if ($verbose) {
            echo "  pass  {$name}\n";
        }
        return;
    }
    $fail++;
    echo "  FAIL  {$name}\n";
    if ($detail !== null) {
        echo "        " . json_encode($detail) . "\n";
    }
}

/** Columns added by migration_20260429_active_return_gameplay_flow.sql. */
const PENDING_COLUMNS = [
    'return_pulse_min_gap_ticks',
    'return_pulse_cooldown_ticks',
    'active_dry_spell_ticks',
    'participation_pulse_reward_tier',
    'last_meaningful_economy_tick',
    'last_return_pulse_tick',
    'last_active_pulse_tick',
    'return_pulses_total',
    'active_pulses_total',
];

/** A DB where the active-return migration has not run. */
function pendingDb(): FakeDb {
    $db = new FakeDb();
    $db->missingColumns = PENDING_COLUMNS;
    // Rows as SELECT * returns them on an unmigrated schema: no pacing columns.
    $db->onFetch('/FROM seasons/', [
        'season_id' => 1, 'start_time' => 0, 'end_time' => 1000, 'blackout_time' => 900,
    ]);
    $db->onFetch('/FROM players/', [
        'player_id' => 7, 'activity_state' => 'Active', 'economic_presence_state' => 'Active',
        'idle_modal_active' => 0, 'online_current' => 1, 'idle_since_tick' => 10,
        'last_activity_tick' => 10, 'joined_season_id' => 1,
    ]);
    $db->onFetch('/FROM season_participation/', [
        'player_id' => 7, 'season_id' => 1, 'sigils_t1' => 0, 'sigils_t2' => 0, 'sigils_t3' => 0,
        'sigils_t4' => 0, 'sigils_t5' => 0, 'sigils_t6' => 0, 'sigil_drops_total' => 0,
    ]);
    return $db;
}

/** Run $fn; return [threw, result|message]. */
function attempt(callable $fn): array {
    try {
        return [false, $fn()];
    } catch (Throwable $e) {
        return [true, get_class($e) . ': ' . $e->getMessage()];
    }
}

echo "Schema-pending self-check (active-return migration not applied)\n\n";

echo "Season join / sigil drop path\n";
$db = pendingDb();
[$threw, $out] = attempt(function () use ($db) {
    ParticipationPacing::markMeaningfulEconomyEvent($db, 7, 1, 50);
});
check('markMeaningfulEconomyEvent does not throw when its column is missing', !$threw, $out);

echo "\nReturn pulse inside a caller's transaction (seasonJoin, idleAck)\n";
$db = pendingDb();
$db->beginTransaction();
[$threw, $out] = attempt(function () use ($db) {
    return ParticipationPacing::grantReturnPulseForPlayer($db, 7, 1, 50);
});
check('grantReturnPulseForPlayer does not throw', !$threw, $out);
check('no return pulse is granted while the schema is pending', !$threw && empty($out['granted']), $out);
check('the caller transaction is left open for the caller to commit', $db->getConnection()->inTransaction());

echo "\nActive pulse inside the tick transaction\n";
$db = pendingDb();
$db->beginTransaction();
[$threw, $out] = attempt(function () use ($db) {
    return ParticipationPacing::grantActivePulseForPlayer($db, 7, 1, 50, 'Active');
});
check('grantActivePulseForPlayer does not throw', !$threw, $out);
check('no active pulse is granted while the schema is pending', !$threw && empty($out['granted']), $out);

echo "\nMigrated schema still writes\n";
$db = new FakeDb();
[$threw, $out] = attempt(function () use ($db) {
    ParticipationPacing::markMeaningfulEconomyEvent($db, 7, 1, 50);
});
check('markMeaningfulEconomyEvent writes the column once it exists',
    !$threw && count($db->statementsMatching('/last_meaningful_economy_tick/')) === 1, $out);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
