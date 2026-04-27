<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/simulation/Archetypes.php';
require_once __DIR__ . '/../scripts/simulation/PolicyBehavior.php';

class PolicyBehaviorUtilityFlowTest extends TestCase
{
    public function testFreezeCanTargetWithOnlyTierFourUtilitySigil(): void
    {
        $archetype = Archetypes::get('aggressive_sigil_user');
        $playerState = $this->playerState(1, ['sigils_t4' => 1]);
        $candidates = [$this->playerState(2, ['seasonal_stars' => 100, 'sigils_t4' => 2])];

        $target = $this->firstChosenFreezeTarget($archetype, $playerState, $candidates);

        $this->assertSame(2, $target);
    }

    public function testTheftCanTargetWithOnlyTierThreeUtilitySigil(): void
    {
        $archetype = Archetypes::get('aggressive_sigil_user');
        $playerState = $this->playerState(1, ['sigils_t3' => 1]);
        $candidates = [$this->playerState(2, ['seasonal_stars' => 100, 'sigils_t2' => 3])];

        $target = $this->firstChosenTheftTarget($archetype, $playerState, $candidates);

        $this->assertSame(2, $target);
    }

    private function firstChosenFreezeTarget(array $archetype, array $playerState, array $candidates): ?int
    {
        for ($tick = 1; $tick <= 500; $tick++) {
            $target = PolicyBehavior::chooseFreezeTarget($archetype, $playerState, $candidates, 'BLACKOUT', 'utility-flow-freeze', $tick);
            if ($target !== null) {
                return $target;
            }
        }

        return null;
    }

    private function firstChosenTheftTarget(array $archetype, array $playerState, array $candidates): ?int
    {
        for ($tick = 1; $tick <= 500; $tick++) {
            $target = PolicyBehavior::chooseTheftTarget($archetype, $playerState, $candidates, 'BLACKOUT', 'utility-flow-theft', $tick);
            if ($target !== null) {
                return $target;
            }
        }

        return null;
    }

    private function playerState(int $playerId, array $participationOverrides): array
    {
        $participation = array_merge([
            'seasonal_stars' => 0,
            'sigils_t1' => 0,
            'sigils_t2' => 0,
            'sigils_t3' => 0,
            'sigils_t4' => 0,
            'sigils_t5' => 0,
            'sigils_t6' => 0,
        ], $participationOverrides);

        return [
            'player_id' => $playerId,
            'participation' => $participation,
            'locked_out' => false,
            'player' => ['player_id' => $playerId],
            'boost' => ['is_active' => false, 'modifier_fp' => 0],
            'freeze' => ['is_active' => false],
        ];
    }
}
