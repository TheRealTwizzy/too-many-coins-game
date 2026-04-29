<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/economy.php';
require_once __DIR__ . '/../includes/participation_pacing.php';

class ParticipationPacingTest extends TestCase
{
    public function testReturnPulseEligibleAfterIdleGap(): void
    {
        $decision = ParticipationPacing::returnPulseDecision(
            $this->season(),
            $this->player(['idle_since_tick' => 10]),
            $this->participation(),
            16
        );

        $this->assertTrue($decision['eligible']);
        $this->assertSame('eligible', $decision['reason_code']);
        $this->assertSame(1, $decision['reward_tier']);
    }

    public function testReturnPulseCooldownBlocksRepeatGrant(): void
    {
        $decision = ParticipationPacing::returnPulseDecision(
            $this->season(),
            $this->player(),
            $this->participation(['last_return_pulse_tick' => 15]),
            20
        );

        $this->assertFalse($decision['eligible']);
        $this->assertSame('return_pulse_cooldown', $decision['reason_code']);
    }

    public function testActiveDrySpellEligibleAfterQuietActiveWindow(): void
    {
        $decision = ParticipationPacing::activePulseDecision(
            $this->season(),
            $this->player(['activity_state' => 'Active']),
            $this->participation(['last_meaningful_economy_tick' => 20]),
            25
        );

        $this->assertTrue($decision['eligible']);
        $this->assertSame('eligible', $decision['reason_code']);
        $this->assertSame(1, $decision['reward_tier']);
    }

    public function testActivePulseBlocksAtSigilCapacity(): void
    {
        $decision = ParticipationPacing::activePulseDecision(
            $this->season(),
            $this->player(['activity_state' => 'Active']),
            $this->participation([
                'sigils_t1' => 25,
                'last_meaningful_economy_tick' => 20,
            ]),
            25
        );

        $this->assertFalse($decision['eligible']);
        $this->assertSame('sigil_capacity', $decision['reason_code']);
    }

    public function testApplyingReturnPulseMutatesParticipationCounters(): void
    {
        $participation = $this->participation();

        $applied = ParticipationPacing::applyPulseToParticipation(
            $participation,
            $this->season(),
            ParticipationPacing::SOURCE_RETURN,
            16
        );

        $this->assertTrue($applied);
        $this->assertSame(1, $participation['sigils_t1']);
        $this->assertSame(1, $participation['sigil_drops_total']);
        $this->assertSame(16, $participation['last_meaningful_economy_tick']);
        $this->assertSame(16, $participation['last_return_pulse_tick']);
        $this->assertSame(1, $participation['return_pulses_total']);
    }

    public function testGrantReturnPulseUsesProvidedPlayerSnapshotForIdleGap(): void
    {
        $db = new ParticipationPacingFakeDb(
            $this->season(),
            $this->player(['idle_since_tick' => null, 'last_activity_tick' => 16]),
            $this->participation()
        );

        $result = ParticipationPacing::grantReturnPulseForPlayer(
            $db,
            10,
            1,
            16,
            $this->player(['idle_since_tick' => 10, 'last_activity_tick' => 10])
        );

        $this->assertSame(['granted' => true, 'reason_code' => 'eligible', 'tier' => 1], $result);
        $this->assertSame(1, $db->participation['sigils_t1']);
        $this->assertSame(16, $db->participation['last_return_pulse_tick']);
        $this->assertSame('return_pulse', $db->dropLog[0]['source']);
    }

    public function testGrantActivePulsePersistsActivePulseCounters(): void
    {
        $db = new ParticipationPacingFakeDb(
            $this->season(),
            $this->player(['activity_state' => 'Active']),
            $this->participation(['last_meaningful_economy_tick' => 20])
        );

        $result = ParticipationPacing::grantActivePulseForPlayer($db, 10, 1, 25, 'Active');

        $this->assertSame(['granted' => true, 'reason_code' => 'eligible', 'tier' => 1], $result);
        $this->assertSame(1, $db->participation['sigils_t1']);
        $this->assertSame(25, $db->participation['last_active_pulse_tick']);
        $this->assertSame(1, $db->participation['active_pulses_total']);
        $this->assertSame('active_dry_spell_pulse', $db->dropLog[0]['source']);
    }

    private function season(array $overrides = []): array
    {
        return array_merge([
            'return_pulse_min_gap_ticks' => 5,
            'return_pulse_cooldown_ticks' => 30,
            'active_dry_spell_ticks' => 5,
            'participation_pulse_reward_tier' => 1,
            'blackout_time' => 1000,
            'end_time' => 1200,
        ], $overrides);
    }

    private function player(array $overrides = []): array
    {
        return array_merge([
            'player_id' => 10,
            'joined_season_id' => 1,
            'participation_enabled' => 1,
            'activity_state' => 'Active',
            'idle_modal_active' => 0,
            'idle_since_tick' => 10,
            'last_activity_tick' => 10,
            'online_current' => 1,
        ], $overrides);
    }

    private function participation(array $overrides = []): array
    {
        return array_merge([
            'player_id' => 10,
            'season_id' => 1,
            'sigils_t1' => 0,
            'sigils_t2' => 0,
            'sigils_t3' => 0,
            'sigils_t4' => 0,
            'sigils_t5' => 0,
            'sigils_t6' => 0,
            'sigil_drops_total' => 0,
            'last_meaningful_economy_tick' => 10,
            'last_return_pulse_tick' => 0,
            'last_active_pulse_tick' => 0,
            'return_pulses_total' => 0,
            'active_pulses_total' => 0,
        ], $overrides);
    }
}

class ParticipationPacingFakeDb
{
    public array $season;
    public array $player;
    public array $participation;
    public array $dropLog = [];

    public function __construct(array $season, array $player, array $participation)
    {
        $this->season = array_merge(['season_id' => 1], $season);
        $this->player = $player;
        $this->participation = $participation;
    }

    public function fetch(string $sql, array $params = []): ?array
    {
        if (strpos($sql, 'FROM seasons') !== false) {
            return $this->season;
        }
        if (strpos($sql, 'FROM players') !== false) {
            return $this->player;
        }
        if (strpos($sql, 'FROM season_participation') !== false) {
            return $this->participation;
        }
        return null;
    }

    public function query(string $sql, array $params = []): void
    {
        if (strpos($sql, 'UPDATE season_participation SET last_meaningful_economy_tick = ?') !== false) {
            $this->participation['last_meaningful_economy_tick'] = (int)$params[0];
            return;
        }

        if (preg_match('/UPDATE season_participation SET\s+(sigils_t\d+)/', $sql, $matches)) {
            $sigilCol = $matches[1];
            $this->participation[$sigilCol] = (int)$this->participation[$sigilCol] + 1;
            $this->participation['sigil_drops_total']++;
            $this->participation['last_meaningful_economy_tick'] = (int)$params[0];
            $pulseTickCol = strpos($sql, 'last_return_pulse_tick') !== false
                ? 'last_return_pulse_tick'
                : 'last_active_pulse_tick';
            $pulseTotalCol = strpos($sql, 'return_pulses_total') !== false
                ? 'return_pulses_total'
                : 'active_pulses_total';
            $this->participation[$pulseTickCol] = (int)$params[1];
            $this->participation[$pulseTotalCol]++;
            return;
        }

        if (strpos($sql, 'INSERT INTO sigil_drop_log') !== false) {
            $this->dropLog[] = [
                'player_id' => (int)$params[0],
                'season_id' => (int)$params[1],
                'drop_tick' => (int)$params[2],
                'tier' => (int)$params[3],
                'source' => (string)$params[4],
            ];
        }
    }
}
