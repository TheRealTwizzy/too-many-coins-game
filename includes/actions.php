<?php
/**
 * Too Many Coins - Player Actions
 * Handles all player actions: join, purchase stars, boost, freeze, theft, lock-in
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/game_time.php';
require_once __DIR__ . '/economy.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/boost_catalog.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/sigil_families.php';

class Actions {

    public static function validateSigilInventoryCaps($participation) {
        for ($tier = 1; $tier <= SIGIL_MAX_TIER; $tier++) {
            $count = max(0, (int)($participation['sigils_t' . $tier] ?? 0));
            $tierCap = Economy::getSigilTierCap($tier);
            if ($tierCap > 0 && $count > $tierCap) {
                return 'Sigil inventory cap reached for Tier ' . $tier;
            }
        }

        $total = Economy::getSigilTotal($participation);
        $totalCap = Economy::getSigilTotalCap();
        if ($totalCap > 0 && $total > $totalCap) {
            return 'Sigil inventory total cap reached';
        }

        return null;
    }

    private static function doesTableExist($db, $tableName) {
        $row = $db->fetch(
            "SELECT COUNT(*) AS c
             FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?",
            [(string)$tableName]
        );

        return ((int)($row['c'] ?? 0)) > 0;
    }

    private static function clearSeasonRunAuxiliaryState($db, $playerId, $seasonId) {
        if (self::doesTableExist($db, 'active_boosts')) {
            $db->query(
                "DELETE FROM active_boosts WHERE player_id = ? AND season_id = ?",
                [(int)$playerId, (int)$seasonId]
            );
        }

        if (self::doesTableExist($db, 'active_freezes')) {
            $db->query(
                "DELETE FROM active_freezes
                 WHERE season_id = ? AND (source_player_id = ? OR target_player_id = ?)",
                [(int)$seasonId, (int)$playerId, (int)$playerId]
            );
        }

        if (self::doesTableExist($db, 'player_season_vault')) {
            $db->query(
                "DELETE FROM player_season_vault WHERE player_id = ? AND season_id = ?",
                [(int)$playerId, (int)$seasonId]
            );
        }

        // Family holdings and wards are season-run-local like boosts/freezes.
        SigilFamilies::clearSeasonHoldings($db, (int)$playerId, (int)$seasonId);
    }

    private static function resetSeasonParticipationForFreshStart($db, $playerId, $seasonId, $gameTime) {
        $db->query(
            "UPDATE season_participation SET
             coins = 0, coins_fractional_fp = 0, seasonal_stars = 0,
             sigils_t1 = 0, sigils_t2 = 0, sigils_t3 = 0, sigils_t4 = 0, sigils_t5 = 0, sigils_t6 = 0,
             sigil_drops_total = 0, eligible_ticks_since_last_drop = 0,
             pending_rng_sigil_drops = 0, pending_pity_sigil_drops = 0, sigil_next_delivery_tick = 0,
             participation_time_total = 0, participation_ticks_since_join = 0, active_ticks_total = 0,
             total_season_participation_ticks = total_season_participation_ticks + participation_ticks_since_join,
             first_joined_at = ?, last_exit_at = NULL,
             spend_window_total = 0, hoarding_sink_total = 0,
             reactivation_balance_snapshot = 0, reactivation_start_tick = NULL,
             lock_in_effect_tick = NULL, lock_in_snapshot_seasonal_stars = NULL,
             lock_in_snapshot_participation_time = NULL,
             end_membership = 0, final_rank = NULL, final_seasonal_stars = NULL,
             global_stars_earned = 0, participation_bonus = 0, placement_bonus = 0,
             badge_awarded = NULL,
             active_boosts = NULL
             WHERE player_id = ? AND season_id = ?",
            [(int)$gameTime, (int)$playerId, (int)$seasonId]
        );

        if (SigilFamilies::schemaReady($db)) {
            $db->query(
                "UPDATE season_participation SET
                 affinity_family_id = NULL, affinity_repicked_at_tick = NULL,
                 market_pending_vp = 0, market_last_used_tick = 0
                 WHERE player_id = ? AND season_id = ?",
                [(int)$playerId, (int)$seasonId]
            );
        }
    }

    private static function normalizeSigilVector($value) {
        $counts = array_fill(0, SIGIL_MAX_TIER, 0);
        if (!is_array($value)) {
            return $counts;
        }

        for ($i = 0; $i < SIGIL_MAX_TIER; $i++) {
            $counts[$i] = max(0, (int)($value[$i] ?? 0));
        }

        return $counts;
    }

    private static function sumSigilVector(array $sigils, $tiers = null) {
        if ($tiers === null) {
            return array_sum($sigils);
        }

        $total = 0;
        foreach ($tiers as $tier) {
            $idx = (int)$tier - 1;
            if ($idx >= 0 && $idx < SIGIL_MAX_TIER) {
                $total += max(0, (int)($sigils[$idx] ?? 0));
            }
        }

        return $total;
    }

    private static function calculateSigilVectorValue(array $sigils, array $valueTable) {
        $total = 0;
        for ($tier = 1; $tier <= SIGIL_MAX_TIER; $tier++) {
            $total += max(0, (int)($sigils[$tier - 1] ?? 0)) * max(0, (int)($valueTable[$tier] ?? 0));
        }
        return $total;
    }

    private static function summarizeSigilVector(array $sigils) {
        $parts = [];
        for ($tier = 1; $tier <= SIGIL_MAX_TIER; $tier++) {
            $count = max(0, (int)($sigils[$tier - 1] ?? 0));
            if ($count > 0) {
                $parts[] = $count . 'xT' . $tier;
            }
        }

        return empty($parts) ? 'none' : implode(', ', $parts);
    }

    private static function normalizeSigilTierList(array $tiers) {
        $normalized = [];
        foreach ($tiers as $tier) {
            $tier = (int)$tier;
            if ($tier < 1 || $tier > SIGIL_MAX_TIER || in_array($tier, $normalized, true)) {
                continue;
            }
            $normalized[] = $tier;
        }
        return $normalized;
    }

    private static function formatSigilTierList(array $tiers) {
        $labels = array_map(
            static fn($tier) => 'Tier ' . (int)$tier,
            self::normalizeSigilTierList($tiers)
        );
        $count = count($labels);
        if ($count === 0) {
            return 'configured-tier';
        }
        if ($count === 1) {
            return $labels[0];
        }
        if ($count === 2) {
            return $labels[0] . ' or ' . $labels[1];
        }
        $last = array_pop($labels);
        return implode(', ', $labels) . ', or ' . $last;
    }

    private static function selectOwnedSigilTier(array $participation, array $tiers, bool $preferHighest = true) {
        $tiers = self::normalizeSigilTierList($tiers);
        if ($preferHighest) {
            rsort($tiers, SORT_NUMERIC);
        } else {
            sort($tiers, SORT_NUMERIC);
        }

        foreach ($tiers as $tier) {
            if ((int)($participation['sigils_t' . $tier] ?? 0) > 0) {
                return $tier;
            }
        }

        return null;
    }

    private static function getSigilResourceType($tier) {
        return 'Sigil_T' . (int)$tier;
    }

    private static function logEconomyLedgerSigilChange($db, $globalTick, $seasonId, $seasonTick, $playerId, $tier, $amount, $direction, $category) {
        $amount = max(0, (int)$amount);
        if ($amount <= 0) {
            return;
        }

        $db->query(
            "INSERT INTO economy_ledger
             (global_tick, season_id, season_tick, player_id, resource_type, direction, amount, category)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [
                (int)$globalTick,
                (int)$seasonId,
                (int)$seasonTick,
                (int)$playerId,
                self::getSigilResourceType($tier),
                (string)$direction,
                $amount,
                (string)$category,
            ]
        );
    }

    private static function getTheftCooldownExpiresTick($db, $playerId, $seasonId) {
        $row = $db->fetch(
            "SELECT MAX(cooldown_expires_tick) AS cooldown_expires_tick
             FROM sigil_theft_attempts
             WHERE season_id = ? AND attacker_player_id = ?",
            [(int)$seasonId, (int)$playerId]
        );

        return max(0, (int)($row['cooldown_expires_tick'] ?? 0));
    }

    private static function getTheftProtectionExpiresTick($db, $playerId, $seasonId) {
        $row = $db->fetch(
            "SELECT MAX(protection_expires_tick) AS protection_expires_tick
             FROM sigil_theft_attempts
             WHERE season_id = ? AND target_player_id = ?",
            [(int)$seasonId, (int)$playerId]
        );

        return max(0, (int)($row['protection_expires_tick'] ?? 0));
    }

    private static function calculateTheftSuccessChanceFp($spendValue, $requestedValue) {
        $spendValue = max(0, (int)$spendValue);
        $requestedValue = max(0, (int)$requestedValue);
        if ($spendValue <= 0 || $requestedValue <= 0) {
            return 0;
        }

        $denominator = $spendValue + ((int)SIGIL_THEFT_VALUE_PRESSURE_MULTIPLIER * $requestedValue);
        if ($denominator <= 0) {
            return 0;
        }

        return min((int)SIGIL_THEFT_SUCCESS_CAP_FP, intdiv($spendValue * FP_SCALE, $denominator));
    }
    
    /**
     * Join a season
     */
    public static function seasonJoin($playerId, $seasonId) {
        $db = Database::getInstance();
        $player = $db->fetch("SELECT * FROM players WHERE player_id = ?", [$playerId]);
        
        // Staff check
        if ($player['role'] !== 'Player') {
            return ['error' => 'Staff accounts cannot participate in seasons', 'reason_code' => 'staff_participation_forbidden'];
        }
        
        // Already in a season check
        if ($player['joined_season_id'] !== null) {
            return ['error' => 'Already participating in a season. Lock-In or wait for season end first.'];
        }
        
        $season = $db->fetch("SELECT * FROM seasons WHERE season_id = ?", [$seasonId]);
        if (!$season) return ['error' => 'Season not found'];
        
        $status = GameTime::getSeasonStatus($season);
        if ($status === 'Scheduled') return ['error' => 'Season has not started yet'];
        if ($status === 'Expired') return ['error' => 'Season has expired'];
        
        // Check if join would be effective before expiration
        $gameTime = GameTime::now();
        if ($gameTime >= (int)$season['blackout_time']) {
            return ['error' => 'Season settlement has started'];
        }
        if ($gameTime >= $season['end_time'] - 1) {
            return ['error' => 'Too late to join this season'];
        }
        
        $db->beginTransaction();
        try {
            // Create or reset participation record
            $existing = $db->fetch(
                "SELECT * FROM season_participation WHERE player_id = ? AND season_id = ?",
                [$playerId, $seasonId]
            );
            
            if ($existing) {
                self::resetSeasonParticipationForFreshStart($db, $playerId, $seasonId, $gameTime);
                self::clearSeasonRunAuxiliaryState($db, $playerId, $seasonId);
            } else {
                $db->query(
                    "INSERT INTO season_participation (player_id, season_id, first_joined_at)
                     VALUES (?, ?, ?)",
                    [$playerId, $seasonId, $gameTime]
                );
            }
            
            // Update player state
            // Clear idle_since_tick to avoid stale idle-hold values persisting
            // after a rejoin (matches idleAck cleanup pattern).
            $db->query(
                "UPDATE players SET 
                 joined_season_id = ?, participation_enabled = 1,
                 idle_modal_active = 0, activity_state = 'Active',
                 idle_since_tick = NULL,
                 last_activity_tick = ?, online_current = 1, last_seen_at = NOW()
                 WHERE player_id = ?",
                [$seasonId, $gameTime, $playerId]
            );
            
            $db->commit();
            return ['success' => true, 'message' => 'Joined season successfully'];
        } catch (Exception $e) {
            $db->rollback();
            return ['error' => 'Failed to join season: ' . $e->getMessage()];
        }
    }
    
    /**
     * Purchase Seasonal Stars by quantity
     */
    public static function purchaseStars($playerId, $starsRequested) {
        $db = Database::getInstance();
        $player = $db->fetch("SELECT * FROM players WHERE player_id = ?", [$playerId]);
        
        if (!$player['participation_enabled'] || !$player['joined_season_id']) {
            return ['error' => 'Not participating in any season'];
        }
        if ($player['idle_modal_active']) {
            return ['error' => 'Cannot perform actions while idle', 'reason_code' => 'idle_gated'];
        }
        
        $seasonId = $player['joined_season_id'];
        $season = $db->fetch("SELECT * FROM seasons WHERE season_id = ?", [$seasonId]);
        $status = GameTime::getSeasonStatus($season);
        if ($status !== 'Active' && $status !== 'Blackout') {
            return ['error' => 'Star purchases are only available during the season'];
        }

        $participation = $db->fetch(
            "SELECT * FROM season_participation WHERE player_id = ? AND season_id = ?",
            [$playerId, $seasonId]
        );
        
        $starsRequested = (int)$starsRequested;
        if ($starsRequested <= 0) return ['error' => 'Must request a positive star quantity'];
        
        // Get locked star price
        $starPrice = Economy::publishedStarPrice($season, $status);
        if ($starPrice <= 0) return ['error' => 'Invalid star price'];
        
        $coinsNeeded = $starsRequested * $starPrice;

        // Market family: a primed discount applies to this one purchase,
        // derived from the player's own gross rate and capped at 50%.
        $marketSaved = 0;
        $pendingVp = (int)($participation['market_pending_vp'] ?? 0);
        if ($pendingVp > 0 && SigilFamilies::active($db)) {
            $player['current_game_time'] = GameTime::now();
            $grossFp = Economy::calculateUBIFp($season, $player, $participation);
            $marketSaved = SigilFamilies::marketCoinsSaved($pendingVp, $grossFp, $coinsNeeded);
        }
        $coinsCharged = max(0, $coinsNeeded - $marketSaved);

        // Affordability check
        if ($participation['coins'] < $coinsCharged) {
            return ['error' => 'Insufficient coins'];
        }

        $db->beginTransaction();
        try {
            // Burn coins, credit stars.
            // The affordability check above reads outside this transaction, so it
            // cannot be trusted alone: concurrent purchases would all pass it and
            // all commit, driving coins negative (the column is signed) and minting
            // stars from nothing. The `coins >= ?` guard is evaluated under the row
            // lock this UPDATE takes, so exactly one racer can win.
            $charged = $db->query(
                "UPDATE season_participation SET
                 coins = coins - ?, seasonal_stars = seasonal_stars + ?,
                 spend_window_total = spend_window_total + ?
                 WHERE player_id = ? AND season_id = ? AND coins >= ?",
                [$coinsCharged, $starsRequested, $coinsCharged, $playerId, $seasonId, $coinsCharged]
            )->rowCount();
            if ($charged !== 1) {
                $db->rollback();
                return ['error' => 'Insufficient coins'];
            }
            if ($pendingVp > 0 && $marketSaved >= 0 && SigilFamilies::schemaReady($db)) {
                $db->query(
                    "UPDATE season_participation SET market_pending_vp = 0
                     WHERE player_id = ? AND season_id = ?",
                    [$playerId, $seasonId]
                );
            }

            // Update season supply
            $db->query(
                "UPDATE seasons
                 SET total_coins_supply = total_coins_supply - ?,
                     pending_star_burn_coins = pending_star_burn_coins + ?
                 WHERE season_id = ?",
                [$coinsCharged, $coinsCharged, $seasonId]
            );
            
            // Update activity
            $db->query(
                "UPDATE players
                 SET last_activity_tick = ?, activity_state = 'Active', idle_modal_active = 0,
                     online_current = 1, last_seen_at = NOW()
                 WHERE player_id = ?",
                [GameTime::now(), $playerId]
            );
            
            $db->commit();
            return [
                'success' => true,
                'stars_purchased' => $starsRequested,
                'coins_spent' => $coinsCharged,
                'market_coins_saved' => $marketSaved,
                'star_price' => $starPrice
            ];
        } catch (Exception $e) {
            $db->rollback();
            return ['error' => 'Purchase failed'];
        }
    }
    
    /**
     * Lock-In: voluntarily exit season, convert SeasonalStars to GlobalStars
     */
    public static function lockIn($playerId) {
        $db = Database::getInstance();
        $player = $db->fetch("SELECT * FROM players WHERE player_id = ?", [$playerId]);
        
        if (!$player['participation_enabled'] || !$player['joined_season_id']) {
            return ['error' => 'Not participating in any season'];
        }
        if ($player['idle_modal_active']) {
            return ['error' => 'Cannot perform actions while idle', 'reason_code' => 'idle_gated'];
        }
        
        $seasonId = $player['joined_season_id'];
        $season = $db->fetch("SELECT * FROM seasons WHERE season_id = ?", [$seasonId]);
        
        $status = GameTime::getSeasonStatus($season);
        if ($status === 'Expired') {
            return ['error' => 'Season has expired'];
        }
        if ($status !== 'Active' && $status !== 'Blackout') {
            return ['error' => 'Lock-In is only available during the season'];
        }
        
        $participation = $db->fetch(
            "SELECT * FROM season_participation WHERE player_id = ? AND season_id = ?",
            [$playerId, $seasonId]
        );
        
        // Minimum participation check (current run)
        if ($participation['participation_ticks_since_join'] < MIN_PARTICIPATION_TICKS) {
            return ['error' => 'Must participate for at least 1 tick before Lock-In'];
        }

        // Seasonal participation floor: cumulative total across all runs in this season.
        // Prevents skip-rejoin-quick-lockin exploit where a player leaves and re-enters
        // to immediately lock in after 1 tick, bypassing the season participation intent.
        $totalSeasonTicks = (int)($participation['total_season_participation_ticks'] ?? 0)
                          + (int)($participation['participation_ticks_since_join'] ?? 0);
        if ($totalSeasonTicks < MIN_SEASONAL_LOCK_IN_TICKS) {
            return [
                'error'       => 'Must participate for at least ' . MIN_SEASONAL_LOCK_IN_TICKS
                               . ' total ticks across the season before locking in',
                'reason_code' => 'insufficient_seasonal_participation',
            ];
        }
        
        $gameTime = GameTime::now();
        $seasonalStars = (int)$participation['seasonal_stars'];

        // Use canonical sigil reference star values for lock-in payout.
        $tierCosts = [
            (int)(SIGIL_REFERENCE_STARS_BY_TIER[1] ?? 0),
            (int)(SIGIL_REFERENCE_STARS_BY_TIER[2] ?? 0),
            (int)(SIGIL_REFERENCE_STARS_BY_TIER[3] ?? 0),
            (int)(SIGIL_REFERENCE_STARS_BY_TIER[4] ?? 0),
            (int)(SIGIL_REFERENCE_STARS_BY_TIER[5] ?? 0),
            (int)(SIGIL_REFERENCE_STARS_BY_TIER[6] ?? 0),
        ];

        // --- Step 2: Compute payout (sigil refund → seasonal → 65% floor → global) ---
        $sigilCounts = [
            (int)$participation['sigils_t1'],
            (int)$participation['sigils_t2'],
            (int)$participation['sigils_t3'],
            (int)$participation['sigils_t4'],
            (int)$participation['sigils_t5'],
            (int)($participation['sigils_t6'] ?? 0),
        ];
        $payout = Economy::computeEarlyLockInPayout($seasonalStars, $sigilCounts, $tierCosts);
        $totalSeasonalStars = $payout['total_seasonal_stars'];
        $sigilRefundStars   = $payout['sigil_refund_stars'];
        $existingGlobalStarsCarryFp = max(0, (int)($player['global_stars_fractional_fp'] ?? 0));
        $globalStarGrant = Economy::applyGlobalStarsGrantWithCarry($totalSeasonalStars, $existingGlobalStarsCarryFp, 65, 100);
        $globalStarsGained  = $globalStarGrant['global_stars_gained'];
        $globalStarsCarryFp = $globalStarGrant['global_stars_fractional_fp'];
        $globalStarsProgressPercent = $globalStarGrant['global_stars_progress_percent'];

        $db->beginTransaction();
        try {
            // 1. Record Lock-In snapshot (snapshot reflects total seasonal including sigil refunds)
            //
            // This UPDATE is also the idempotency claim for the whole operation.
            // `lock_in_effect_tick IS NULL` means only one of N concurrent calls can
            // proceed; without it every racer read the same pre-state, all credited
            // global stars at step 2, and the "destroy resources" step at 3 is
            // absolute-valued so it stayed harmless when repeated — permanently
            // multiplying the payout.
            $claimed = $db->query(
                "UPDATE season_participation SET
                 lock_in_effect_tick = ?,
                 lock_in_snapshot_seasonal_stars = ?,
                 lock_in_snapshot_participation_time = participation_time_total,
                 last_exit_at = ?
                 WHERE player_id = ? AND season_id = ? AND lock_in_effect_tick IS NULL",
                [$gameTime, $totalSeasonalStars, $gameTime, $playerId, $seasonId]
            )->rowCount();
            if ($claimed !== 1) {
                $db->rollback();
                return ['error' => 'Already locked in for this season'];
            }

            // 2. Convert total seasonal stars → global stars at 65% while preserving carry
            $db->query(
                "UPDATE players SET global_stars = global_stars + ?, global_stars_fractional_fp = ? WHERE player_id = ?",
                [$globalStarsGained, $globalStarsCarryFp, $playerId]
            );
            
            // 3. Destroy all season-bound resources
            $db->query(
                "UPDATE season_participation SET 
                 coins = 0, coins_fractional_fp = 0, seasonal_stars = 0,
                 sigils_t1 = 0, sigils_t2 = 0, sigils_t3 = 0, sigils_t4 = 0, sigils_t5 = 0, sigils_t6 = 0,
                 reactivation_balance_snapshot = 0, reactivation_start_tick = NULL,
                 active_boosts = NULL
                 WHERE player_id = ? AND season_id = ?",
                [$playerId, $seasonId]
            );

            self::clearSeasonRunAuxiliaryState($db, $playerId, $seasonId);
            
            // 4. Exit season
            $db->query(
                "UPDATE players SET 
                 joined_season_id = NULL, participation_enabled = 0,
                 idle_modal_active = 0, activity_state = 'Active', online_current = 1, last_seen_at = NOW()
                 WHERE player_id = ?",
                [$playerId]
            );
            
            // Update coins supply
            $coinsDestroyed = (int)$participation['coins'];
            if ($coinsDestroyed > 0) {
                $db->query(
                    "UPDATE seasons SET total_coins_supply = GREATEST(0, total_coins_supply - ?) WHERE season_id = ?",
                    [$coinsDestroyed, $seasonId]
                );
            }
            
            $db->commit();

            $msg = "Locked in! Converted {$totalSeasonalStars} Seasonal Stars (including "
                 . "{$sigilRefundStars} refunded from sigils) into {$globalStarsGained} Global Stars, plus "
                 . "{$globalStarsProgressPercent}% banked toward the next Global Star.";
            return [
                'success' => true,
                'sigil_refund_stars'     => $sigilRefundStars,
                'seasonal_stars_converted' => $totalSeasonalStars,
                'global_stars_gained'    => $globalStarsGained,
                'global_stars_fractional_fp' => $globalStarsCarryFp,
                'global_stars_progress_percent' => $globalStarsProgressPercent,
                'message' => $msg,
            ];
        } catch (Exception $e) {
            $db->rollback();
            return ['error' => 'Lock-In failed'];
        }
    }
    
    /**
     * Spend configured utility sigils to attempt unilateral sigil theft from another player.
     */
    public static function attemptSigilTheft($playerId, $targetPlayerId, $spentSigils, $requestedSigils) {
        $db = Database::getInstance();
        if (!self::doesTableExist($db, 'sigil_theft_attempts')) {
            return ['error' => 'Sigil theft is unavailable until migrations are applied'];
        }

        $player = $db->fetch("SELECT * FROM players WHERE player_id = ?", [$playerId]);
        if (!$player['participation_enabled'] || !$player['joined_season_id']) {
            return ['error' => 'Not participating in any season'];
        }
        if ($player['idle_modal_active']) {
            return ['error' => 'Cannot perform actions while idle', 'reason_code' => 'idle_gated'];
        }

        $seasonId = (int)$player['joined_season_id'];
        $season = $db->fetch("SELECT * FROM seasons WHERE season_id = ?", [$seasonId]);
        $status = GameTime::getSeasonStatus($season);
        if ($status !== 'Active' && $status !== 'Blackout') {
            return ['error' => 'Sigil theft is only available during Active or Blackout phase'];
        }

        $targetPlayerId = (int)$targetPlayerId;
        if ($targetPlayerId <= 0 || $targetPlayerId === (int)$playerId) {
            return ['error' => 'Choose another player in your season'];
        }

        $target = $db->fetch(
            "SELECT player_id, handle FROM players WHERE player_id = ? AND joined_season_id = ? AND participation_enabled = 1",
            [$targetPlayerId, $seasonId]
        );
        if (!$target) {
            return ['error' => 'Target player not found in this active season'];
        }

        $spentSigils = self::normalizeSigilVector($spentSigils);
        $requestedSigils = self::normalizeSigilVector($requestedSigils);

        $spentCount = self::sumSigilVector($spentSigils, SIGIL_THEFT_SPEND_TIERS);
        if ($spentCount <= 0) {
            return ['error' => 'Select at least one ' . self::formatSigilTierList(SIGIL_THEFT_SPEND_TIERS) . ' sigil to spend'];
        }
        for ($tier = 1; $tier <= SIGIL_MAX_TIER; $tier++) {
            if (!in_array($tier, SIGIL_THEFT_SPEND_TIERS, true) && (int)($spentSigils[$tier - 1] ?? 0) > 0) {
                return ['error' => 'Only ' . self::formatSigilTierList(SIGIL_THEFT_SPEND_TIERS) . ' sigils can be spent on theft'];
            }
        }

        $requestedCount = self::sumSigilVector($requestedSigils, SIGIL_THEFT_TARGET_TIERS);
        if ($requestedCount <= 0) {
            return ['error' => 'Select at least one sigil to attempt to steal'];
        }
        for ($tier = 1; $tier <= SIGIL_MAX_TIER; $tier++) {
            if (!in_array($tier, SIGIL_THEFT_TARGET_TIERS, true) && (int)($requestedSigils[$tier - 1] ?? 0) > 0) {
                return ['error' => 'Requested loot contains an invalid sigil tier'];
            }
        }

        $spendValue = self::calculateSigilVectorValue($spentSigils, SIGIL_UTILITY_VALUE_BY_TIER);
        $requestedValue = self::calculateSigilVectorValue($requestedSigils, SIGIL_UTILITY_VALUE_BY_TIER);
        if ($requestedValue > $spendValue) {
            return ['error' => 'Requested loot value exceeds your theft spend value'];
        }

        $nowTick = GameTime::now();
        $seasonTick = GameTime::seasonTick((int)$season['start_time'], $nowTick);
        $cooldownExpires = $nowTick + (int)($status === 'Blackout' ? SIGIL_THEFT_BLACKOUT_COOLDOWN_TICKS : SIGIL_THEFT_COOLDOWN_TICKS);
        $protectionExpires = $nowTick + (int)($status === 'Blackout' ? SIGIL_THEFT_BLACKOUT_PROTECTION_TICKS : SIGIL_THEFT_PROTECTION_TICKS);
        $successChanceFp = self::calculateTheftSuccessChanceFp($spendValue, $requestedValue);
        $rollFp = random_int(1, FP_SCALE);
        $theftSuccess = $rollFp <= $successChanceFp;

        $db->beginTransaction();
        try {
            // Lock both participation rows in a deterministic order (ascending
            // player_id), not attacker-then-target. With the old fixed order, A
            // stealing from B locked (A,B) while B stealing from A concurrently
            // locked (B,A) - a textbook lock-order inversion that InnoDB resolves
            // by killing one with error 1213, surfacing to the player as a bare
            // "Sigil theft failed" with the attempt consumed.
            $attackerId = (int)$playerId;
            $targetPlayerId = (int)$target['player_id'];
            $lockOrder = [$attackerId, $targetPlayerId];
            sort($lockOrder, SORT_NUMERIC);

            $lockedRows = [];
            foreach ($lockOrder as $lockPlayerId) {
                $lockedRows[$lockPlayerId] = $db->fetch(
                    "SELECT * FROM season_participation WHERE player_id = ? AND season_id = ? FOR UPDATE",
                    [$lockPlayerId, $seasonId]
                );
            }
            $attackerParticipation = $lockedRows[$attackerId] ?? null;
            $targetParticipation = $lockedRows[$targetPlayerId] ?? null;

            if (!$attackerParticipation || !$targetParticipation) {
                $db->rollback();
                return ['error' => 'Both players must be active season participants'];
            }

            $currentCooldown = self::getTheftCooldownExpiresTick($db, $playerId, $seasonId);
            if ($currentCooldown >= $nowTick) {
                $db->rollback();
                return ['error' => 'Your theft cooldown is active'];
            }

            $currentProtection = self::getTheftProtectionExpiresTick($db, (int)$target['player_id'], $seasonId);
            $wardExpires = SigilFamilies::wardExpiresTick($db, (int)$target['player_id'], $seasonId);
            if ($currentProtection >= $nowTick || $wardExpires >= $nowTick) {
                $db->rollback();
                if ($wardExpires >= $nowTick && $wardExpires > $currentProtection) {
                    // Ward reports what it blocked (drama budget: the act, not the value).
                    $db->query(
                        "UPDATE active_wards SET blocked_count = blocked_count + 1
                         WHERE player_id = ? AND season_id = ? AND expires_tick >= ?",
                        [(int)$target['player_id'], $seasonId, $nowTick]
                    );
                    Notifications::create(
                        (int)$target['player_id'],
                        'ward_blocked',
                        'Ward Held',
                        $player['handle'] . "'s theft attempt broke against your Ward.",
                        ['event_key' => 'ward_block:' . $seasonId . ':' . (int)$target['player_id'] . ':' . $nowTick]
                    );
                    return ['error' => 'Target is warded'];
                }
                return ['error' => 'Target theft protection is active'];
            }

            foreach (SIGIL_THEFT_SPEND_TIERS as $tier) {
                $amount = max(0, (int)($spentSigils[$tier - 1] ?? 0));
                if ($amount > (int)($attackerParticipation['sigils_t' . $tier] ?? 0)) {
                    $db->rollback();
                    return ['error' => 'Insufficient Tier ' . $tier . ' Sigils'];
                }
            }

            foreach (SIGIL_THEFT_TARGET_TIERS as $tier) {
                $amount = max(0, (int)($requestedSigils[$tier - 1] ?? 0));
                if ($amount > (int)($targetParticipation['sigils_t' . $tier] ?? 0)) {
                    $db->rollback();
                    return ['error' => 'Requested sigils exceed target inventory'];
                }
            }

            $attackerProjected = $attackerParticipation;
            foreach (SIGIL_THEFT_SPEND_TIERS as $tier) {
                $col = 'sigils_t' . $tier;
                $attackerProjected[$col] = max(0, (int)$attackerProjected[$col] - (int)($spentSigils[$tier - 1] ?? 0));
            }
            if ($theftSuccess) {
                foreach (SIGIL_THEFT_TARGET_TIERS as $tier) {
                    $col = 'sigils_t' . $tier;
                    $attackerProjected[$col] = max(0, (int)$attackerProjected[$col] + (int)($requestedSigils[$tier - 1] ?? 0));
                }
            }

            $capError = self::validateSigilInventoryCaps($attackerProjected);
            if ($capError !== null) {
                $db->rollback();
                return ['error' => $capError];
            }

            foreach (SIGIL_THEFT_SPEND_TIERS as $tier) {
                $amount = max(0, (int)($spentSigils[$tier - 1] ?? 0));
                if ($amount <= 0) {
                    continue;
                }
                $col = 'sigils_t' . $tier;
                $db->query(
                    "UPDATE season_participation SET {$col} = {$col} - ? WHERE player_id = ? AND season_id = ?",
                    [$amount, $playerId, $seasonId]
                );
                // Larceny is the theft verb: spend larceny holdings first.
                SigilFamilies::syncSpendTier($db, $seasonId, $playerId, $tier, $amount, [SigilFamilies::LARCENY_ID]);
                self::logEconomyLedgerSigilChange($db, $nowTick, $seasonId, $seasonTick, $playerId, $tier, $amount, 'BURN', 'SigilTheftSpend');
            }

            $transferredSigils = array_fill(0, SIGIL_MAX_TIER, 0);
            if ($theftSuccess) {
                foreach (SIGIL_THEFT_TARGET_TIERS as $tier) {
                    $amount = max(0, (int)($requestedSigils[$tier - 1] ?? 0));
                    if ($amount <= 0) {
                        continue;
                    }

                    $col = 'sigils_t' . $tier;
                    $db->query(
                        "UPDATE season_participation SET {$col} = {$col} - ? WHERE player_id = ? AND season_id = ?",
                        [$amount, (int)$target['player_id'], $seasonId]
                    );
                    $db->query(
                        "UPDATE season_participation SET {$col} = {$col} + ? WHERE player_id = ? AND season_id = ?",
                        [$amount, $playerId, $seasonId]
                    );

                    $transferredSigils[$tier - 1] = $amount;
                    SigilFamilies::syncTransferTier($db, $seasonId, (int)$target['player_id'], $playerId, $tier, $amount);
                    self::logEconomyLedgerSigilChange($db, $nowTick, $seasonId, $seasonTick, $playerId, $tier, $amount, 'TRANSFER', 'SigilTheftTransferIn');
                    self::logEconomyLedgerSigilChange($db, $nowTick, $seasonId, $seasonTick, (int)$target['player_id'], $tier, $amount, 'TRANSFER', 'SigilTheftTransferOut');
                }
                SigilFamilies::emitEvent(
                    $db, $seasonId, $playerId, 'theft_success',
                    $player['handle'] . ' stole from ' . $target['handle'],
                    $nowTick
                );
            }

            $theftId = (int)$db->insert(
                "INSERT INTO sigil_theft_attempts
                 (season_id, attacker_player_id, target_player_id, spent_sigils, requested_sigils, transferred_sigils,
                  spend_value, requested_value, success_chance_fp, rng_roll_fp, result,
                  cooldown_expires_tick, protection_expires_tick, created_tick, resolved_tick)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $seasonId,
                    $playerId,
                    (int)$target['player_id'],
                    json_encode($spentSigils),
                    json_encode($requestedSigils),
                    json_encode($transferredSigils),
                    $spendValue,
                    $requestedValue,
                    $successChanceFp,
                    $rollFp,
                    $theftSuccess ? 'SUCCESS' : 'FAILED',
                    $cooldownExpires,
                    $protectionExpires,
                    $nowTick,
                    $nowTick,
                ]
            );

            $db->query(
                "UPDATE players SET last_activity_tick = ?, activity_state = 'Active', idle_modal_active = 0 WHERE player_id = ?",
                [$nowTick, $playerId]
            );

            $spentSummary = self::summarizeSigilVector($spentSigils);
            $lootSummary = self::summarizeSigilVector($requestedSigils);
            $transferredSummary = self::summarizeSigilVector($transferredSigils);

            // Near-miss reporting (drama budget): tension from information, zero value cost.
            $nearMiss = '';
            if (!$theftSuccess && SigilFamilies::active($db)) {
                $rollPct = (int)round($rollFp / 10000);
                $shortPp = max(1, (int)ceil(($rollFp - $successChanceFp) / 10000));
                $nearMiss = " Your attempt resolved at {$rollPct}% - {$shortPp} points short.";
            }

            Notifications::create(
                $playerId,
                $theftSuccess ? 'sigil_theft_success' : 'sigil_theft_failed',
                $theftSuccess ? 'Sigil Theft Succeeded' : 'Sigil Theft Failed',
                $theftSuccess
                    ? ('You stole ' . $transferredSummary . ' from ' . $target['handle'] . '.')
                    : ('You lost ' . $spentSummary . ' trying to steal ' . $lootSummary . ' from ' . $target['handle'] . '.' . $nearMiss),
                [
                    'event_key' => 'sigil_theft:' . $theftId . ':attacker',
                    'payload' => [
                        'theft_id' => $theftId,
                        'season_id' => $seasonId,
                        'target_player_id' => (int)$target['player_id'],
                        'result' => $theftSuccess ? 'SUCCESS' : 'FAILED',
                        'spent_sigils' => $spentSigils,
                        'requested_sigils' => $requestedSigils,
                        'transferred_sigils' => $transferredSigils,
                    ]
                ]
            );

            Notifications::create(
                (int)$target['player_id'],
                $theftSuccess ? 'sigil_theft_taken' : 'sigil_theft_defended',
                $theftSuccess ? 'Sigils Stolen' : 'Theft Defended',
                $theftSuccess
                    ? ($player['handle'] . ' stole ' . $transferredSummary . ' from you.')
                    : ($player['handle'] . ' spent ' . $spentSummary . ' trying to steal ' . $lootSummary . ' from you.'),
                [
                    'event_key' => 'sigil_theft:' . $theftId . ':target',
                    'payload' => [
                        'theft_id' => $theftId,
                        'season_id' => $seasonId,
                        'attacker_player_id' => $playerId,
                        'result' => $theftSuccess ? 'SUCCESS' : 'FAILED',
                        'spent_sigils' => $spentSigils,
                        'requested_sigils' => $requestedSigils,
                        'transferred_sigils' => $transferredSigils,
                    ]
                ]
            );

            $db->commit();

            return [
                'success' => true,
                'theft_id' => $theftId,
                'theft_success' => $theftSuccess,
                'target_player_id' => (int)$target['player_id'],
                'target_handle' => (string)$target['handle'],
                'spent_sigils' => $spentSigils,
                'requested_sigils' => $requestedSigils,
                'transferred_sigils' => $transferredSigils,
                'spend_value' => $spendValue,
                'requested_value' => $requestedValue,
                'success_chance_fp' => $successChanceFp,
                'rng_roll_fp' => $rollFp,
                'cooldown_expires_tick' => $cooldownExpires,
                'protection_expires_tick' => $protectionExpires,
                'message' => $theftSuccess
                    ? ('Theft succeeded. You stole ' . $transferredSummary . ' from ' . $target['handle'] . '.')
                    : ('Theft failed. You lost ' . $spentSummary . ' and ' . $target['handle'] . ' is now protected.'),
            ];
        } catch (Exception $e) {
            $db->rollback();
            return ['error' => 'Sigil theft failed'];
        }
    }
    
    /**
     * Acknowledge idle modal (return to Active)
     */
    public static function idleAck($playerId) {
        $db = Database::getInstance();
        $player = $db->fetch("SELECT * FROM players WHERE player_id = ?", [$playerId]);
        
        if (!$player['idle_modal_active']) {
            return ['error' => 'Not idle'];
        }
        
        $gameTime = GameTime::now();
        $joinedSeasonId = isset($player['joined_season_id']) ? (int)$player['joined_season_id'] : 0;

        if ($joinedSeasonId > 0) {
            $participation = $db->fetch(
                "SELECT coins FROM season_participation WHERE player_id = ? AND season_id = ?",
                [$playerId, $joinedSeasonId]
            );
            if ($participation) {
                $db->query(
                    "UPDATE season_participation
                     SET reactivation_balance_snapshot = ?, reactivation_start_tick = ?
                     WHERE player_id = ? AND season_id = ?",
                    [max(0, (int)($participation['coins'] ?? 0)), $gameTime, $playerId, $joinedSeasonId]
                );
            }
        }

        $db->query(
            "UPDATE players SET 
             idle_modal_active = 0, activity_state = 'Active',
             idle_since_tick = NULL, last_activity_tick = ?, online_current = 1, last_seen_at = NOW()
             WHERE player_id = ?",
            [$gameTime, $playerId]
        );

        Notifications::create(
            $playerId,
            'active',
            'You are active again',
            'Welcome back. Your participation is active.',
            [
                'is_read' => true,
                'event_key' => 'active:' . $gameTime,
                'payload' => ['at_tick' => (int)$gameTime]
            ]
        );
        
        return ['success' => true, 'message' => 'Welcome back! You are now Active.'];
    }
    
    /**
     * Purchase a cosmetic with Global Stars
     */
    public static function purchaseCosmetic($playerId, $cosmeticId) {
        $db = Database::getInstance();
        $player = $db->fetch("SELECT * FROM players WHERE player_id = ?", [$playerId]);
        
        $cosmetic = $db->fetch("SELECT * FROM cosmetic_catalog WHERE cosmetic_id = ?", [$cosmeticId]);
        if (!$cosmetic) return ['error' => 'Cosmetic not found'];
        
        $price = (int)$cosmetic['price_global_stars'];
        if ($player['global_stars'] < $price) {
            return ['error' => 'Insufficient Global Stars'];
        }
        
        // Check if already owned
        $owned = $db->fetch(
            "SELECT * FROM player_cosmetics WHERE player_id = ? AND cosmetic_id = ?",
            [$playerId, $cosmeticId]
        );
        if ($owned) return ['error' => 'Already owned'];
        
        $db->beginTransaction();
        try {
            // Guarded: the affordability check above runs outside this
            // transaction, so concurrent purchases of different cosmetics would
            // each pass it and each commit, spending the same Global Stars more
            // than once. global_stars is a signed BIGINT, so it went negative.
            $charged = $db->query(
                "UPDATE players SET global_stars = global_stars - ?
                 WHERE player_id = ? AND global_stars >= ?",
                [$price, $playerId, $price]
            )->rowCount();
            if ($charged !== 1) {
                $db->rollback();
                return ['error' => 'Insufficient Global Stars'];
            }
            $db->query(
                "INSERT INTO player_cosmetics (player_id, cosmetic_id) VALUES (?, ?)",
                [$playerId, $cosmeticId]
            );
            $db->commit();
            return ['success' => true, 'message' => 'Cosmetic purchased!'];
        } catch (Exception $e) {
            $db->rollback();
            return ['error' => 'Purchase failed'];
        }
    }
    
    /**
     * Equip/unequip a cosmetic
     */
    public static function equipCosmetic($playerId, $cosmeticId, $equip = true) {
        $db = Database::getInstance();
        
        // Get cosmetic category
        $cosmetic = $db->fetch(
            "SELECT c.category FROM player_cosmetics pc 
             JOIN cosmetic_catalog c ON c.cosmetic_id = pc.cosmetic_id
             WHERE pc.player_id = ? AND pc.cosmetic_id = ?",
            [$playerId, $cosmeticId]
        );
        if (!$cosmetic) return ['error' => 'Cosmetic not owned'];
        
        if ($equip) {
            // Unequip others in same category
            $db->query(
                "UPDATE player_cosmetics pc 
                 JOIN cosmetic_catalog c ON c.cosmetic_id = pc.cosmetic_id
                 SET pc.equipped = 0
                 WHERE pc.player_id = ? AND c.category = ?",
                [$playerId, $cosmetic['category']]
            );
        }
        
        $db->query(
            "UPDATE player_cosmetics SET equipped = ? WHERE player_id = ? AND cosmetic_id = ?",
            [$equip ? 1 : 0, $playerId, $cosmeticId]
        );
        
        return ['success' => true];
    }
    
    /**
     * Spend one sigil to modify unified boost state.
     * - If no boost is active: initialize power/duration from selected tier.
        * - If active: apply a power boost or time extension from the selected tier.
     */
    public static function purchaseBoost($playerId, $sigilTier, $purchaseKind = 'power', $boostId = null) {
        $db = Database::getInstance();
        $player = $db->fetch("SELECT * FROM players WHERE player_id = ?", [$playerId]);
        
        if (!$player['participation_enabled'] || !$player['joined_season_id']) {
            return ['error' => 'Not participating in any season'];
        }
        if ($player['idle_modal_active']) {
            return ['error' => 'Cannot perform actions while idle', 'reason_code' => 'idle_gated'];
        }
        
        $seasonId = $player['joined_season_id'];
        $season = $db->fetch("SELECT * FROM seasons WHERE season_id = ?", [$seasonId]);
        
        $status = GameTime::getSeasonStatus($season);
        if ($status !== 'Active' && $status !== 'Blackout') {
            return ['error' => 'Season is not active'];
        }
        
        $purchaseKind = strtolower(trim((string)$purchaseKind));
        $sigilTier = (int)$sigilTier;

        if (!BoostCatalog::canSpendSigilTier($sigilTier)) {
            if ($sigilTier === 6) {
                return ['error' => 'Tier 6 sigils cannot be used for power boosts or time extensions'];
            }
            return ['error' => 'Invalid sigil tier'];
        }

        $purchaseKind = strtolower(trim((string)$purchaseKind));
        if ($purchaseKind !== 'power' && $purchaseKind !== 'time') {
            return ['error' => 'Invalid boost purchase kind'];
        }

        $sigilCost = 1;
        $scope = 'SELF';
        $productPowerCapFp = BoostCatalog::POWER_CAP_FP_PER_PRODUCT;
        $totalPowerCapFp = BoostCatalog::TOTAL_POWER_CAP_FP;
        $timeCapTicks = BoostCatalog::getTimeCapTicks();
        $recoveryTicks = BoostCatalog::getRecoveryTicks();
        $recoveryLabel = ((int)BoostCatalog::RECOVERY_SECONDS_AFTER_SESSION % 3600 === 0)
            ? ((int)(BoostCatalog::RECOVERY_SECONDS_AFTER_SESSION / 3600) . 'h')
            : ((int)BoostCatalog::RECOVERY_SECONDS_AFTER_SESSION . 's');
        $recoveryError = "Boost is recovering ({$recoveryLabel})";
        $powerIncrementFp = max(1, BoostCatalog::getSpendPowerFpForTier($sigilTier));
        $timeIncrementTicks = max(1, BoostCatalog::getSpendTimeTicksForTier($sigilTier));
        $timeIncrementRealSeconds = max(1, BoostCatalog::getSpendTimeRealSecondsForTier($sigilTier));
        $initialPowerFp = max(1, BoostCatalog::getInitialPowerFpForTier($sigilTier));
        $initialDurationTicks = max(1, BoostCatalog::getInitialDurationTicksForTier($sigilTier));
        $initialDurationRealSeconds = max(1, BoostCatalog::getInitialDurationRealSecondsForTier($sigilTier));

        $catalogByTier = $db->fetch(
            "SELECT * FROM boost_catalog WHERE tier_required = ? ORDER BY boost_id ASC LIMIT 1",
            [$sigilTier]
        );
        if (!$catalogByTier) {
            return ['error' => 'Boost catalog unavailable for selected sigil tier'];
        }
        $catalogByTier = BoostCatalog::normalize($catalogByTier);
        $resolvedBoostId = (int)$catalogByTier['boost_id'];
        $resolvedBoostName = (string)($catalogByTier['name'] ?? ('Tier ' . $sigilTier . ' Boost'));

        // Get participation
        $participation = $db->fetch(
            "SELECT * FROM season_participation WHERE player_id = ? AND season_id = ?",
            [$playerId, $seasonId]
        );

        // Check sigil inventory for the selected spend tier.
        $sigilCol = "sigils_t{$sigilTier}";
        if ((int)$participation[$sigilCol] < $sigilCost) {
            return ['error' => "Insufficient Tier {$sigilTier} Sigils. Need {$sigilCost}, have {$participation[$sigilCol]}"];
        }

        // With families on, power is the Yield verb and time is the Time verb:
        // the spend must come from that family (wildcards substitute). The
        // modifier ceiling also tightens to the families cap.
        $familiesActive = SigilFamilies::active($db);
        $verbFamilyId = ($purchaseKind === 'time') ? SigilFamilies::TIME_ID : SigilFamilies::YIELD_ID;
        if ($familiesActive) {
            if (SigilFamilies::spendableCount($db, $seasonId, $playerId, $verbFamilyId, $sigilTier) < $sigilCost) {
                $verbName = SigilFamilies::familyName($verbFamilyId);
                return ['error' => "This spend needs a {$verbName} sigil of Tier {$sigilTier} (or a Wildcard)"];
            }
            $totalPowerCapFp = min($totalPowerCapFp, (int)CAPS_MODIFIER_CEILING_PCT * 10000);
        }

        // Load currently-active SELF boost rows (legacy-safe; collapse to one canonical row).
        $gameTime = GameTime::now();
        $activeRows = $db->fetchAll(
            "SELECT * FROM active_boosts
             WHERE player_id = ? AND season_id = ? AND scope = 'SELF' AND is_active = 1 AND expires_tick >= ?
               AND (activated_tick <= 0 OR activated_tick + ? >= ?)
             ORDER BY expires_tick DESC, id ASC",
            [$playerId, $seasonId, $gameTime, $timeCapTicks, $gameTime]
        );

        $active = count($activeRows) > 0 ? $activeRows[0] : null;
        $extraRows = count($activeRows) > 1 ? array_slice($activeRows, 1) : [];

        $currentBoostTotalFp = 0;
        foreach ($activeRows as $row) {
            $currentBoostTotalFp += max(0, (int)($row['modifier_fp'] ?? 0));
        }

            $combinedRow = $db->fetch(
                "SELECT COALESCE(SUM(modifier_fp), 0) AS total_fp
                 FROM active_boosts
                 WHERE season_id = ? AND is_active = 1 AND expires_tick >= ?
                   AND (activated_tick <= 0 OR activated_tick + ? >= ?)
                   AND scope = 'SELF' AND player_id = ?",
                [$seasonId, $gameTime, $timeCapTicks, $gameTime, $playerId]
            );
        $combinedCurrentFp = max(0, (int)($combinedRow['total_fp'] ?? 0));

        $currentModifier = max(0, (int)($active['modifier_fp'] ?? 0));
        $currentActivatedTick = max(0, (int)($active['activated_tick'] ?? 0));
        $currentRawExpiresTick = max($gameTime, (int)($active['expires_tick'] ?? 0));
        if ($active && $currentActivatedTick <= 0) {
            $currentActivatedTick = max(0, $currentRawExpiresTick - $timeCapTicks);
        }
        $currentExpiresTick = $active
            ? BoostCatalog::getEffectiveExpiresTick($currentRawExpiresTick, $currentActivatedTick)
            : $currentRawExpiresTick;
        $maxExpiresTick = $active
            ? BoostCatalog::getSessionMaxExpiresTick($currentActivatedTick)
            : ($gameTime + $timeCapTicks);

        if (!$active) {
            $recentSelfBoosts = $db->fetchAll(
                "SELECT expires_tick, activated_tick FROM active_boosts
                 WHERE player_id = ? AND season_id = ? AND scope = 'SELF'
                 ORDER BY id DESC
                 LIMIT 50",
                [$playerId, $seasonId]
            );
            $latestExpiresTick = 0;
            foreach ($recentSelfBoosts as $row) {
                $latestExpiresTick = max(
                    $latestExpiresTick,
                    BoostCatalog::getEffectiveExpiresTick(
                        (int)($row['expires_tick'] ?? 0),
                        (int)($row['activated_tick'] ?? 0)
                    )
                );
            }
            if ($latestExpiresTick > 0 && $latestExpiresTick < $gameTime && BoostCatalog::isRecoveringAfterSession($latestExpiresTick, $gameTime)) {
                return [
                    'error' => $recoveryError,
                    'reason_code' => 'boost_recovery',
                    'recovery_until_tick' => BoostCatalog::getRecoveryUntilTick($latestExpiresTick),
                    'recovery_ticks' => $recoveryTicks,
                ];
            }
        }

        if ($active && $purchaseKind === 'power') {
            $projectedModifier = min($productPowerCapFp, $currentModifier + $powerIncrementFp);
            $projectedCombinedFp = $combinedCurrentFp - $currentBoostTotalFp + $projectedModifier;
            if ($projectedCombinedFp > $totalPowerCapFp || $projectedModifier <= $currentModifier) {
                return ['error' => 'Boost power cap reached (100% per active boost)'];
            }
        }
        if ($active && $purchaseKind === 'time') {
            if ($currentExpiresTick >= $maxExpiresTick) {
                return ['error' => 'Maximum boost session time reached'];
            }
        }
        
        $db->beginTransaction();
        try {
            // Consume sigil for this spend.
            // Guarded: concurrent purchases previously each read the same
            // pre-state, each consumed the same sigil, and each stacked boost
            // power past the per-product cap.
            $spent = $db->query(
                "UPDATE season_participation SET {$sigilCol} = {$sigilCol} - ?
                 WHERE player_id = ? AND season_id = ? AND {$sigilCol} >= ?",
                [$sigilCost, $playerId, $seasonId, $sigilCost]
            )->rowCount();
            if ($spent !== 1) {
                throw new Exception('You do not own the selected sigil tier');
            }
            if ($familiesActive) {
                SigilFamilies::spendSpecific($db, $seasonId, $playerId, $verbFamilyId, $sigilTier, $sigilCost);
            }

            if (!$active) {
                $newModifier = $initialPowerFp;
                $expiresTick = $gameTime + $initialDurationTicks;
                $projectedCombinedFp = $combinedCurrentFp - $currentBoostTotalFp + $newModifier;
                if ($projectedCombinedFp > $totalPowerCapFp) {
                    throw new Exception('Total boost cap reached (500% combined)');
                }

                $db->query(
                    "INSERT INTO active_boosts (player_id, season_id, boost_id, scope, modifier_fp, activated_tick, expires_tick, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 1)",
                    [$playerId, $seasonId, $resolvedBoostId, $scope, $newModifier, $gameTime, $expiresTick]
                );
            } else {
                if ($purchaseKind === 'power') {
                    $newModifier = min($productPowerCapFp, $currentModifier + $powerIncrementFp);
                    $projectedCombinedFp = $combinedCurrentFp - $currentBoostTotalFp + $newModifier;
                    if ($projectedCombinedFp > $totalPowerCapFp || $newModifier <= $currentModifier) {
                        throw new Exception('Boost power cap reached (100% per active boost)');
                    }
                    $expiresTick = $currentExpiresTick;
                } else {
                    $newModifier = $currentModifier;
                    if ($familiesActive) {
                        // Time family: duration is derived, never authored -
                        // added time = VP x 0.1h / (active modifier / 100).
                        $derivedTicks = SigilFamilies::derivedTimeExtensionTicks($sigilTier, $currentModifier);
                        if ($derivedTicks > 0) {
                            $timeIncrementTicks = $derivedTicks;
                        }
                    }
                    $expiresTick = min($maxExpiresTick, $currentExpiresTick + $timeIncrementTicks);
                    if ($expiresTick <= $currentExpiresTick) {
                        throw new Exception('Maximum boost session time reached');
                    }
                }

                $db->query(
                    "UPDATE active_boosts
                     SET boost_id = ?, modifier_fp = ?, expires_tick = ?, activated_tick = ?, is_active = 1
                     WHERE id = ?",
                    [$resolvedBoostId, $newModifier, $expiresTick, $currentActivatedTick, (int)$active['id']]
                );
            }

            // Deactivate legacy duplicate SELF rows.
            foreach ($extraRows as $row) {
                $db->query("UPDATE active_boosts SET is_active = 0 WHERE id = ?", [(int)$row['id']]);
            }
            
            // Update activity
            $db->query(
                "UPDATE players SET last_activity_tick = ?, activity_state = 'Active', idle_modal_active = 0 WHERE player_id = ?",
                [$gameTime, $playerId]
            );
            
            $db->commit();
            
            $scopeLabel = ($scope === 'GLOBAL') ? 'all players in the season' : 'you';
            $modifierPercent = round($newModifier / 10000, 1);
            $didInitialize = !$active;
            $purchasedPowerPercent = ($purchaseKind === 'power')
                ? round((($didInitialize ? $initialPowerFp : $powerIncrementFp) / 10000), 1)
                : 0;
            $purchasedTimeRealSeconds = ($purchaseKind === 'time')
                ? ($didInitialize ? $initialDurationRealSeconds : $timeIncrementRealSeconds)
                : 0;
            return [
                'success' => true,
                'boost_name' => $resolvedBoostName,
                'boost_id' => $resolvedBoostId,
                'purchase_kind' => $purchaseKind,
                'scope' => $scope,
                'sigil_tier' => $sigilTier,
                'modifier_percent' => $modifierPercent,
                'purchased_power_percent' => $purchasedPowerPercent,
                'purchased_time_real_seconds' => $purchasedTimeRealSeconds,
                'stack_count' => 0,
                'max_stack' => 0,
                'power_cap_fp' => $productPowerCapFp,
                'total_power_cap_fp' => $totalPowerCapFp,
                'time_cap_ticks' => $timeCapTicks,
                'recovery_ticks' => $recoveryTicks,
                'duration_ticks' => $didInitialize ? $initialDurationTicks : max(0, $expiresTick - $gameTime),
                'time_extension_ticks' => $didInitialize ? $initialDurationTicks : ($purchaseKind === 'time' ? $timeIncrementTicks : 0),
                'time_extension_real_seconds' => $didInitialize ? $initialDurationRealSeconds : ($purchaseKind === 'time' ? $timeIncrementRealSeconds : 0),
                'expires_tick' => $expiresTick,
                'sigils_consumed' => $sigilCost,
                'tier_consumed' => $sigilTier,
                'time_sigil_tier_used' => ($purchaseKind === 'time') ? $sigilTier : null,
                'initialized_from_inactive' => $didInitialize,
                'message' => $didInitialize
                    ? "Boost activated from Tier {$sigilTier}. Total UBI +{$modifierPercent}% for {$scopeLabel}."
                    : (($purchaseKind === 'power')
                        ? "Boost power increased by Tier {$sigilTier}. Total UBI +{$modifierPercent}% for {$scopeLabel}."
                        : "Boost time extended by Tier {$sigilTier} sigil power.")
            ];
        } catch (Exception $e) {
            $db->rollback();
            $msg = $e->getMessage();
            if ($msg === 'Boost power cap reached (100% per active boost)') {
                return ['error' => 'Boost power cap reached (100% per active boost)'];
            }
            if ($msg === 'Total boost cap reached (500% combined)') {
                return ['error' => 'Total boost cap reached (500% combined)'];
            }
            if ($msg === 'Maximum boost session time reached') {
                return ['error' => 'Maximum boost session time reached'];
            }
            return ['error' => 'Boost activation failed: ' . $msg];
        }
    }

    /**
     * Combine same-tier sigils into the next tier.
     */
    public static function combineSigils($playerId, $fromTier, $familyCode = null) {
        $db = Database::getInstance();
        $player = $db->fetch("SELECT * FROM players WHERE player_id = ?", [$playerId]);

        if (!$player['participation_enabled'] || !$player['joined_season_id']) {
            return ['error' => 'Not participating in any season'];
        }
        if ($player['idle_modal_active']) {
            return ['error' => 'Cannot perform actions while idle', 'reason_code' => 'idle_gated'];
        }

        $seasonId = (int)$player['joined_season_id'];
        $season = $db->fetch("SELECT * FROM seasons WHERE season_id = ?", [$seasonId]);
        $status = GameTime::getSeasonStatus($season);
        if ($status !== 'Active') {
            return ['error' => 'Combining sigils is only available during active season'];
        }

        $fromTier = (int)$fromTier;
        if ($fromTier < 1 || $fromTier >= SIGIL_MAX_TIER) {
            return ['error' => 'Invalid source tier'];
        }

        $required = (int)(SIGIL_COMBINE_RECIPES[$fromTier] ?? 0);
        if ($required <= 0) {
            return ['error' => 'This combine recipe is unavailable'];
        }

        $toTier = $fromTier + 1;
        $fromCol = 'sigils_t' . $fromTier;
        $toCol = 'sigils_t' . $toTier;

        $participation = $db->fetch(
            "SELECT * FROM season_participation WHERE player_id = ? AND season_id = ?",
            [$playerId, $seasonId]
        );

        $owned = (int)($participation[$fromCol] ?? 0);
        if ($owned < $required) {
            return ['error' => "Insufficient Tier {$fromTier} Sigils. Need {$required}, have {$owned}"];
        }
        if (!Economy::canReceiveSigilTier($participation, $toTier, 1, $required)) {
            return ['error' => "Tier {$toTier} sigil inventory cap reached"];
        }

        // With families on, Ascend is family-constrained: the inputs come from
        // one family (wildcards substitute) and the output stays in it.
        $familiesActive = SigilFamilies::active($db);
        $ascendFamilyId = null;
        if ($familiesActive) {
            $requestedFamilyId = $familyCode !== null ? SigilFamilies::familyIdByCode($familyCode) : null;
            $candidateIds = $requestedFamilyId !== null
                ? [$requestedFamilyId]
                : [SigilFamilies::YIELD_ID, SigilFamilies::TIME_ID, SigilFamilies::WARD_ID,
                   SigilFamilies::LARCENY_ID, SigilFamilies::MARKET_ID, SigilFamilies::WILD_ID];
            $bestCount = -1;
            foreach ($candidateIds as $candidateId) {
                if (in_array($candidateId, [SigilFamilies::SIGHT_ID, null], true)) {
                    continue;
                }
                $count = SigilFamilies::spendableCount($db, $seasonId, $playerId, $candidateId, $fromTier);
                if ($count >= $required && $count > $bestCount) {
                    $ascendFamilyId = $candidateId;
                    $bestCount = $count;
                }
            }
            if ($ascendFamilyId === null) {
                return ['error' => "Ascend needs {$required} same-family Tier {$fromTier} sigils (wildcards substitute)"];
            }
            if ($toTier === 6 && SigilFamilies::holdingCount($db, $seasonId, $playerId, $ascendFamilyId, 6) >= 1) {
                return ['error' => 'One Tier 6 per family per season'];
            }
        }

        $db->beginTransaction();
        try {
            // Guarded so concurrent combines cannot each consume the same sigils
            // and each mint an output (the sigil columns are signed INTs).
            $consumed = $db->query(
                "UPDATE season_participation
                 SET {$fromCol} = {$fromCol} - ?, {$toCol} = {$toCol} + 1
                 WHERE player_id = ? AND season_id = ? AND {$fromCol} >= ?",
                [$required, $playerId, $seasonId, $required]
            )->rowCount();
            if ($consumed !== 1) {
                $db->rollback();
                return ['error' => 'Not enough sigils'];
            }
            if ($familiesActive && $ascendFamilyId !== null) {
                SigilFamilies::spendSpecific($db, $seasonId, $playerId, $ascendFamilyId, $fromTier, $required);
                SigilFamilies::addHolding($db, $seasonId, $playerId, $ascendFamilyId, $toTier, 1);
            }

            $db->query(
                "UPDATE players SET last_activity_tick = ?, activity_state = 'Active', idle_modal_active = 0 WHERE player_id = ?",
                [GameTime::now(), $playerId]
            );

            $db->commit();
            $familyLabel = $ascendFamilyId !== null ? (SigilFamilies::familyName($ascendFamilyId) . ' ') : '';
            return [
                'success' => true,
                'from_tier' => $fromTier,
                'to_tier' => $toTier,
                'family_code' => $ascendFamilyId !== null ? SigilFamilies::familyCode($ascendFamilyId) : null,
                'consumed' => $required,
                'produced' => 1,
                'message' => "Combined {$required} {$familyLabel}Tier {$fromTier} sigils into 1 {$familyLabel}Tier {$toTier} sigil."
            ];
        } catch (Exception $e) {
            $db->rollback();
            return ['error' => 'Sigil combine failed'];
        }
    }

    /**
     * Combine all available sigils, including chain reactions across tiers.
     */
    public static function combineAllSigils($playerId) {
        $maxOperations = 500;
        $operations = [];
        $totalConsumed = 0;
        $totalProduced = 0;
        $operationCount = 0;

        // Re-scan all source tiers until we complete a full pass with no combines.
        while ($operationCount < $maxOperations) {
            $didCombineThisPass = false;

            foreach (SIGIL_COMBINE_RECIPES as $fromTier => $required) {
                while ($operationCount < $maxOperations) {
                    $result = self::combineSigils($playerId, (int)$fromTier);
                    if (!empty($result['error'])) {
                        break;
                    }

                    $didCombineThisPass = true;
                    $operationCount++;

                    $consumed = (int)($result['consumed'] ?? 0);
                    $produced = (int)($result['produced'] ?? 0);
                    $toTier = (int)($result['to_tier'] ?? ((int)$fromTier + 1));

                    $totalConsumed += $consumed;
                    $totalProduced += $produced;
                    $operations[] = [
                        'from_tier' => (int)$fromTier,
                        'to_tier' => $toTier,
                        'consumed' => $consumed,
                        'produced' => $produced,
                    ];
                }
            }

            if (!$didCombineThisPass) {
                break;
            }
        }

        if ($operationCount === 0) {
            return ['error' => 'No sigil combinations currently available'];
        }

        $summary = "Combined {$totalConsumed} sigils into {$totalProduced} higher-tier sigils across {$operationCount} operation";
        if ($operationCount !== 1) {
            $summary .= 's';
        }
        $summary .= '.';

        return [
            'success' => true,
            'total_operations' => $operationCount,
            'total_consumed' => $totalConsumed,
            'total_produced' => $totalProduced,
            'operations' => $operations,
            'hit_operation_limit' => $operationCount >= $maxOperations,
            'message' => $summary,
        ];
    }

    /**
     * Consume a configured utility sigil to freeze another player's UBI accrual to 0/tick.
     */
    public static function freezePlayerUbi($playerId, $targetPlayerId = null, $targetHandle = null, $requestedTier = null) {
        $db = Database::getInstance();
        $player = $db->fetch("SELECT * FROM players WHERE player_id = ?", [$playerId]);

        if (!$player['participation_enabled'] || !$player['joined_season_id']) {
            return ['error' => 'Not participating in any season'];
        }
        if ($player['idle_modal_active']) {
            return ['error' => 'Cannot perform actions while idle', 'reason_code' => 'idle_gated'];
        }

        $seasonId = (int)$player['joined_season_id'];
        $season = $db->fetch("SELECT * FROM seasons WHERE season_id = ?", [$seasonId]);
        $status = GameTime::getSeasonStatus($season);
        if ($status !== 'Active' && $status !== 'Blackout') {
            return ['error' => 'Freeze is only available during Active or Blackout phase'];
        }

        $target = null;
        $targetPlayerId = (int)$targetPlayerId;
        if ($targetPlayerId > 0) {
            $target = $db->fetch(
                "SELECT player_id, handle FROM players WHERE player_id = ? AND joined_season_id = ? AND participation_enabled = 1",
                [$targetPlayerId, $seasonId]
            );
        } elseif ($targetHandle !== null && trim((string)$targetHandle) !== '') {
            $target = $db->fetch(
                "SELECT player_id, handle FROM players WHERE handle_lower = ? AND joined_season_id = ? AND participation_enabled = 1",
                [strtolower(trim((string)$targetHandle)), $seasonId]
            );
        }

        if (!$target) {
            return ['error' => 'Target player not found in this active season'];
        }
        if ((int)$target['player_id'] === (int)$playerId) {
            return ['error' => 'You cannot freeze yourself'];
        }

        $freezeSpendTiers = self::normalizeSigilTierList(SIGIL_FREEZE_SPEND_TIERS);
        if ($freezeSpendTiers === []) {
            return ['error' => 'Freeze is not configured'];
        }
        $sigilColumns = implode(', ', array_map(static fn($tier) => 'sigils_t' . (int)$tier, $freezeSpendTiers));
        $participation = $db->fetch(
            "SELECT {$sigilColumns} FROM season_participation WHERE player_id = ? AND season_id = ?",
            [$playerId, $seasonId]
        );

        $requestedTier = ($requestedTier === null || $requestedTier === '') ? null : (int)$requestedTier;
        if ($requestedTier === null) {
            $requestedTier = self::selectOwnedSigilTier((array)$participation, $freezeSpendTiers, true);
        }

        if (!in_array((int)$requestedTier, $freezeSpendTiers, true)) {
            return ['error' => 'Freeze requires a ' . self::formatSigilTierList($freezeSpendTiers) . ' sigil'];
        }

        $sigilCol = 'sigils_t' . (int)$requestedTier;
        if ((int)($participation[$sigilCol] ?? 0) < 1) {
            return ['error' => 'You do not own the selected sigil tier for Freeze'];
        }

        $nowTick = GameTime::now();
        $existing = $db->fetch(
            "SELECT freeze_id, expires_tick FROM active_freezes
             WHERE season_id = ? AND target_player_id = ? AND is_active = 1 AND expires_tick >= ?
             ORDER BY expires_tick DESC LIMIT 1",
            [$seasonId, (int)$target['player_id'], $nowTick]
        );

        $durationMap = (array)($status === 'Blackout' ? SIGIL_FREEZE_BLACKOUT_DURATION_TICKS_BY_TIER : SIGIL_FREEZE_DURATION_TICKS_BY_TIER);
        $stackMap = (array)($status === 'Blackout' ? SIGIL_FREEZE_BLACKOUT_STACK_EXTENSION_TICKS_BY_TIER : SIGIL_FREEZE_STACK_EXTENSION_TICKS_BY_TIER);
        $freezeBaseTicks = (int)($durationMap[(int)$requestedTier] ?? FREEZE_BASE_DURATION_TICKS);
        $freezeStackTicks = (int)($stackMap[(int)$requestedTier] ?? FREEZE_STACK_EXTENSION_TICKS);
        $newRemaining = $freezeBaseTicks;
        if ($existing) {
            // Flat extension: add stack ticks to the current expiry (preserving
            // remaining duration), so each additional freeze predictably extends the timer.
            $existingExpires = (int)$existing['expires_tick'];
            $newExpires = max($existingExpires, $nowTick) + $freezeStackTicks;
            $newRemaining = $newExpires - $nowTick;
        } else {
            $newExpires = $nowTick + $newRemaining;
        }

        $db->beginTransaction();
        try {
            $spent = $db->query(
                "UPDATE season_participation SET {$sigilCol} = {$sigilCol} - 1
                 WHERE player_id = ? AND season_id = ? AND {$sigilCol} >= 1",
                [$playerId, $seasonId]
            )->rowCount();
            if ($spent !== 1) {
                $db->rollback();
                return ['error' => 'You do not own the selected sigil tier'];
            }

            if ($existing) {
                $db->query(
                    "UPDATE active_freezes
                     SET source_player_id = ?, activated_tick = ?, expires_tick = ?, applied_count = applied_count + 1, is_active = 1
                     WHERE freeze_id = ?",
                    [$playerId, $nowTick, $newExpires, (int)$existing['freeze_id']]
                );
            } else {
                $db->query(
                    "INSERT INTO active_freezes
                     (source_player_id, target_player_id, season_id, activated_tick, expires_tick, applied_count, is_active)
                     VALUES (?, ?, ?, ?, ?, 1, 1)",
                    [$playerId, (int)$target['player_id'], $seasonId, $nowTick, $newExpires]
                );
            }

            $db->query(
                "UPDATE players SET last_activity_tick = ?, activity_state = 'Active', idle_modal_active = 0 WHERE player_id = ?",
                [$nowTick, $playerId]
            );

            $db->commit();

            return [
                'success' => true,
                'target_player_id' => (int)$target['player_id'],
                'target_handle' => (string)$target['handle'],
                'consumed_tier' => (int)$requestedTier,
                'freeze_duration_ticks' => $newRemaining,
                'expires_tick' => $newExpires,
                'message' => 'Tier ' . (int)$requestedTier . ' Freeze applied to ' . $target['handle'] . ' for ' . $newRemaining . ' ticks.'
            ];
        } catch (Exception $e) {
            $db->rollback();
            return ['error' => 'Freeze action failed'];
        }
    }

    /**
     * Consume a Tier 5 or Tier 6 sigil to reduce your own active freeze.
     */
    public static function selfMeltFreeze($playerId, $requestedTier = null) {
        $db = Database::getInstance();
        $player = $db->fetch("SELECT * FROM players WHERE player_id = ?", [$playerId]);

        if (!$player['participation_enabled'] || !$player['joined_season_id']) {
            return ['error' => 'Not participating in any season'];
        }

        $seasonId = (int)$player['joined_season_id'];
        $season = $db->fetch("SELECT * FROM seasons WHERE season_id = ?", [$seasonId]);
        $status = GameTime::getSeasonStatus($season);
        if ($status !== 'Active' && $status !== 'Blackout') {
            return ['error' => 'Melt is only available during the season'];
        }

        $participation = $db->fetch(
            "SELECT sigils_t5, sigils_t6 FROM season_participation WHERE player_id = ? AND season_id = ?",
            [$playerId, $seasonId]
        );

        $requestedTier = ($requestedTier === null || $requestedTier === '') ? null : (int)$requestedTier;
        if ($requestedTier === null) {
            if ((int)($participation['sigils_t6'] ?? 0) > 0) {
                $requestedTier = 6;
            } elseif ((int)($participation['sigils_t5'] ?? 0) > 0) {
                $requestedTier = 5;
            }
        }

        if (!in_array((int)$requestedTier, SIGIL_MELT_SPEND_TIERS, true)) {
            return ['error' => 'Melt requires a Tier 5 or Tier 6 sigil'];
        }

        $sigilCol = 'sigils_t' . (int)$requestedTier;
        if ((int)($participation[$sigilCol] ?? 0) < 1) {
            return ['error' => 'You do not own the selected sigil tier for Melt'];
        }

        $nowTick = GameTime::now();
        $existing = $db->fetch(
            "SELECT freeze_id, expires_tick FROM active_freezes
             WHERE season_id = ? AND target_player_id = ? AND is_active = 1 AND expires_tick >= ?
             ORDER BY expires_tick DESC LIMIT 1",
            [$seasonId, (int)$playerId, $nowTick]
        );
        if (!$existing) {
            return ['error' => 'You are not currently frozen'];
        }

        $currentExpires = (int)$existing['expires_tick'];
        $remainingTicks = max(0, $currentExpires - $nowTick);
        if ($remainingTicks < 1) {
            return ['error' => 'You are not currently frozen'];
        }

        $reductionTicks = min((int)(SIGIL_MELT_REDUCTION_TICKS_BY_TIER[(int)$requestedTier] ?? ABILITY_UNIT_DURATION_TICKS), $remainingTicks);
        $newExpires = max($nowTick, $currentExpires - $reductionTicks);
        $newRemaining = max(0, $newExpires - $nowTick);

        $db->beginTransaction();
        try {
            $spent = $db->query(
                "UPDATE season_participation SET {$sigilCol} = {$sigilCol} - 1
                 WHERE player_id = ? AND season_id = ? AND {$sigilCol} >= 1",
                [$playerId, $seasonId]
            )->rowCount();
            if ($spent !== 1) {
                $db->rollback();
                return ['error' => 'You do not own the selected sigil tier for Melt'];
            }

            $db->query(
                "UPDATE active_freezes
                 SET expires_tick = ?, is_active = CASE WHEN ? <= ? THEN 0 ELSE 1 END
                 WHERE freeze_id = ?",
                [$newExpires, $newExpires, $nowTick, (int)$existing['freeze_id']]
            );

            $db->query(
                "UPDATE players SET last_activity_tick = ?, activity_state = 'Active', idle_modal_active = 0 WHERE player_id = ?",
                [$nowTick, $playerId]
            );

            $db->commit();

            return [
                'success' => true,
                'consumed_tier' => (int)$requestedTier,
                'reduction_ticks' => $reductionTicks,
                'new_remaining_ticks' => $newRemaining,
                'expires_tick' => $newExpires,
                'message' => $newRemaining > 0
                    ? ('Tier ' . (int)$requestedTier . ' Melt applied. Freeze reduced by ' . $reductionTicks . ' ticks.')
                    : ('Tier ' . (int)$requestedTier . ' Melt applied. Freeze removed.')
            ];
        } catch (Exception $e) {
            $db->rollback();
            return ['error' => 'Freeze melt failed'];
        }
    }
}
