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
 * GameTime::ensureSeasons (season INSERT) and the rejoin reset in
 * Actions::resetSeasonParticipationForFreshStart name the same columns.
 *
 * This drives those paths against tools/lib/fake_db.php with the columns
 * marked missing (and, separately, with the sigil_drop_log ENUM not yet
 * widened) and requires that nothing throws, no pulse is granted, and the
 * pacing columns are skipped rather than written. It failed on main as of
 * bde1df9 (room task E1); room task E3 made it pass and put it in CI.
 *
 * Usage:  php tools/schema_pending_selfcheck.php [--verbose]
 * Exit:   0 = pass, 1 = fail
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/economy.php';
require_once __DIR__ . '/../includes/participation_pacing.php';
require_once __DIR__ . '/../includes/game_time.php';
require_once __DIR__ . '/../includes/actions.php';
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

const NEW_ENUM = "enum('RNG','PITY','RETURN_PULSE','ACTIVE_DRY_SPELL_PULSE')";
const OLD_ENUM = "enum('RNG','PITY')";

/** A fully migrated DB: every column present, drop-log ENUM widened. */
function migratedDb(string $enum = NEW_ENUM): FakeDb {
    $db = pendingDb();
    $db->missingColumns = [];
    $db->onFetch('/COLUMN_TYPE/', ['COLUMN_TYPE' => $enum]);
    return $db;
}

$pacingWrite = '/last_meaningful_economy_tick|last_return_pulse_tick|last_active_pulse_tick|pulses_total/';

echo "Season join / sigil drop path\n";
$db = pendingDb();
[$threw, $out] = attempt(function () use ($db) {
    ParticipationPacing::markMeaningfulEconomyEvent($db, 7, 1, 50);
});
check('markMeaningfulEconomyEvent does not throw when its column is missing', !$threw, $out);
check('markMeaningfulEconomyEvent writes nothing when its column is missing', $db->statementsMatching('/^\s*UPDATE/') === []);

echo "\nReturn pulse inside a caller's transaction (seasonJoin, idleAck)\n";
$db = pendingDb();
$db->beginTransaction();
[$threw, $out] = attempt(function () use ($db) {
    return ParticipationPacing::grantReturnPulseForPlayer($db, 7, 1, 50);
});
check('grantReturnPulseForPlayer does not throw', !$threw, $out);
check('no return pulse is granted while the schema is pending', !$threw && empty($out['granted']), $out);
check('the refusal says schema_pending', !$threw && ($out['reason_code'] ?? null) === 'schema_pending', $out);
check('the refusal writes nothing', $db->statementsMatching('/^\s*(UPDATE|INSERT)/') === []);
check('the caller transaction is left open for the caller to commit', $db->getConnection()->inTransaction());

echo "\nActive pulse inside the tick transaction\n";
$db = pendingDb();
$db->beginTransaction();
[$threw, $out] = attempt(function () use ($db) {
    return ParticipationPacing::grantActivePulseForPlayer($db, 7, 1, 50, 'Active');
});
check('grantActivePulseForPlayer does not throw', !$threw, $out);
check('no active pulse is granted while the schema is pending', !$threw && empty($out['granted']), $out);
check('the refusal says schema_pending', !$threw && ($out['reason_code'] ?? null) === 'schema_pending', $out);

echo "\nHalf-applied migration: columns present, drop-log ENUM not widened\n";
$db = migratedDb(OLD_ENUM);
$db->beginTransaction();
[$threw, $out] = attempt(function () use ($db) {
    return ParticipationPacing::grantActivePulseForPlayer($db, 7, 1, 50, 'Active');
});
check('a pulse is refused while sigil_drop_log.source cannot hold it', !$threw && ($out['reason_code'] ?? null) === 'schema_pending', $out);
check('pulse readiness is false on the old ENUM', ParticipationPacing::pulseSchemaReady(migratedDb(OLD_ENUM)) === false);
check('pulse readiness is true once fully migrated', ParticipationPacing::pulseSchemaReady(migratedDb()) === true);
check('pulse readiness is false with the columns missing', ParticipationPacing::pulseSchemaReady(pendingDb()) === false);

echo "\nSeason INSERT (GameTime::ensureSeasons)\n";
[$threw, $out] = attempt(function () {
    return GameTime::pacingSeasonInsertColumns(pendingDb());
});
check('no pacing columns are inserted while they are missing', !$threw && $out === [], $out);
[$threw, $out] = attempt(function () {
    return GameTime::pacingSeasonInsertColumns(migratedDb());
});
check('all four pacing columns are inserted once they exist', !$threw && is_array($out)
    && array_keys($out) === ['return_pulse_min_gap_ticks', 'return_pulse_cooldown_ticks', 'active_dry_spell_ticks', 'participation_pulse_reward_tier'], $out);
check('the reward tier inserted is 1', !$threw && ($out['participation_pulse_reward_tier'] ?? null) === 1, $out);
$gameTimeSrc = (string)file_get_contents(__DIR__ . '/../includes/game_time.php');
check('the INSERT literal no longer names the pacing columns', !preg_match('/INSERT INTO seasons[^"]*return_pulse_min_gap_ticks/s', $gameTimeSrc));
check('ensureSeasons uses the guarded column helper', strpos($gameTimeSrc, 'self::pacingSeasonInsertColumns($db)') !== false);

echo "\nRejoin reset (Actions::resetSeasonParticipationForFreshStart)\n";
$reset = new ReflectionMethod('Actions', 'resetSeasonParticipationForFreshStart');
$db = pendingDb();
[$threw, $out] = attempt(function () use ($reset, $db) {
    $reset->invoke(null, $db, 7, 1, 50);
});
check('the reset does not throw while the pacing columns are missing', !$threw, $out);
check('the reset names no pacing column while they are missing', $db->statementsMatching($pacingWrite) === []);
$db = migratedDb();
[$threw, $out] = attempt(function () use ($reset, $db) {
    $reset->invoke(null, $db, 7, 1, 50);
});
check('the reset still resets the pacing columns once they exist', !$threw
    && count($db->statementsMatching('/last_meaningful_economy_tick\s*=\s*\?.*last_active_pulse_tick\s*=\s*0/s')) === 1, $out);

echo "\nMigrated schema still writes\n";
$db = migratedDb();
[$threw, $out] = attempt(function () use ($db) {
    ParticipationPacing::markMeaningfulEconomyEvent($db, 7, 1, 50);
});
check('markMeaningfulEconomyEvent writes the column once it exists',
    !$threw && count($db->statementsMatching('/last_meaningful_economy_tick/')) === 1, $out);
$db = migratedDb(OLD_ENUM);
[$threw, $out] = attempt(function () use ($db) {
    ParticipationPacing::markMeaningfulEconomyEvent($db, 7, 1, 50);
});
check('markMeaningfulEconomyEvent does not wait on the ENUM widen',
    !$threw && count($db->statementsMatching('/UPDATE season_participation SET last_meaningful_economy_tick/')) === 1, $out);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
