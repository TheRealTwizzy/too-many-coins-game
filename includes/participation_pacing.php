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

    public static function markMeaningfulEconomyEvent($db, int $playerId, int $seasonId, int $tick): void
    {
        $db->query(
            "UPDATE season_participation SET last_meaningful_economy_tick = ?
             WHERE player_id = ? AND season_id = ?",
            [$tick, $playerId, $seasonId]
        );
    }

    public static function grantReturnPulseForPlayer($db, int $playerId, int $seasonId, int $tick, ?array $playerSnapshot = null): array
    {
        return self::grantPulseForPlayer($db, $playerId, $seasonId, $tick, self::SOURCE_RETURN, null, $playerSnapshot);
    }

    public static function grantActivePulseForPlayer($db, int $playerId, int $seasonId, int $tick, string $presenceState): array
    {
        return self::grantPulseForPlayer($db, $playerId, $seasonId, $tick, self::SOURCE_ACTIVE, $presenceState);
    }

    private static function grantPulseForPlayer(
        $db,
        int $playerId,
        int $seasonId,
        int $tick,
        string $source,
        ?string $presenceState = null,
        ?array $playerSnapshot = null
    ): array {
        $startedTransaction = self::beginTransactionIfNeeded($db);
        $season = null;

        try {
            $season = $db->fetch("SELECT * FROM seasons WHERE season_id = ?", [$seasonId]);
            if (!$season) {
                self::commitIfStarted($db, $startedTransaction);
                return self::grantResult(false, 'season_not_found', 1);
            }

            $player = $db->fetch("SELECT * FROM players WHERE player_id = ?", [$playerId]);
            if (!$player) {
                self::commitIfStarted($db, $startedTransaction);
                return self::grantResult(false, 'player_not_found', self::rewardTier($season));
            }

            $participation = $db->fetch(
                "SELECT * FROM season_participation WHERE player_id = ? AND season_id = ? FOR UPDATE",
                [$playerId, $seasonId]
            );
            if (!$participation) {
                self::commitIfStarted($db, $startedTransaction);
                return self::grantResult(false, 'not_participating', self::rewardTier($season));
            }

            if ($source === self::SOURCE_RETURN) {
                $decisionPlayer = self::returnDecisionPlayer($player, $playerSnapshot);
                $decision = self::returnPulseDecision($season, $decisionPlayer, $participation, $tick);
                $pulseTickColumn = 'last_return_pulse_tick';
                $pulseTotalColumn = 'return_pulses_total';
            } elseif ($source === self::SOURCE_ACTIVE) {
                $decision = self::activePulseDecision($season, $player, $participation, $tick, $presenceState ?? 'Active');
                $pulseTickColumn = 'last_active_pulse_tick';
                $pulseTotalColumn = 'active_pulses_total';
            } else {
                self::commitIfStarted($db, $startedTransaction);
                return self::grantResult(false, 'invalid_source', self::rewardTier($season));
            }

            $tier = (int)($decision['reward_tier'] ?? self::rewardTier($season));
            if (empty($decision['eligible'])) {
                self::commitIfStarted($db, $startedTransaction);
                return self::grantResult(false, (string)($decision['reason_code'] ?? 'not_eligible'), $tier);
            }

            $sigilColumn = 'sigils_t' . $tier;
            $db->query(
                "UPDATE season_participation SET
                 {$sigilColumn} = {$sigilColumn} + 1,
                 sigil_drops_total = sigil_drops_total + 1,
                 last_meaningful_economy_tick = ?,
                 {$pulseTickColumn} = ?,
                 {$pulseTotalColumn} = {$pulseTotalColumn} + 1
                 WHERE player_id = ? AND season_id = ?",
                [$tick, $tick, $playerId, $seasonId]
            );

            $db->query(
                "INSERT INTO sigil_drop_log (player_id, season_id, drop_tick, tier, source)
                 VALUES (?, ?, ?, ?, ?)",
                [$playerId, $seasonId, $tick, $tier, $source]
            );

            self::commitIfStarted($db, $startedTransaction);
            return self::grantResult(true, 'granted', $tier);
        } catch (Throwable $e) {
            self::rollbackIfStarted($db, $startedTransaction);
            if (!$startedTransaction) {
                throw $e;
            }

            return self::grantResult(false, 'grant_failed', is_array($season) ? self::rewardTier($season) : 1);
        }
    }

    private static function grantResult(bool $granted, string $reasonCode, int $tier): array
    {
        return [
            'granted' => $granted,
            'reason_code' => $reasonCode,
            'tier' => $tier,
        ];
    }

    private static function returnDecisionPlayer(array $player, ?array $playerSnapshot): array
    {
        if ($playerSnapshot !== null) {
            $player = array_merge($player, $playerSnapshot);
        }

        $player['activity_state'] = 'Active';
        $player['economic_presence_state'] = 'Active';
        $player['idle_modal_active'] = 0;
        $player['online_current'] = 1;

        return $player;
    }

    private static function beginTransactionIfNeeded($db): bool
    {
        if (!method_exists($db, 'beginTransaction')) {
            return false;
        }

        $connection = method_exists($db, 'getConnection') ? $db->getConnection() : null;
        $inTransaction = $connection !== null
            && method_exists($connection, 'inTransaction')
            && $connection->inTransaction();

        if ($inTransaction) {
            return false;
        }

        $db->beginTransaction();
        return true;
    }

    private static function commitIfStarted($db, bool $startedTransaction): void
    {
        if ($startedTransaction && method_exists($db, 'commit')) {
            $db->commit();
        }
    }

    private static function rollbackIfStarted($db, bool $startedTransaction): void
    {
        if ($startedTransaction && method_exists($db, 'rollback')) {
            $db->rollback();
        }
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
