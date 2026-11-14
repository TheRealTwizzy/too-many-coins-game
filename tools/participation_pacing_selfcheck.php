<?php
/**
 * Too Many Coins - Participation Pacing Self-Check
 *
 * The active-return flow hands a Tier 1 sigil to a player who comes back
 * after a gap (return pulse) and to an Active player whose economy has gone
 * quiet (dry-spell pulse). Both are gated by season config columns and by
 * per-participation counters, and both must respect the inventory cap.
 *
 * This drives the pure decision functions with hand-built rows, then checks
 * at source level that a granted pulse goes through TickEngine::awardSigilDrop
 * rather than writing sigils_tN itself, because that is the only path that
 * keeps family holdings, progression and the drop log in step.
 *
 * Ported from tests/ParticipationPacingTest.php on the codex branch; main has
 * no PHPUnit harness, so this follows the other tools/*_selfcheck.php files.
 *
 * Usage:  php tools/participation_pacing_selfcheck.php [--verbose]
 * Exit:   0 = pass, 1 = fail
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/economy.php';

// participation_pacing.php requires tick_engine.php, which pulls in the
// database layer. None of that is executed here; it only has to parse.
require_once __DIR__ . '/../includes/participation_pacing.php';

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

function season(array $overrides = []): array {
    return array_merge([
        'season_id' => 1,
        'start_time' => 0,
        'end_time' => 1000,
        'blackout_time' => 900,
        'return_pulse_min_gap_ticks' => 5,
        'return_pulse_cooldown_ticks' => 30,
        'active_dry_spell_ticks' => 5,
        'participation_pulse_reward_tier' => 1,
    ], $overrides);
}

function player(array $overrides = []): array {
    return array_merge([
        'player_id' => 7,
        'activity_state' => 'Active',
        'economic_presence_state' => 'Active',
        'idle_modal_active' => 0,
        'online_current' => 1,
        'idle_since_tick' => 0,
        'last_activity_tick' => 10,
        'joined_season_id' => 1,
    ], $overrides);
}

function participation(array $overrides = []): array {
    $base = [
        'player_id' => 7,
        'season_id' => 1,
        'sigil_drops_total' => 0,
        'last_meaningful_economy_tick' => 0,
        'last_return_pulse_tick' => 0,
        'last_active_pulse_tick' => 0,
        'return_pulses_total' => 0,
        'active_pulses_total' => 0,
    ];
    for ($t = 1; $t <= 6; $t++) {
        $base["sigils_t{$t}"] = 0;
    }
    return array_merge($base, $overrides);
}

echo "Participation pacing self-check\n\n";

echo "Return pulse decisions\n";
$d = ParticipationPacing::returnPulseDecision(season(), player(['idle_since_tick' => 10]), participation(), 16);
check('eligible after an idle gap at least min_gap long', $d['eligible'] && $d['reason_code'] === 'eligible', $d);
check('reward tier is 1', $d['reward_tier'] === 1, $d);

$d = ParticipationPacing::returnPulseDecision(season(), player(['idle_since_tick' => 10]), participation(), 12);
check('blocked when the gap is shorter than min_gap', !$d['eligible'] && $d['reason_code'] === 'return_gap_too_short', $d);

$d = ParticipationPacing::returnPulseDecision(season(), player(), participation(['last_return_pulse_tick' => 15]), 20);
check('cooldown blocks a repeat grant', !$d['eligible'] && $d['reason_code'] === 'return_pulse_cooldown', $d);

$d = ParticipationPacing::returnPulseDecision(season(), player(['idle_since_tick' => 10]), participation(['last_return_pulse_tick' => 15]), 50);
check('cooldown releases once return_pulse_cooldown_ticks have passed', $d['eligible'], $d);

$d = ParticipationPacing::returnPulseDecision(season(), player(['idle_since_tick' => 10]), participation(), 950);
check('blackout blocks a return pulse', !$d['eligible'] && $d['reason_code'] === 'blackout_blocked', $d);

$d = ParticipationPacing::returnPulseDecision(season(), player(['idle_since_tick' => 10, 'activity_state' => 'Idle']), participation(), 16);
check('a non-Active player gets no return pulse', !$d['eligible'] && $d['reason_code'] === 'not_active', $d);

$d = ParticipationPacing::returnPulseDecision(season(), player(['idle_since_tick' => 0, 'last_activity_tick' => 10]), participation(), 16);
check('falls back to last_activity_tick when idle_since_tick is unset', $d['eligible'], $d);

echo "\nActive dry-spell decisions\n";
$d = ParticipationPacing::activePulseDecision(season(), player(), participation(['last_meaningful_economy_tick' => 10]), 16, 'Active');
check('eligible after a quiet active window', $d['eligible'] && $d['reason_code'] === 'eligible', $d);

$d = ParticipationPacing::activePulseDecision(season(), player(), participation(['last_meaningful_economy_tick' => 10]), 13, 'Active');
check('blocked while the window is shorter than active_dry_spell_ticks', !$d['eligible'] && $d['reason_code'] === 'active_gap_too_short', $d);

$d = ParticipationPacing::activePulseDecision(season(), player(), participation(['last_meaningful_economy_tick' => 10, 'last_active_pulse_tick' => 14]), 16, 'Active');
check('a recent active pulse resets the dry-spell reference', !$d['eligible'], $d);

$d = ParticipationPacing::activePulseDecision(season(), player(), participation(['last_meaningful_economy_tick' => 10]), 16, 'Idle');
check('presence state from the tick engine wins over the player row', !$d['eligible'] && $d['reason_code'] === 'not_active', $d);

$capped = participation(['last_meaningful_economy_tick' => 10]);
$cap = (int)Economy::getSigilTotalCap();
$capped['sigils_t1'] = $cap;
$d = ParticipationPacing::activePulseDecision(season(), player(), $capped, 16, 'Active');
check('blocked at the sigil inventory total cap', !$d['eligible'] && $d['reason_code'] === 'sigil_capacity', ['cap' => $cap, 'decision' => $d]);

echo "\nIn-memory application\n";
$p = participation();
$ok = ParticipationPacing::applyPulseToParticipation($p, season(), ParticipationPacing::SOURCE_RETURN, 42);
check('applying a return pulse adds one Tier 1 sigil', $ok && $p['sigils_t1'] === 1, $p);
check('applying a return pulse moves the return counters', $p['last_return_pulse_tick'] === 42 && $p['return_pulses_total'] === 1 && $p['last_meaningful_economy_tick'] === 42, $p);
check('applying a return pulse leaves the active counters alone', $p['last_active_pulse_tick'] === 0 && $p['active_pulses_total'] === 0, $p);

$p = participation();
$ok = ParticipationPacing::applyPulseToParticipation($p, season(), ParticipationPacing::SOURCE_ACTIVE, 43);
check('applying an active pulse moves the active counters', $ok && $p['sigils_t1'] === 1 && $p['last_active_pulse_tick'] === 43 && $p['active_pulses_total'] === 1, $p);

$p = participation();
$ok = ParticipationPacing::applyPulseToParticipation($p, season(), 'rng', 44);
check('an unknown source is refused', !$ok && $p['sigils_t1'] === 0, $p);

$p = participation(['sigils_t1' => $cap]);
$ok = ParticipationPacing::applyPulseToParticipation($p, season(), ParticipationPacing::SOURCE_RETURN, 45);
check('application respects the inventory cap', !$ok && $p['sigils_t1'] === $cap, $p);

echo "\nSeason config surface\n";
check('rewardTier is pinned to 1 regardless of column value', ParticipationPacing::rewardTier(season(['participation_pulse_reward_tier' => 4])) === 1);

$gameTime = file_get_contents(__DIR__ . '/../includes/game_time.php');
check('the season INSERT writes return_pulse_min_gap_ticks', strpos($gameTime, 'return_pulse_min_gap_ticks') !== false);
check('the season INSERT writes return_pulse_cooldown_ticks', strpos($gameTime, 'return_pulse_cooldown_ticks') !== false);
check('the season INSERT writes active_dry_spell_ticks', strpos($gameTime, 'active_dry_spell_ticks') !== false);
check('the season INSERT writes participation_pulse_reward_tier', strpos($gameTime, 'participation_pulse_reward_tier') !== false);

$schema = file_get_contents(__DIR__ . '/../schema.sql');
foreach (['return_pulse_min_gap_ticks', 'return_pulse_cooldown_ticks', 'active_dry_spell_ticks', 'participation_pulse_reward_tier',
          'last_meaningful_economy_tick', 'last_return_pulse_tick', 'last_active_pulse_tick', 'return_pulses_total', 'active_pulses_total'] as $col) {
    check("schema.sql declares {$col}", strpos($schema, $col) !== false);
}

$migration = __DIR__ . '/../migrations/migration_20260429_active_return_gameplay_flow.sql';
check('the migration file is in migrations/', is_file($migration));
$migrationSql = is_file($migration) ? file_get_contents($migration) : '';
check('the migration widens sigil_drop_log.source for pulse sources',
    strpos($migrationSql, "'RETURN_PULSE'") !== false && strpos($migrationSql, "'ACTIVE_DRY_SPELL_PULSE'") !== false);

echo "\nGrant path\n";
$pacing = file_get_contents(__DIR__ . '/../includes/participation_pacing.php');
check('a granted pulse goes through TickEngine::awardSigilDrop', strpos($pacing, 'TickEngine::awardSigilDrop(') !== false);
check('the grant path does not write sigils_tN on its own', !preg_match('/\{\$sigilColumn\}\s*=\s*\{\$sigilColumn\}\s*\+\s*1/', $pacing));
check('the grant path does not insert into sigil_drop_log on its own', strpos($pacing, 'INSERT INTO sigil_drop_log') === false);

$engine = file_get_contents(__DIR__ . '/../includes/tick_engine.php');
check('TickEngine::awardSigilDrop is public', (bool)preg_match('/public static function awardSigilDrop\(/', $engine));
check('the tick loop grants active pulses only to Active players', (bool)preg_match('/\$presenceState === \'Active\'[^\n]*\n\s*ParticipationPacing::grantActivePulseForPlayer/s', $engine));

$auth = file_get_contents(__DIR__ . '/../includes/auth.php');
check('login grants a return pulse only to a player who was offline', strpos($auth, '$wasOffline && $joinedSeasonId > 0') !== false);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
