<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/simulation/Archetypes.php';
require_once __DIR__ . '/../scripts/simulation/SimulationSeason.php';
require_once __DIR__ . '/../scripts/simulation/SimulationPlayer.php';

class SimulationParticipationPacingTest extends TestCase
{
    public function testReturnPulseGrantsWhenPlayerBecomesActiveAfterIdleGap(): void
    {
        $season = $this->season();
        $start = (int)$season['start_time'];
        $player = $this->player('regular', 'sim-return-pulse');

        $player->setPresenceState('Idle', $start + 1);
        $player->setPresenceState('Active', $start + 6, $season);

        $snapshot = $player->snapshot();

        $this->assertSame(1, (int)$snapshot['participation']['sigils_t1']);
        $this->assertSame($start + 6, (int)$snapshot['participation']['last_return_pulse_tick']);
        $this->assertSame(1, (int)$snapshot['participation']['return_pulses_total']);
        $this->assertSame(1, (int)$snapshot['metrics']['participation_pulses_by_source']['return_pulse']);
        $this->assertSame(1, (int)$snapshot['metrics']['sigils_acquired_by_source']['return_pulse']);
    }

    public function testActiveDrySpellPulseGrantsAfterQuietActiveWindow(): void
    {
        $season = $this->season();
        $start = (int)$season['start_time'];
        $player = $this->player('hardcore', 'sim-active-pulse');

        $player->setPresenceState('Active', $start + 1, $season);
        $player->processParticipationPacing($season, 'EARLY', $start + 6);

        $snapshot = $player->snapshot();

        $this->assertSame(1, (int)$snapshot['participation']['sigils_t1']);
        $this->assertSame($start + 6, (int)$snapshot['participation']['last_active_pulse_tick']);
        $this->assertSame(1, (int)$snapshot['participation']['active_pulses_total']);
        $this->assertSame(1, (int)$snapshot['metrics']['participation_pulses_by_source']['active_dry_spell_pulse']);
        $this->assertSame(1, (int)$snapshot['metrics']['sigils_acquired_by_source']['active_dry_spell_pulse']);
        $this->assertSame(5, (int)$snapshot['metrics']['max_active_dry_spell_ticks']);
        $this->assertSame(0, (int)$snapshot['metrics']['active_dry_spell_violations']);
    }

    public function testRecentMeaningfulEconomyEventBlocksDrySpellPulseBeforeWindow(): void
    {
        $season = $this->season();
        $start = (int)$season['start_time'];
        $player = $this->player('hardcore', 'sim-active-blocked');

        $this->seedParticipation($player, ['last_meaningful_economy_tick' => $start + 3]);
        $player->setPresenceState('Active', $start + 1, $season);
        $player->processParticipationPacing($season, 'EARLY', $start + 7);

        $snapshot = $player->snapshot();

        $this->assertSame(0, (int)$snapshot['participation']['sigils_t1']);
        $this->assertSame(0, (int)$snapshot['participation']['active_pulses_total']);
        $this->assertSame(0, (int)$snapshot['metrics']['participation_pulses_by_source']['active_dry_spell_pulse']);
        $this->assertSame(4, (int)$snapshot['metrics']['max_active_dry_spell_ticks']);
        $this->assertSame(0, (int)$snapshot['metrics']['active_dry_spell_violations']);
    }

    public function testTwoArgumentPresenceRefreshesLastActivityTickForCompatibility(): void
    {
        $season = $this->season();
        $start = (int)$season['start_time'];
        $player = $this->player('regular', 'sim-presence-compat');

        $player->setPresenceState('Active', $start + 1);
        $player->setPresenceState('Active', $start + 7);

        $snapshot = $player->snapshot();

        $this->assertSame($start + 7, (int)$snapshot['player']['last_activity_tick']);
    }

    public function testSameTickMeaningfulActionBlocksDrySpellPulse(): void
    {
        $season = $this->season();
        $start = (int)$season['start_time'];
        $tick = $start + 6;
        $player = $this->player('hardcore', 'sim-same-tick-action');

        $this->seedParticipation($player, ['sigils_t1' => 5]);
        $player->setPresenceState('Active', $start + 1, $season);
        $player->setPresenceState('Active', $tick, $season);
        $this->combineSigils($player, 1, 'EARLY');
        $player->processParticipationPacing($season, 'EARLY', $tick);

        $snapshot = $player->snapshot();

        $this->assertSame(0, (int)$snapshot['participation']['sigils_t1']);
        $this->assertSame(1, (int)$snapshot['participation']['sigils_t2']);
        $this->assertSame(0, (int)$snapshot['participation']['active_pulses_total']);
        $this->assertSame(0, (int)$snapshot['metrics']['participation_pulses_by_source']['active_dry_spell_pulse']);
        $this->assertSame(1, (int)$snapshot['metrics']['sigils_acquired_by_source']['combine']);
        $this->assertSame(0, (int)$snapshot['metrics']['sigils_acquired_by_tier']['2']);
    }

    private function season(): array
    {
        return SimulationSeason::build(1, 'simulation-participation-pacing', [
            'return_pulse_min_gap_ticks' => 5,
            'return_pulse_cooldown_ticks' => 30,
            'active_dry_spell_ticks' => 5,
            'participation_pulse_reward_tier' => 1,
        ]);
    }

    private function player(string $archetypeKey, string $seed): SimulationPlayer
    {
        return new SimulationPlayer(
            1,
            $archetypeKey,
            Archetypes::get($archetypeKey),
            $seed,
            1
        );
    }

    private function seedParticipation(SimulationPlayer $player, array $overrides): void
    {
        $mutator = function () use ($overrides): void {
            $this->participation = array_replace($this->participation, $overrides);
        };

        $bound = \Closure::bind($mutator, $player, SimulationPlayer::class);
        $bound();
    }

    private function combineSigils(SimulationPlayer $player, int $fromTier, string $phase): void
    {
        $combiner = function () use ($fromTier, $phase): void {
            $this->combineSigils($fromTier, $phase);
        };

        $bound = \Closure::bind($combiner, $player, SimulationPlayer::class);
        $bound();
    }
}
