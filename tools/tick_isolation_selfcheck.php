<?php
/**
 * Too Many Coins - Tick Isolation Self-Check (room task E4)
 *
 * The season tick runs every participant in ONE transaction. Before E4 any
 * throw from an optional side effect inside it - the active-pulse grant, or
 * the pacing bookkeeping at the end of awardSigilDrop - rolled back UBI,
 * participation and last_processed_tick for every player in the batch (see
 * docs/economy-bugs-rootcause.md).
 *
 * This proves, DB-free, against tools/lib/fake_db.php:
 *   - TickEngine::runOptionalSideEffect contains a failure under a SAVEPOINT,
 *     keeps the caller's transaction open, and later core writes still commit
 *   - a lost savepoint (deadlock) rethrows so the caller still rolls back
 *   - nested optional effects get distinct savepoints
 *   - pulse sources are refused before any write when the sigil_drop_log
 *     ENUM is not widened yet; RNG and PITY are always accepted
 *
 * Usage:  php tools/tick_isolation_selfcheck.php [--verbose]
 * Exit:   0 = pass, 1 = fail
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/economy.php';
// participation_pacing.php requires tick_engine.php.
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

/** Keep expected error_log noise out of the check output. */
ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');

/** Run $fn; return [threw, result|message]. */
function attempt(callable $fn): array {
    try {
        return [false, $fn()];
    } catch (Throwable $e) {
        return [true, get_class($e) . ': ' . $e->getMessage()];
    }
}

/** Savepoint statements only, with the sequence number stripped. */
function savepointShape(array $execs): array {
    return array_map(static function ($sql) {
        return preg_replace('/tmc_opt_\d+/', 'SP', $sql);
    }, $execs);
}

const UBI_SQL = "UPDATE season_participation SET coins = GREATEST(0, coins + ?), coins_fractional_fp = ?, hoarding_sink_total = hoarding_sink_total + ? WHERE player_id = ? AND season_id = ?";

echo "Tick isolation self-check\n\n";

echo "A throwing pulse inside the season transaction\n";
// Mirrors processSeasonTick: begin, optional pulse for an Active player, then
// the core UBI write, then commit. The pulse is the real pacing code hitting
// a column the pending migration has not added.
$db = new FakeDb();
$db->missingColumns = ['last_meaningful_economy_tick'];
[$threw, $out] = attempt(function () use ($db) {
    $db->beginTransaction();
    try {
        TickEngine::runOptionalSideEffect($db, 'active_pulse test', function () use ($db) {
            ParticipationPacing::markMeaningfulEconomyEvent($db, 7, 1, 50);
        });
        $db->query(UBI_SQL, [5, 0, 0, 7, 1]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
});
check('the failed side effect does not escape', !$threw, $out);
check('the season batch commits', $db->commits === 1 && $db->rollbacks === 0, ['commits' => $db->commits, 'rollbacks' => $db->rollbacks]);
check('the UBI write after it is still issued', count($db->statementsMatching('/SET coins = GREATEST/')) === 1);
check('only the side effect is undone, via its savepoint',
    savepointShape($db->getConnection()->execs) === ['SAVEPOINT SP', 'ROLLBACK TO SAVEPOINT SP', 'RELEASE SAVEPOINT SP'],
    $db->getConnection()->execs);

echo "\nA side effect that succeeds\n";
$db = new FakeDb();
$db->beginTransaction();
[$threw, $out] = attempt(function () use ($db) {
    return TickEngine::runOptionalSideEffect($db, 'ok', function () use ($db) {
        $db->query('UPDATE season_participation SET last_active_pulse_tick = 1');
        return ['granted' => true];
    });
});
check('returns the callback result', !$threw && ($out['granted'] ?? false) === true, $out);
check('savepoint is released, not rolled back',
    savepointShape($db->getConnection()->execs) === ['SAVEPOINT SP', 'RELEASE SAVEPOINT SP'],
    $db->getConnection()->execs);
check('the caller transaction stays open', $db->getConnection()->inTransaction());

echo "\nNested optional side effects (pulse -> awardSigilDrop -> pacing mark)\n";
$db = new FakeDb();
$db->beginTransaction();
[$threw, $out] = attempt(function () use ($db) {
    return TickEngine::runOptionalSideEffect($db, 'outer', function () use ($db) {
        TickEngine::runOptionalSideEffect($db, 'inner', function () {
            throw new RuntimeException('inner failure');
        });
        return 'outer done';
    });
});
$execs = $db->getConnection()->execs;
preg_match_all('/tmc_opt_\d+/', implode(' ', $execs), $names);
check('outer survives an inner failure', !$threw && $out === 'outer done', $out);
check('inner and outer use different savepoints', count(array_unique($names[0])) === 2, $execs);

echo "\nLost savepoint (deadlock already rolled the transaction back)\n";
$db = new FakeDb();
$db->beginTransaction();
$db->getConnection()->failExecMatching = '/^ROLLBACK TO SAVEPOINT/';
[$threw, $out] = attempt(function () use ($db) {
    return TickEngine::runOptionalSideEffect($db, 'deadlock', function () {
        throw new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found');
    });
});
check('the original error is rethrown so the caller rolls back', $threw && strpos((string)$out, 'Deadlock') !== false, $out);

echo "\nOutside a transaction\n";
$db = new FakeDb();
[$threw, $out] = attempt(function () use ($db) {
    return TickEngine::runOptionalSideEffect($db, 'no-tx', function () {
        throw new RuntimeException('boom');
    });
});
check('a failure is swallowed and returns null', !$threw && $out === null, $out);
check('no savepoint is used', $db->getConnection()->execs === [], $db->getConnection()->execs);

echo "\nsigil_drop_log.source ENUM parsing\n";
check('pre-migration ENUM', TickEngine::parseEnumValues("enum('RNG','PITY')") === ['rng', 'pity']);
check('widened ENUM', TickEngine::parseEnumValues("ENUM('RNG', 'PITY', 'RETURN_PULSE', 'ACTIVE_DRY_SPELL_PULSE')") === ['rng', 'pity', 'return_pulse', 'active_dry_spell_pulse']);
check('quoted quote', TickEngine::parseEnumValues("enum('a''b')") === ["a'b"]);
check('non-ENUM reads as unknown', TickEngine::parseEnumValues('varchar(32)') === null);

echo "\nPulse sources vs. the live ENUM\n";
$narrow = new FakeDb();
$narrow->onFetch('/information_schema/', ['COLUMN_TYPE' => "enum('RNG','PITY')"]);
TickEngine::resetSchemaCache();
check('narrow ENUM refuses return_pulse', TickEngine::sigilDropLogAcceptsSource($narrow, 'return_pulse') === false);
check('narrow ENUM refuses active_dry_spell_pulse', TickEngine::sigilDropLogAcceptsSource($narrow, 'ACTIVE_DRY_SPELL_PULSE') === false);
check('RNG always accepted, no schema lookup needed', TickEngine::sigilDropLogAcceptsSource(new FakeDb(), 'rng') === true);
check('PITY always accepted', TickEngine::sigilDropLogAcceptsSource(new FakeDb(), 'pity') === true);

$wide = new FakeDb();
$wide->onFetch('/information_schema/', ['COLUMN_TYPE' => "enum('RNG','PITY','RETURN_PULSE','ACTIVE_DRY_SPELL_PULSE')"]);
TickEngine::resetSchemaCache();
check('widened ENUM accepts return_pulse', TickEngine::sigilDropLogAcceptsSource($wide, 'return_pulse') === true);
check('the ENUM is cached between calls',
    TickEngine::sigilDropLogAcceptsSource($wide, 'active_dry_spell_pulse') === true
    && count($wide->statementsMatching('/information_schema/')) === 1,
    count($wide->statementsMatching('/information_schema/')));

$unknown = new FakeDb();
TickEngine::resetSchemaCache();
check('unreadable column type allows the drop', TickEngine::sigilDropLogAcceptsSource($unknown, 'return_pulse') === true);
TickEngine::resetSchemaCache();

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
