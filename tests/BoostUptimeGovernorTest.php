<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/simulation/Archetypes.php';
require_once __DIR__ . '/../scripts/simulation/SimulationPlayer.php';
require_once __DIR__ . '/../scripts/simulation/RuntimeParityCertification.php';

class BoostUptimeGovernorTest extends TestCase
{
    public function testRecoveryWindowIsTwelveHoursAfterSessionExpiry(): void
    {
        $this->assertSame(12 * 60 * 60, BoostCatalog::RECOVERY_SECONDS_AFTER_SESSION);
        $this->assertSame(
            ticks_from_real_seconds(12 * 60 * 60),
            BoostCatalog::getRecoveryTicks()
        );
        $this->assertSame(
            100 + ticks_from_real_seconds(12 * 60 * 60),
            BoostCatalog::getRecoveryUntilTick(100)
        );
    }

    public function testSimulatorTimePurchaseCannotRollSessionPastOriginalTimeCap(): void
    {
        $player = new SimulationPlayer(
            1,
            'boost_focused',
            Archetypes::get('boost_focused'),
            'boost-session-cap-test',
            1
        );
        $this->seedPlayer($player, ['sigils_t1' => 1, 'sigils_t5' => 1]);

        $this->invokePlayerMethod($player, 'purchaseBoost', [1, 'power', 10, 'EARLY']);
        $afterInitial = $player->snapshot();
        $cappedExpiry = 10 + ticks_from_real_seconds(BoostCatalog::TIME_CAP_SECONDS_PER_PRODUCT);

        $this->assertSame($cappedExpiry, (int)$afterInitial['boost']['expires_tick']);

        $this->invokePlayerMethod($player, 'purchaseBoost', [5, 'time', 11, 'EARLY']);
        $afterTimeAttempt = $player->snapshot();

        $this->assertSame($cappedExpiry, (int)$afterTimeAttempt['boost']['expires_tick']);
        $this->assertSame(1, (int)$afterTimeAttempt['participation']['sigils_t5']);
        $this->assertSame(1, (int)$afterTimeAttempt['metrics']['sigils_spent_by_action']['boost']);
    }

    public function testRuntimeParityTimePurchaseCannotRollSessionPastOriginalTimeCap(): void
    {
        $state = $this->runtimeState(['sigils_t1' => 1, 'sigils_t5' => 1]);
        $this->invokeRuntimeMethod('runtimePurchaseBoost', [&$state, 1, 'power', 10]);
        $cappedExpiry = 10 + ticks_from_real_seconds(BoostCatalog::TIME_CAP_SECONDS_PER_PRODUCT);

        $this->assertSame($cappedExpiry, (int)$state['boost']['expires_tick']);

        $this->invokeRuntimeMethod('runtimePurchaseBoost', [&$state, 5, 'time', 11]);

        $this->assertSame($cappedExpiry, (int)$state['boost']['expires_tick']);
        $this->assertSame(1, (int)$state['participation']['sigils_t5']);
    }

    public function testSimulatorPowerPurchaseCannotStackSingleSessionPastPerProductCap(): void
    {
        $player = new SimulationPlayer(
            1,
            'boost_focused',
            Archetypes::get('boost_focused'),
            'boost-power-cap-test',
            1
        );
        $this->seedPlayer($player, ['sigils_t5' => 2]);

        $this->invokePlayerMethod($player, 'purchaseBoost', [5, 'power', 10, 'EARLY']);
        $afterInitial = $player->snapshot();

        $this->assertSame(BoostCatalog::POWER_CAP_FP_PER_PRODUCT, (int)$afterInitial['boost']['modifier_fp']);

        $this->invokePlayerMethod($player, 'purchaseBoost', [5, 'power', 11, 'EARLY']);
        $afterSecondAttempt = $player->snapshot();

        $this->assertSame(BoostCatalog::POWER_CAP_FP_PER_PRODUCT, (int)$afterSecondAttempt['boost']['modifier_fp']);
        $this->assertSame(1, (int)$afterSecondAttempt['participation']['sigils_t5']);
        $this->assertSame(1, (int)$afterSecondAttempt['metrics']['sigils_spent_by_action']['boost']);
    }

    public function testRuntimeParityPowerPurchaseCannotStackSingleSessionPastPerProductCap(): void
    {
        $state = $this->runtimeState(['sigils_t5' => 2]);

        $this->invokeRuntimeMethod('runtimePurchaseBoost', [&$state, 5, 'power', 10]);
        $this->assertSame(BoostCatalog::POWER_CAP_FP_PER_PRODUCT, (int)$state['boost']['modifier_fp']);

        $this->invokeRuntimeMethod('runtimePurchaseBoost', [&$state, 5, 'power', 11]);

        $this->assertSame(BoostCatalog::POWER_CAP_FP_PER_PRODUCT, (int)$state['boost']['modifier_fp']);
        $this->assertSame(1, (int)$state['participation']['sigils_t5']);
    }

    public function testSimulatorRequiresRecoveryAfterBoostSessionExpiresBeforeReactivation(): void
    {
        $player = new SimulationPlayer(
            1,
            'boost_focused',
            Archetypes::get('boost_focused'),
            'boost-recovery-test',
            1
        );
        $this->seedPlayer($player, ['sigils_t5' => 2]);

        $this->invokePlayerMethod($player, 'purchaseBoost', [5, 'power', 10, 'EARLY']);
        $afterInitial = $player->snapshot();
        $expiresTick = (int)$afterInitial['boost']['expires_tick'];
        $recoveryUntilTick = $expiresTick + ticks_from_real_seconds(BoostCatalog::RECOVERY_SECONDS_AFTER_SESSION);

        $player->expireEffects($expiresTick + 1);
        $this->invokePlayerMethod($player, 'purchaseBoost', [5, 'power', $expiresTick + 1, 'EARLY']);
        $duringRecovery = $player->snapshot();

        $this->assertFalse((bool)$duringRecovery['boost']['is_active']);
        $this->assertSame($recoveryUntilTick, (int)$duringRecovery['boost']['recovery_until_tick']);
        $this->assertSame(1, (int)$duringRecovery['participation']['sigils_t5']);
        $this->assertSame(1, (int)$duringRecovery['metrics']['sigils_spent_by_action']['boost']);

        $this->invokePlayerMethod($player, 'purchaseBoost', [5, 'power', $recoveryUntilTick, 'MID']);
        $afterRecovery = $player->snapshot();

        $this->assertTrue((bool)$afterRecovery['boost']['is_active']);
        $this->assertSame(0, (int)$afterRecovery['participation']['sigils_t5']);
        $this->assertSame(2, (int)$afterRecovery['metrics']['sigils_spent_by_action']['boost']);
    }

    public function testSimulatorExpiresOverlongStoredSessionAtOriginalCap(): void
    {
        $player = new SimulationPlayer(
            1,
            'boost_focused',
            Archetypes::get('boost_focused'),
            'boost-overlong-session-test',
            1
        );
        $sessionMax = BoostCatalog::getSessionMaxExpiresTick(10);
        $this->seedBoost($player, [
            'is_active' => true,
            'modifier_fp' => 50000,
            'activated_tick' => 10,
            'expires_tick' => $sessionMax + 20,
            'recovery_until_tick' => 0,
        ]);

        $player->expireEffects($sessionMax);
        $atCap = $player->snapshot();
        $this->assertTrue((bool)$atCap['boost']['is_active']);

        $player->expireEffects($sessionMax + 1);
        $afterCap = $player->snapshot();
        $this->assertFalse((bool)$afterCap['boost']['is_active']);
        $this->assertSame(
            BoostCatalog::getRecoveryUntilTick($sessionMax),
            (int)$afterCap['boost']['recovery_until_tick']
        );
    }

    private function seedPlayer(SimulationPlayer $player, array $participationOverrides): void
    {
        $mutator = function () use ($participationOverrides): void {
            $this->participation = array_replace($this->participation, $participationOverrides);
        };

        $bound = \Closure::bind($mutator, $player, SimulationPlayer::class);
        $bound();
    }

    private function seedBoost(SimulationPlayer $player, array $boostOverrides): void
    {
        $mutator = function () use ($boostOverrides): void {
            $this->boost = array_replace($this->boost, $boostOverrides);
        };

        $bound = \Closure::bind($mutator, $player, SimulationPlayer::class);
        $bound();
    }

    private function invokePlayerMethod(SimulationPlayer $player, string $method, array $args): mixed
    {
        $invoker = function (string $method, array $args): mixed {
            return $this->{$method}(...$args);
        };

        $bound = \Closure::bind($invoker, $player, SimulationPlayer::class);
        return $bound($method, $args);
    }

    private function runtimeState(array $participationOverrides): array
    {
        return [
            'player' => ['player_id' => 1],
            'participation' => array_replace([
                'sigils_t1' => 0,
                'sigils_t2' => 0,
                'sigils_t3' => 0,
                'sigils_t4' => 0,
                'sigils_t5' => 0,
                'sigils_t6' => 0,
            ], $participationOverrides),
            'boost' => [
                'is_active' => false,
                'modifier_fp' => 0,
                'activated_tick' => 0,
                'expires_tick' => 0,
                'recovery_until_tick' => 0,
            ],
            'metrics' => ['ticks_boosted' => 0],
        ];
    }

    private function invokeRuntimeMethod(string $method, array $args): mixed
    {
        $reflection = new ReflectionMethod(RuntimeParityCertification::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs(null, $args);
    }
}
