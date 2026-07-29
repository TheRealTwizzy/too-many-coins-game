<?php
/**
 * Too Many Coins - Family ability verbs (Sigil Systems Spec §4-§5)
 *
 * New spend verbs introduced with sigil families: Ward (theft protection),
 * Market (rate-relative purchase discount), Sight (information), affinity
 * picks, and the Transmute/Distil forge recipes. Yield and Time run through
 * the existing boost purchase path; Larceny runs through the existing theft
 * path - both gain family awareness inside Actions.
 *
 * Every entry point refuses cleanly when families are not active, so none
 * of this is reachable on a flag-off deploy.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/game_time.php';
require_once __DIR__ . '/economy.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/sigil_families.php';

class FamilyActions {

    /** Shared preamble: authenticated season context with families active. */
    private static function context($playerId, array $allowedStatuses = ['Active', 'Blackout']) {
        $db = Database::getInstance();
        if (!SigilFamilies::active($db)) {
            return ['error' => 'Sigil families are not enabled on this server'];
        }

        $player = $db->fetch("SELECT * FROM players WHERE player_id = ?", [(int)$playerId]);
        if (!$player || !$player['participation_enabled'] || !$player['joined_season_id']) {
            return ['error' => 'Not participating in any season'];
        }
        if ($player['idle_modal_active']) {
            return ['error' => 'Cannot perform actions while idle', 'reason_code' => 'idle_gated'];
        }

        $seasonId = (int)$player['joined_season_id'];
        $season = $db->fetch("SELECT * FROM seasons WHERE season_id = ?", [$seasonId]);
        $status = GameTime::getSeasonStatus($season);
        if (!in_array($status, $allowedStatuses, true)) {
            return ['error' => 'This ability is unavailable in the current season phase'];
        }

        $participation = $db->fetch(
            "SELECT * FROM season_participation WHERE player_id = ? AND season_id = ?",
            [(int)$playerId, $seasonId]
        );
        if (!$participation) {
            return ['error' => 'Season participation not found'];
        }

        return [
            'db' => $db,
            'player' => $player,
            'season' => $season,
            'season_id' => $seasonId,
            'status' => $status,
            'participation' => $participation,
        ];
    }

    private static function touchActivity($db, $playerId, $nowTick) {
        $db->query(
            "UPDATE players SET last_activity_tick = ?, activity_state = 'Active', idle_modal_active = 0 WHERE player_id = ?",
            [(int)$nowTick, (int)$playerId]
        );
    }

    private static function roman($tier) {
        $numerals = [1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V', 6 => 'VI'];
        return $numerals[(int)$tier] ?? ('T' . (int)$tier);
    }

    // ---------------------------------------------------------------
    // Affinity
    // ---------------------------------------------------------------

    public static function pickAffinity($playerId, $familyCode) {
        $ctx = self::context($playerId);
        if (isset($ctx['error'])) {
            return $ctx;
        }
        $db = $ctx['db'];

        $familyId = SigilFamilies::familyIdByCode($familyCode);
        if ($familyId === null || in_array($familyId, [SigilFamilies::SIGHT_ID, SigilFamilies::WILD_ID], true)
            || !SigilFamilies::familyEnabled($db, $familyId)) {
            return ['error' => 'Choose an enabled material family'];
        }

        $nowTick = GameTime::now();
        $current = (int)($ctx['participation']['affinity_family_id'] ?? 0);

        if ($current > 0) {
            // Re-pick: one free, public, at blackout entry only.
            if ($ctx['status'] !== 'Blackout') {
                return ['error' => 'Affinity can only be re-picked during blackout'];
            }
            if (!empty($ctx['participation']['affinity_repicked_at_tick'])) {
                return ['error' => 'Affinity has already been re-picked this season'];
            }
            if ($familyId === $current) {
                return ['error' => 'That is already your affinity'];
            }
            $db->query(
                "UPDATE season_participation SET affinity_family_id = ?, affinity_repicked_at_tick = ?
                 WHERE player_id = ? AND season_id = ?",
                [$familyId, $nowTick, (int)$playerId, $ctx['season_id']]
            );
            SigilFamilies::emitEvent(
                $db, $ctx['season_id'], (int)$playerId, 'affinity_repick',
                sprintf('%s re-attuned to %s', (string)$ctx['player']['handle'], SigilFamilies::familyName($familyId)),
                $nowTick
            );
        } else {
            $db->query(
                "UPDATE season_participation SET affinity_family_id = ?
                 WHERE player_id = ? AND season_id = ?",
                [$familyId, (int)$playerId, $ctx['season_id']]
            );
        }

        self::touchActivity($db, $playerId, $nowTick);
        return [
            'success' => true,
            'affinity_family_id' => $familyId,
            'affinity_code' => SigilFamilies::familyCode($familyId),
            'repick' => $current > 0,
            'message' => 'Affinity set to ' . SigilFamilies::familyName($familyId)
                . ' (+' . SIGIL_AFFINITY_BONUS_PP . 'pp drop weight).',
        ];
    }

    // ---------------------------------------------------------------
    // Ward
    // ---------------------------------------------------------------

    public static function activateWard($playerId, $tier) {
        $ctx = self::context($playerId);
        if (isset($ctx['error'])) {
            return $ctx;
        }
        $db = $ctx['db'];
        $tier = (int)$tier;

        if (!SigilFamilies::familyEnabled($db, SigilFamilies::WARD_ID)) {
            return ['error' => 'The Ward family is not enabled'];
        }
        if (!isset(WARD_UNITS_X100_BY_TIER[$tier])) {
            return ['error' => 'Ward requires a Tier 2+ sigil'];
        }
        if ((int)($ctx['participation']['sigils_t' . $tier] ?? 0) < 1) {
            return ['error' => "Insufficient Tier {$tier} Sigils"];
        }
        if (SigilFamilies::spendableCount($db, $ctx['season_id'], $playerId, SigilFamilies::WARD_ID, $tier) < 1) {
            return ['error' => 'No Ward ' . self::roman($tier) . ' sigil in your holdings'];
        }

        $nowTick = GameTime::now();
        if (SigilFamilies::wardExpiresTick($db, $playerId, $ctx['season_id']) >= $nowTick) {
            return ['error' => 'A Ward is already active (wards do not stack)'];
        }

        $remaining = max(0, (int)$ctx['season']['end_time'] - $nowTick);
        $window = SigilFamilies::wardWindowTicks($tier, $ctx['status'] === 'Blackout', $remaining);
        if ($window <= 0) {
            return ['error' => 'Not enough season remaining to ward'];
        }

        $db->beginTransaction();
        try {
            $sigilCol = 'sigils_t' . $tier;
            $spent = $db->query(
                "UPDATE season_participation SET {$sigilCol} = {$sigilCol} - 1
                 WHERE player_id = ? AND season_id = ? AND {$sigilCol} >= 1",
                [(int)$playerId, $ctx['season_id']]
            )->rowCount();
            // The guard above can legitimately match zero rows when two requests
            // race for the last sigil. Without this check the sigil went unspent
            // and the ward was granted anyway.
            if ($spent !== 1) {
                $db->rollback();
                return ['error' => 'Not enough sigils'];
            }
            SigilFamilies::spendSpecific($db, $ctx['season_id'], $playerId, SigilFamilies::WARD_ID, $tier, 1);
            $db->query(
                "INSERT INTO active_wards (player_id, season_id, spent_tier, activated_tick, expires_tick)
                 VALUES (?, ?, ?, ?, ?)",
                [(int)$playerId, $ctx['season_id'], $tier, $nowTick, $nowTick + $window]
            );
            self::touchActivity($db, $playerId, $nowTick);
            $db->commit();
        } catch (Exception $e) {
            $db->rollback();
            return ['error' => 'Ward activation failed'];
        }

        $realSeconds = (int)($window * TICK_REAL_SECONDS);
        Notifications::create(
            $playerId,
            'ward_activated',
            'Ward Active',
            sprintf('Ward %s raised: theft protection for ~%d minutes.', self::roman($tier), max(1, intdiv($realSeconds, 60))),
            ['event_key' => 'ward:' . $ctx['season_id'] . ':' . $nowTick]
        );

        return [
            'success' => true,
            'tier' => $tier,
            'expires_tick' => $nowTick + $window,
            'window_ticks' => $window,
            'message' => 'Ward ' . self::roman($tier) . ' raised.',
        ];
    }

    // ---------------------------------------------------------------
    // Market
    // ---------------------------------------------------------------

    public static function primeMarket($playerId, $tier) {
        $ctx = self::context($playerId);
        if (isset($ctx['error'])) {
            return $ctx;
        }
        $db = $ctx['db'];
        $tier = (int)$tier;

        if (!SigilFamilies::familyEnabled($db, SigilFamilies::MARKET_ID)) {
            return ['error' => 'The Market family is not enabled'];
        }
        if ($tier < 1 || $tier > SIGIL_MAX_TIER) {
            return ['error' => 'Invalid sigil tier'];
        }
        if ((int)($ctx['participation']['sigils_t' . $tier] ?? 0) < 1) {
            return ['error' => "Insufficient Tier {$tier} Sigils"];
        }
        if (SigilFamilies::spendableCount($db, $ctx['season_id'], $playerId, SigilFamilies::MARKET_ID, $tier) < 1) {
            return ['error' => 'No Market ' . self::roman($tier) . ' sigil in your holdings'];
        }
        if ((int)($ctx['participation']['market_pending_vp'] ?? 0) > 0) {
            return ['error' => 'A Market discount is already primed - buy stars to use it'];
        }

        $nowTick = GameTime::now();
        $lastUsed = (int)($ctx['participation']['market_last_used_tick'] ?? 0);
        if ($lastUsed > 0 && ($nowTick - $lastUsed) < (int)MARKET_WINDOW_TICKS) {
            return ['error' => 'Market can be used once per 24-hour window', 'reason_code' => 'market_window'];
        }

        $vp = SigilFamilies::vpForTier($tier);
        $db->beginTransaction();
        try {
            $sigilCol = 'sigils_t' . $tier;
            $spent = $db->query(
                "UPDATE season_participation SET {$sigilCol} = {$sigilCol} - 1,
                 market_pending_vp = ?, market_last_used_tick = ?
                 WHERE player_id = ? AND season_id = ? AND {$sigilCol} >= 1",
                [$vp, $nowTick, (int)$playerId, $ctx['season_id']]
            )->rowCount();
            if ($spent !== 1) {
                $db->rollback();
                return ['error' => 'Not enough sigils'];
            }
            SigilFamilies::spendSpecific($db, $ctx['season_id'], $playerId, SigilFamilies::MARKET_ID, $tier, 1);
            self::touchActivity($db, $playerId, $nowTick);
            $db->commit();
        } catch (Exception $e) {
            $db->rollback();
            return ['error' => 'Market prime failed'];
        }

        // Public: the act, never the number (spec §14).
        SigilFamilies::emitEvent(
            $db, $ctx['season_id'], (int)$playerId, 'market_used',
            sprintf('%s used a Market sigil', (string)$ctx['player']['handle']),
            $nowTick
        );

        return [
            'success' => true,
            'tier' => $tier,
            'pending_vp' => $vp,
            'message' => 'Market ' . self::roman($tier) . ' primed: your next star purchase is discounted (up to 50%).',
        ];
    }

    // ---------------------------------------------------------------
    // Sight
    // ---------------------------------------------------------------

    public static function sightReveal($playerId, $kind, $targetPlayerId = 0) {
        $ctx = self::context($playerId);
        if (isset($ctx['error'])) {
            return $ctx;
        }
        $db = $ctx['db'];
        $kind = strtolower(trim((string)$kind));
        $validKinds = ['target_rate', 'price_step', 'pity', 'ward_status'];
        if (!in_array($kind, $validKinds, true)) {
            return ['error' => 'Unknown Sight reveal'];
        }
        if (!SigilFamilies::familyEnabled($db, SigilFamilies::SIGHT_ID)) {
            return ['error' => 'The Sight family is not enabled'];
        }

        // Consume the lowest-tier Sight held (Sight is holdings-only).
        $sightRow = $db->fetch(
            "SELECT tier, count FROM season_sigil_holdings
             WHERE season_id = ? AND player_id = ? AND family_id = ? AND count > 0
             ORDER BY tier ASC LIMIT 1",
            [$ctx['season_id'], (int)$playerId, SigilFamilies::SIGHT_ID]
        );
        if (!$sightRow) {
            return ['error' => 'No Sight sigils in your holdings'];
        }

        $target = null;
        if (in_array($kind, ['target_rate', 'ward_status'], true)) {
            $targetPlayerId = (int)$targetPlayerId;
            if ($targetPlayerId <= 0 || $targetPlayerId === (int)$playerId) {
                return ['error' => 'Choose another player in your season'];
            }
            // Season-local scope, non-negotiable: Sight sees the season you are in.
            $target = $db->fetch(
                "SELECT * FROM players WHERE player_id = ? AND joined_season_id = ? AND participation_enabled = 1",
                [$targetPlayerId, $ctx['season_id']]
            );
            if (!$target) {
                return ['error' => 'Target player not found in this season'];
            }
        }

        $nowTick = GameTime::now();
        $reveal = ['kind' => $kind];
        switch ($kind) {
            case 'target_rate':
                $targetParticipation = $db->fetch(
                    "SELECT * FROM season_participation WHERE player_id = ? AND season_id = ?",
                    [(int)$target['player_id'], $ctx['season_id']]
                );
                $target['current_game_time'] = $nowTick;
                $grossFp = Economy::calculateUBIFp($ctx['season'], $target, $targetParticipation);
                $reveal['target_handle'] = (string)$target['handle'];
                $reveal['gross_rate_per_min'] = round(($grossFp * max(1, ticks_from_real_seconds(60))) / FP_SCALE, 2);
                break;
            case 'price_step':
                $reveal['published_star_price'] = (int)Economy::publishedStarPrice($ctx['season'], $ctx['status']);
                $reveal['star_price_cap'] = (int)($ctx['season']['star_price_cap'] ?? 0);
                $reveal['max_upstep_fp'] = (int)($ctx['season']['starprice_max_upstep_fp'] ?? 0);
                $reveal['max_downstep_fp'] = (int)($ctx['season']['starprice_max_downstep_fp'] ?? 0);
                break;
            case 'pity':
                $eligible = (int)($ctx['participation']['eligible_ticks_since_last_drop'] ?? 0);
                $reveal['eligible_ticks_since_last_drop'] = $eligible;
                $reveal['pity_ticks'] = (int)SIGIL_PITY_TICKS;
                $reveal['ticks_to_pity'] = max(0, (int)SIGIL_PITY_TICKS - $eligible);
                break;
            case 'ward_status':
                $wardExpires = SigilFamilies::wardExpiresTick($db, (int)$target['player_id'], $ctx['season_id']);
                $reveal['target_handle'] = (string)$target['handle'];
                $reveal['ward_up'] = $wardExpires >= $nowTick;
                break;
        }

        $consumed = $db->query(
            "UPDATE season_sigil_holdings SET count = count - 1
             WHERE season_id = ? AND player_id = ? AND family_id = ? AND tier = ? AND count >= 1",
            [$ctx['season_id'], (int)$playerId, SigilFamilies::SIGHT_ID, (int)$sightRow['tier']]
        )->rowCount();
        if ($consumed !== 1) {
            return ['error' => 'Not enough Sight sigils'];
        }

        // Tell the target they were scried.
        //
        // target_rate and ward_status inspect another player's private state -
        // income rate and defensive posture - and both were completely silent, so
        // a rival could scout you repeatedly and you would never know. Theft and
        // ward already notify; reconnaissance should too, otherwise the attacker
        // has perfect information and the defender has none. The other two kinds
        // (price_step, pity) read only the caster's own state, so no one to tell.
        if (in_array($kind, ['target_rate', 'ward_status'], true) && !empty($target['player_id'])) {
            $scryer = $db->fetch("SELECT handle FROM players WHERE player_id = ?", [(int)$playerId]);
            Notifications::create(
                (int)$target['player_id'],
                'sight_revealed',
                'You Were Scried',
                ($scryer['handle'] ?? 'A rival') . ' used a Sight sigil to read your '
                    . ($kind === 'target_rate' ? 'income rate' : 'ward status') . '.',
                [
                    'event_key' => 'sight_revealed:' . $ctx['season_id'] . ':' . (int)$target['player_id'] . ':' . $nowTick,
                    'payload' => [
                        'season_id' => $ctx['season_id'],
                        'source_player_id' => (int)$playerId,
                        'source_handle' => (string)($scryer['handle'] ?? ''),
                        'reveal_kind' => $kind,
                    ],
                ]
            );
        }

        self::touchActivity($db, $playerId, $nowTick);

        return [
            'success' => true,
            'consumed_tier' => (int)$sightRow['tier'],
            'reveal' => $reveal,
            'message' => 'Sight consumed.',
        ];
    }

    // ---------------------------------------------------------------
    // Forge: Transmute and Distil (phase 4)
    // ---------------------------------------------------------------

    public static function transmuteSigils($playerId, $tier, array $familyCodes) {
        $ctx = self::context($playerId, ['Active']);
        if (isset($ctx['error'])) {
            return $ctx;
        }
        $db = $ctx['db'];
        if (!FORGE_TRANSMUTE_ENABLED) {
            return ['error' => 'Transmute is not enabled'];
        }

        $tier = (int)$tier;
        if ($tier < 1 || $tier > SIGIL_MAX_TIER) {
            return ['error' => 'Invalid tier'];
        }

        $familyIds = [];
        foreach ($familyCodes as $code) {
            $familyId = SigilFamilies::familyIdByCode($code);
            if ($familyId === null || in_array($familyId, [SigilFamilies::SIGHT_ID, SigilFamilies::WILD_ID], true)) {
                return ['error' => 'Transmute takes material families only'];
            }
            $familyIds[] = $familyId;
        }
        $familyIds = array_values(array_unique($familyIds));
        if (count($familyIds) !== 3) {
            return ['error' => 'Transmute takes three different material families of the same tier'];
        }

        foreach ($familyIds as $familyId) {
            if (SigilFamilies::holdingCount($db, $ctx['season_id'], $playerId, $familyId, $tier) < 1) {
                return ['error' => 'Missing a ' . SigilFamilies::familyName($familyId) . ' ' . self::roman($tier) . ' sigil'];
            }
        }
        if ((int)($ctx['participation']['sigils_t' . $tier] ?? 0) < 3) {
            return ['error' => "Insufficient Tier {$tier} Sigils"];
        }

        $nowTick = GameTime::now();
        $db->beginTransaction();
        try {
            foreach ($familyIds as $familyId) {
                $consumedFamily = $db->query(
                    "UPDATE season_sigil_holdings SET count = count - 1
                     WHERE season_id = ? AND player_id = ? AND family_id = ? AND tier = ? AND count >= 1",
                    [$ctx['season_id'], (int)$playerId, $familyId, $tier]
                )->rowCount();
                if ($consumedFamily !== 1) {
                    $db->rollback();
                    return ['error' => 'Missing a ' . SigilFamilies::familyName($familyId) . ' ' . self::roman($tier) . ' sigil'];
                }
            }
            // 3 in, 2 wildcards out: -33% value, wildcard spends as any family.
            SigilFamilies::addHolding($db, $ctx['season_id'], $playerId, SigilFamilies::WILD_ID, $tier, 2);
            $sigilCol = 'sigils_t' . $tier;
            // Net -1: three family holdings consumed above, two wildcards granted.
            // Checked so a lost race cannot leave the per-family holdings spent
            // while the aggregate count stays untouched.
            $spent = $db->query(
                "UPDATE season_participation SET {$sigilCol} = {$sigilCol} - 1
                 WHERE player_id = ? AND season_id = ? AND {$sigilCol} >= 3",
                [(int)$playerId, $ctx['season_id']]
            )->rowCount();
            if ($spent !== 1) {
                $db->rollback();
                return ['error' => "Insufficient Tier {$tier} Sigils"];
            }
            self::touchActivity($db, $playerId, $nowTick);
            $db->commit();
        } catch (Exception $e) {
            $db->rollback();
            return ['error' => 'Transmute failed'];
        }

        return [
            'success' => true,
            'tier' => $tier,
            'produced' => 2,
            'message' => 'Transmuted three families into two Wildcard ' . self::roman($tier) . ' sigils.',
        ];
    }

    public static function distilSigils($playerId, $tier, $targetFamilyCode) {
        $ctx = self::context($playerId, ['Active']);
        if (isset($ctx['error'])) {
            return $ctx;
        }
        $db = $ctx['db'];
        if (!FORGE_DISTIL_ENABLED) {
            return ['error' => 'Distil is not enabled'];
        }

        $tier = (int)$tier;
        if ($tier < 2 || $tier > SIGIL_MAX_TIER) {
            return ['error' => 'Distil requires Tier 2+ Sight sigils'];
        }
        $targetFamilyId = SigilFamilies::familyIdByCode($targetFamilyCode);
        if ($targetFamilyId === null
            || in_array($targetFamilyId, [SigilFamilies::SIGHT_ID, SigilFamilies::WILD_ID], true)
            || !SigilFamilies::familyEnabled($db, $targetFamilyId)) {
            return ['error' => 'Choose an enabled material family to distil into'];
        }
        if (SigilFamilies::holdingCount($db, $ctx['season_id'], $playerId, SigilFamilies::SIGHT_ID, $tier) < 3) {
            return ['error' => 'Distil takes three Sight ' . self::roman($tier) . ' sigils'];
        }
        $outTier = $tier - 1;
        if (!Economy::canReceiveSigilTier($ctx['participation'], $outTier, 1)) {
            return ['error' => "Tier {$outTier} sigil inventory cap reached"];
        }

        $nowTick = GameTime::now();
        $db->beginTransaction();
        try {
            $consumed = $db->query(
                "UPDATE season_sigil_holdings SET count = count - 3
                 WHERE season_id = ? AND player_id = ? AND family_id = ? AND tier = ? AND count >= 3",
                [$ctx['season_id'], (int)$playerId, SigilFamilies::SIGHT_ID, $tier]
            )->rowCount();
            if ($consumed !== 1) {
                $db->rollback();
                return ['error' => 'Not enough Sight sigils'];
            }
            SigilFamilies::addHolding($db, $ctx['season_id'], $playerId, $targetFamilyId, $outTier, 1);
            $sigilCol = 'sigils_t' . $outTier;
            $db->query(
                "UPDATE season_participation SET {$sigilCol} = {$sigilCol} + 1
                 WHERE player_id = ? AND season_id = ?",
                [(int)$playerId, $ctx['season_id']]
            );
            self::touchActivity($db, $playerId, $nowTick);
            $db->commit();
        } catch (Exception $e) {
            $db->rollback();
            return ['error' => 'Distil failed'];
        }

        return [
            'success' => true,
            'produced_tier' => $outTier,
            'produced_family' => SigilFamilies::familyCode($targetFamilyId),
            'message' => 'Distilled three Sight into a ' . SigilFamilies::familyName($targetFamilyId) . ' ' . self::roman($outTier) . '.',
        ];
    }

    // ---------------------------------------------------------------
    // Read-only state
    // ---------------------------------------------------------------

    public static function familyState($playerId) {
        $db = Database::getInstance();
        $player = $db->fetch("SELECT * FROM players WHERE player_id = ?", [(int)$playerId]);
        $seasonId = (int)($player['joined_season_id'] ?? 0);
        $participation = null;
        if ($seasonId > 0) {
            $participation = $db->fetch(
                "SELECT * FROM season_participation WHERE player_id = ? AND season_id = ?",
                [(int)$playerId, $seasonId]
            );
        }
        $payload = SigilFamilies::statePayload($db, $seasonId ?: null, (int)$playerId, $participation);
        if (SigilFamilies::active($db) && $seasonId > 0) {
            $nowTick = GameTime::now();
            $wardExpires = SigilFamilies::wardExpiresTick($db, (int)$playerId, $seasonId);
            $payload['ward'] = [
                'active' => $wardExpires >= $nowTick,
                'expires_tick' => $wardExpires,
            ];
            $payload['market'] = [
                'pending_vp' => (int)($participation['market_pending_vp'] ?? 0),
                'last_used_tick' => (int)($participation['market_last_used_tick'] ?? 0),
                'window_ticks' => (int)MARKET_WINDOW_TICKS,
            ];
        }
        return $payload;
    }

    public static function seasonEvents($playerId, $limit = 25) {
        $db = Database::getInstance();
        $player = $db->fetch("SELECT * FROM players WHERE player_id = ?", [(int)$playerId]);
        $seasonId = (int)($player['joined_season_id'] ?? 0);
        if ($seasonId <= 0 || !SigilFamilies::active($db)) {
            return ['events' => []];
        }
        return ['events' => SigilFamilies::listEvents($db, $seasonId, (int)$limit)];
    }
}
