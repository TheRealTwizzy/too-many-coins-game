<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/economy.php';

class ParticipationPacing
{
    public const SOURCE_RETURN = 'return_pulse';
    public const SOURCE_ACTIVE = 'active_dry_spell_pulse';

    public static function returnPulseDecision(array $season, array $player, array $participation, int $tick): array
    {
        $rewardTier = self::rewardTier($season);
        $baseBlock = self::baseEligibilityBlock($season, $player, $participation, $tick, $rewardTier);
        if ($baseBlock !== null) {
            return $baseBlock;
        }

        $lastReturn = max(0, (int)($participation['last_return_pulse_tick'] ?? 0));
        $cooldown = max(1, (int)($season['return_pulse_cooldown_ticks'] ?? 1));
        if ($lastReturn > 0 && ($tick - $lastReturn) < $cooldown) {
            return self::decision(false, 'return_pulse_cooldown', $rewardTier);
        }

        $inactiveSinceTick = (int)($player['idle_since_tick'] ?? 0);
        if ($inactiveSinceTick <= 0) {
            $inactiveSinceTick = (int)($player['last_activity_tick'] ?? 0);
        }

        $minGap = max(1, (int)($season['return_pulse_min_gap_ticks'] ?? 1));
        if ($inactiveSinceTick <= 0 || ($tick - $inactiveSinceTick) < $minGap) {
            return self::decision(false, 'return_gap_too_short', $rewardTier);
        }

        return self::decision(true, 'eligible', $rewardTier);
    }

    public static function activePulseDecision(
        array $season,
        array $player,
        array $participation,
        int $tick,
        string $presenceState = 'Active'
    ): array {
        $rewardTier = self::rewardTier($season);
        $baseBlock = self::baseEligibilityBlock($season, $player, $participation, $tick, $rewardTier, $presenceState);
        if ($baseBlock !== null) {
            return $baseBlock;
        }

        $referenceTick = max(
            (int)($participation['last_meaningful_economy_tick'] ?? 0),
            (int)($participation['last_active_pulse_tick'] ?? 0)
        );
        if ($referenceTick <= 0) {
            $referenceTick = (int)($player['last_activity_tick'] ?? 0);
        }

        $drySpellTicks = max(1, (int)($season['active_dry_spell_ticks'] ?? 1));
        if ($referenceTick <= 0 || ($tick - $referenceTick) < $drySpellTicks) {
            return self::decision(false, 'active_gap_too_short', $rewardTier);
        }

        return self::decision(true, 'eligible', $rewardTier);
    }

    public static function applyPulseToParticipation(array &$participation, array $season, string $source, int $tick): bool
    {
        if ($source !== self::SOURCE_RETURN && $source !== self::SOURCE_ACTIVE) {
            return false;
        }

        $tier = self::rewardTier($season);
        if (!Economy::canReceiveSigilTier($participation, $tier, 1)) {
            return false;
        }

        $sigilKey = 'sigils_t' . $tier;
        $participation[$sigilKey] = max(0, (int)($participation[$sigilKey] ?? 0)) + 1;
        $participation['sigil_drops_total'] = max(0, (int)($participation['sigil_drops_total'] ?? 0)) + 1;
        $participation['last_meaningful_economy_tick'] = $tick;

        if ($source === self::SOURCE_RETURN) {
            $participation['last_return_pulse_tick'] = $tick;
            $participation['return_pulses_total'] = max(0, (int)($participation['return_pulses_total'] ?? 0)) + 1;
            return true;
        }

        $participation['last_active_pulse_tick'] = $tick;
        $participation['active_pulses_total'] = max(0, (int)($participation['active_pulses_total'] ?? 0)) + 1;
        return true;
    }

    public static function rewardTier(array $season): int
    {
        return max(1, min(1, (int)($season['participation_pulse_reward_tier'] ?? 1)));
    }

    public static function markMeaningfulEconomyEventInArray(array &$participation, int $tick): void
    {
        $participation['last_meaningful_economy_tick'] = $tick;
    }

    private static function baseEligibilityBlock(
        array $season,
        array $player,
        array $participation,
        int $tick,
        int $rewardTier,
        ?string $presenceState = null
    ): ?array {
        if ((int)($season['blackout_time'] ?? PHP_INT_MAX) <= $tick) {
            return self::decision(false, 'blackout_blocked', $rewardTier);
        }

        $state = $presenceState ?? (string)($player['activity_state'] ?? '');
        if ($state !== 'Active') {
            return self::decision(false, 'not_active', $rewardTier);
        }

        if (!Economy::canReceiveSigilTier($participation, $rewardTier, 1)) {
            return self::decision(false, 'sigil_capacity', $rewardTier);
        }

        return null;
    }

    private static function decision(bool $eligible, string $reasonCode, int $rewardTier): array
    {
        return [
            'eligible' => $eligible,
            'reason_code' => $reasonCode,
            'reward_tier' => $rewardTier,
        ];
    }
}
