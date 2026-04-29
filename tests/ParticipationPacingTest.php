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
