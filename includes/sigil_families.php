<?php
/**
 * Too Many Coins - Sigil Families (Sigil Systems Spec §2-§10)
 *
 * A sigil is family x tier: the tier is magnitude (existing ladder), the
 * family is the verb. Six families: Yield, Time, Ward, Larceny, Market,
 * Sight (+ the Wildcard pseudo-family produced by Transmute).
 *
 * Rule 2 (one faucet): families change WHICH sigil drops, never HOW MANY.
 * The family roll happens strictly after the existing gate + tier roll and
 * touches nothing upstream of it.
 *
 * Compatibility: the positional sigils_tN array on season_participation
 * remains authoritative for the whole rollout. Holdings mirror it per
 * family; every legacy spend path syncs the mirror through this class.
 * Sight is the exception: it lives only in holdings (it displaces nothing,
 * does not count toward the 25-cap, and never enters lock-in refunds).
 *
 * Everything here is inert unless TMC_SIGIL_FAMILIES_ENABLED is on, the P1
 * schema exists, and at least one material family row is enabled.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/economy.php';

class SigilFamilies {
    const YIELD_ID = 1;
    const TIME_ID = 2;
    const WARD_ID = 3;
    const LARCENY_ID = 4;
    const MARKET_ID = 5;
    const SIGHT_ID = 6;
    const WILD_ID = 7;

    const CODES = [
        self::YIELD_ID => 'yield',
        self::TIME_ID => 'time',
        self::WARD_ID => 'ward',
        self::LARCENY_ID => 'larceny',
        self::MARKET_ID => 'market',
        self::SIGHT_ID => 'sight',
        self::WILD_ID => 'wild',
    ];

    const NAMES = [
        self::YIELD_ID => 'Yield',
        self::TIME_ID => 'Time',
        self::WARD_ID => 'Ward',
        self::LARCENY_ID => 'Larceny',
        self::MARKET_ID => 'Market',
        self::SIGHT_ID => 'Sight',
        self::WILD_ID => 'Wildcard',
    ];

    /** @var array|null request-scope cache: [family_id => row] or null before load */
    private static $familyRows = null;
    private static $schemaChecked = null;

    // ---------------------------------------------------------------
    // Gates
    // ---------------------------------------------------------------

    public static function schemaReady($db) {
        if (self::$schemaChecked !== null) {
            return self::$schemaChecked;
        }
        $row = $db->fetch(
            "SELECT COUNT(*) AS c FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name IN ('sigil_family', 'season_sigil_holdings')"
        );
        self::$schemaChecked = ((int)($row['c'] ?? 0)) === 2;
        return self::$schemaChecked;
    }

    /** Global gate: env flag + schema + at least one enabled material family. */
    public static function active($db) {
        if (!TMC_SIGIL_FAMILIES_ENABLED || !self::schemaReady($db)) {
            return false;
        }
        foreach (self::familyRows($db) as $row) {
            if ((int)$row['enabled'] === 1 && (int)$row['family_id'] !== self::SIGHT_ID && (int)$row['family_id'] !== self::WILD_ID) {
                return true;
            }
        }
        return false;
    }

    public static function familyRows($db) {
        if (self::$familyRows === null) {
            $rows = self::schemaReady($db)
                ? $db->fetchAll("SELECT * FROM sigil_family ORDER BY family_id")
                : [];
            self::$familyRows = [];
            foreach ($rows as $row) {
                self::$familyRows[(int)$row['family_id']] = $row;
            }
        }
        return self::$familyRows;
    }

    public static function familyEnabled($db, $familyId) {
        $rows = self::familyRows($db);
        return isset($rows[(int)$familyId]) && (int)$rows[(int)$familyId]['enabled'] === 1;
    }

    public static function familyName($familyId) {
        return self::NAMES[(int)$familyId] ?? 'Sigil';
    }

    public static function familyCode($familyId) {
        return self::CODES[(int)$familyId] ?? '';
    }

    public static function familyIdByCode($code) {
        $code = strtolower(trim((string)$code));
        $flipped = array_flip(self::CODES);
        return isset($flipped[$code]) ? (int)$flipped[$code] : null;
    }

    /** Utility VP for a tier (T1 = 1 on the game's own ladder). */
    public static function vpForTier($tier) {
        $utility = (int)(SIGIL_UTILITY_VALUE_BY_TIER[(int)$tier] ?? 0);
        return max(0, intdiv($utility, 50));
    }

    // ---------------------------------------------------------------
    // Drop-time family roll (deterministic, after the tier roll)
    // ---------------------------------------------------------------

    /**
     * Roll the family for a freshly dropped material sigil.
     * Returns family_id or null when families are not active.
     */
    public static function rollFamilyForDrop($db, $season, $playerId, $tickIndex, $tier, $participation = null) {
        if (!self::active($db)) {
            return null;
        }

        $weights = self::effectiveDropWeightsFp($db, $tier, $participation);
        if (empty($weights)) {
            return null;
        }

        $roll = Economy::deterministicSigilRollU32(
            (int)$season['season_id'],
            (int)$playerId,
            (int)$tickIndex,
            'sigil_family',
            $season['season_seed']
        ) % FP_SCALE;

        $cumulative = 0;
        $chosen = null;
        foreach ($weights as $familyId => $weightFp) {
            $cumulative += $weightFp;
            if ($roll < $cumulative) {
                $chosen = (int)$familyId;
                break;
            }
        }
        if ($chosen === null) {
            $chosen = (int)array_key_first($weights);
        }

        // Per-family x tier holding cap: fall to the least-held eligible family.
        if (self::holdingCount($db, $season['season_id'], $playerId, $chosen, $tier) >= CAPS_PER_FAMILY_HOLDING) {
            $fallback = null;
            $fallbackCount = PHP_INT_MAX;
            foreach ($weights as $familyId => $weightFp) {
                $count = self::holdingCount($db, $season['season_id'], $playerId, $familyId, $tier);
                if ($count < CAPS_PER_FAMILY_HOLDING && $count < $fallbackCount) {
                    $fallback = (int)$familyId;
                    $fallbackCount = $count;
                }
            }
            if ($fallback !== null) {
                $chosen = $fallback;
            }
        }

        return $chosen;
    }

    /**
     * Enabled material families eligible at this tier, with affinity applied
     * (+SIGIL_AFFINITY_BONUS_PP to the pick, -SIGIL_AFFINITY_PENALTY_PP each
     * elsewhere), renormalized to FP_SCALE. 1pp = FP_SCALE / 100.
     */
    public static function effectiveDropWeightsFp($db, $tier, $participation = null) {
        $rows = self::familyRows($db);
        $ppFp = intdiv(FP_SCALE, 100);
        $affinityId = (int)($participation['affinity_family_id'] ?? 0);

        $weights = [];
        foreach (SIGIL_FAMILY_WEIGHTS_FP as $code => $baseFp) {
            $familyId = self::familyIdByCode($code);
            if ($familyId === null || !isset($rows[$familyId]) || (int)$rows[$familyId]['enabled'] !== 1) {
                continue;
            }
            if ((int)$tier < (int)$rows[$familyId]['min_tier']) {
                continue;
            }
            $adjusted = (int)$baseFp;
            if ($affinityId > 0) {
                $adjusted += ($familyId === $affinityId)
                    ? SIGIL_AFFINITY_BONUS_PP * $ppFp
                    : -(SIGIL_AFFINITY_PENALTY_PP * $ppFp);
            }
            $weights[$familyId] = max(0, $adjusted);
        }

        $total = array_sum($weights);
        if ($total <= 0) {
            return [];
        }
        foreach ($weights as $familyId => $weightFp) {
            $weights[$familyId] = intdiv($weightFp * FP_SCALE, $total);
        }
        return $weights;
    }

    /** Deterministic Sight trickle roll: appended to a drop, displaces nothing. */
    public static function rollSightTrickle($db, $season, $playerId, $tickIndex) {
        if (!self::active($db) || !self::familyEnabled($db, self::SIGHT_ID)) {
            return false;
        }
        $roll = Economy::deterministicSigilRollU32(
            (int)$season['season_id'],
            (int)$playerId,
            (int)$tickIndex,
            'sigil_sight',
            $season['season_seed']
        ) % FP_SCALE;
        return $roll < (int)SIGIL_SIGHT_TRICKLE_CHANCE_FP;
    }

    // ---------------------------------------------------------------
    // Holdings (mirror of the positional array, per family)
    // ---------------------------------------------------------------

    public static function holdingCount($db, $seasonId, $playerId, $familyId, $tier) {
        $row = $db->fetch(
            "SELECT count FROM season_sigil_holdings
             WHERE season_id = ? AND player_id = ? AND family_id = ? AND tier = ?",
            [(int)$seasonId, (int)$playerId, (int)$familyId, (int)$tier]
        );
        return max(0, (int)($row['count'] ?? 0));
    }

    public static function addHolding($db, $seasonId, $playerId, $familyId, $tier, $amount = 1) {
        $amount = max(0, (int)$amount);
        if ($amount <= 0) {
            return;
        }
        $db->query(
            "INSERT INTO season_sigil_holdings (season_id, player_id, family_id, tier, count)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE count = count + VALUES(count)",
            [(int)$seasonId, (int)$playerId, (int)$familyId, (int)$tier, $amount]
        );
    }

    /**
     * Spend from a specific family (wildcard substitutes when the named
     * family runs short). Returns the number actually decremented.
     */
    public static function spendSpecific($db, $seasonId, $playerId, $familyId, $tier, $amount = 1) {
        $amount = max(0, (int)$amount);
        $spent = 0;
        foreach ([(int)$familyId, self::WILD_ID] as $sourceFamily) {
            if ($spent >= $amount) {
                break;
            }
            $available = self::holdingCount($db, $seasonId, $playerId, $sourceFamily, $tier);
            $take = min($available, $amount - $spent);
            if ($take > 0) {
                $db->query(
                    "UPDATE season_sigil_holdings SET count = count - ?
                     WHERE season_id = ? AND player_id = ? AND family_id = ? AND tier = ? AND count >= ?",
                    [$take, (int)$seasonId, (int)$playerId, $sourceFamily, (int)$tier, $take]
                );
                $spent += $take;
            }
        }
        return $spent;
    }

    /** Family + wildcard availability for a verb spend at a tier. */
    public static function spendableCount($db, $seasonId, $playerId, $familyId, $tier) {
        return self::holdingCount($db, $seasonId, $playerId, $familyId, $tier)
            + self::holdingCount($db, $seasonId, $playerId, self::WILD_ID, $tier);
    }

    /**
     * Sync the mirror for a family-agnostic legacy spend of N tier-n sigils
     * (combine, freeze, melt, legacy theft spend). Preferred families first,
     * then largest holding first. Pre-family sigils may be untracked, so
     * this decrements at most what the mirror holds.
     */
    public static function syncSpendTier($db, $seasonId, $playerId, $tier, $amount, array $preferFamilyIds = []) {
        if (!self::active($db)) {
            return;
        }
        $amount = max(0, (int)$amount);
        foreach ($preferFamilyIds as $familyId) {
            if ($amount <= 0) {
                break;
            }
            $amount -= self::spendSpecific($db, $seasonId, $playerId, (int)$familyId, $tier, $amount);
        }
        while ($amount > 0) {
            $row = $db->fetch(
                "SELECT family_id, count FROM season_sigil_holdings
                 WHERE season_id = ? AND player_id = ? AND tier = ? AND count > 0 AND family_id <> ?
                 ORDER BY count DESC, family_id ASC LIMIT 1",
                [(int)$seasonId, (int)$playerId, (int)$tier, self::SIGHT_ID]
            );
            if (!$row) {
                break;
            }
            $take = min((int)$row['count'], $amount);
            $db->query(
                "UPDATE season_sigil_holdings SET count = count - ?
                 WHERE season_id = ? AND player_id = ? AND family_id = ? AND tier = ? AND count >= ?",
                [$take, (int)$seasonId, (int)$playerId, (int)$row['family_id'], (int)$tier, $take]
            );
            $amount -= $take;
        }
    }

    /** Sync the mirror for a theft transfer of N tier-n sigils (largest-first). */
    public static function syncTransferTier($db, $seasonId, $fromPlayerId, $toPlayerId, $tier, $amount) {
        if (!self::active($db)) {
            return;
        }
        $amount = max(0, (int)$amount);
        while ($amount > 0) {
            $row = $db->fetch(
                "SELECT family_id, count FROM season_sigil_holdings
                 WHERE season_id = ? AND player_id = ? AND tier = ? AND count > 0 AND family_id <> ?
                 ORDER BY count DESC, family_id ASC LIMIT 1",
                [(int)$seasonId, (int)$fromPlayerId, (int)$tier, self::SIGHT_ID]
            );
            if (!$row) {
                // Untracked pre-family sigils: land them as Yield for the taker.
                self::addHolding($db, $seasonId, $toPlayerId, self::YIELD_ID, $tier, $amount);
                return;
            }
            $take = min((int)$row['count'], $amount);
            $db->query(
                "UPDATE season_sigil_holdings SET count = count - ?
                 WHERE season_id = ? AND player_id = ? AND family_id = ? AND tier = ? AND count >= ?",
                [$take, (int)$seasonId, (int)$fromPlayerId, (int)$row['family_id'], (int)$tier, $take]
            );
            self::addHolding($db, $seasonId, $toPlayerId, (int)$row['family_id'], $tier, $take);
            $amount -= $take;
        }
    }

    public static function holdingsForPlayer($db, $seasonId, $playerId) {
        if (!self::schemaReady($db)) {
            return [];
        }
        $rows = $db->fetchAll(
            "SELECT family_id, tier, count FROM season_sigil_holdings
             WHERE season_id = ? AND player_id = ? AND count > 0
             ORDER BY family_id, tier",
            [(int)$seasonId, (int)$playerId]
        );
        $out = [];
        foreach ($rows as $row) {
            $familyId = (int)$row['family_id'];
            if (!isset($out[$familyId])) {
                $out[$familyId] = [
                    'family_id' => $familyId,
                    'code' => self::familyCode($familyId),
                    'name' => self::familyName($familyId),
                    'tiers' => [],
                ];
            }
            $out[$familyId]['tiers'][(int)$row['tier']] = (int)$row['count'];
        }
        return array_values($out);
    }

    public static function clearSeasonHoldings($db, $playerId, $seasonId) {
        if (!self::schemaReady($db)) {
            return;
        }
        $db->query(
            "DELETE FROM season_sigil_holdings WHERE player_id = ? AND season_id = ?",
            [(int)$playerId, (int)$seasonId]
        );
        if (self::tableExists($db, 'active_wards')) {
            $db->query(
                "DELETE FROM active_wards WHERE player_id = ? AND season_id = ?",
                [(int)$playerId, (int)$seasonId]
            );
        }
    }

    // ---------------------------------------------------------------
    // Verb math
    // ---------------------------------------------------------------

    /**
     * Ward window in ticks for a spent tier: units x the shared 15-minute
     * ability unit, halved in blackout, capped at 25% of remaining season.
     * Non-stacking is enforced at the action layer.
     */
    public static function wardWindowTicks($tier, $isBlackout, $remainingSeasonTicks) {
        $unitsX100 = (int)(WARD_UNITS_X100_BY_TIER[(int)$tier] ?? 0);
        if ($unitsX100 <= 0) {
            return 0;
        }
        $window = intdiv($unitsX100 * (int)ABILITY_UNIT_DURATION_TICKS, 100);
        if ($isBlackout) {
            $window = max(1, intdiv($window, 2));
        }
        $cap = intdiv(max(0, (int)$remainingSeasonTicks) * (int)WARD_MAX_FRACTION_OF_REMAINING_FP, FP_SCALE);
        return max(0, min($window, $cap));
    }

    public static function wardExpiresTick($db, $playerId, $seasonId) {
        if (!self::tableExists($db, 'active_wards')) {
            return 0;
        }
        $row = $db->fetch(
            "SELECT MAX(expires_tick) AS expires_tick FROM active_wards
             WHERE player_id = ? AND season_id = ?",
            [(int)$playerId, (int)$seasonId]
        );
        return max(0, (int)($row['expires_tick'] ?? 0));
    }

    /**
     * Time family: derived extension, never authored.
     * added_seconds = VP(tier) x 0.1h / (active_modifier_pct / 100)
     * On a +25% boost, tier 1 adds ~24 minutes and tier 4 adds ~24 hours.
     */
    public static function derivedTimeExtensionTicks($tier, $activeModifierFp) {
        $vp = self::vpForTier($tier);
        $modifierFp = (int)$activeModifierFp;
        if ($vp <= 0 || $modifierFp <= 0) {
            return 0;
        }
        // 0.1h = 360s; dividing by (modifier_fp / 1e4 / 100) => x 1e6 / modifier_fp
        $seconds = intdiv($vp * 360 * FP_SCALE, $modifierFp);
        return max(1, ticks_from_real_seconds($seconds));
    }

    /**
     * Market: coins saved on one purchase, in the player's own income.
     * coins_saved = VP x rate_hours x own gross hourly rate, capped at
     * MARKET_MAX_DISCOUNT_FRACTION of the purchase.
     */
    public static function marketCoinsSaved($vp, $grossPerTickFp, $coinsNeeded) {
        $vp = max(0, (int)$vp);
        $grossPerTickFp = max(0, (int)$grossPerTickFp);
        if ($vp <= 0 || $grossPerTickFp <= 0 || $coinsNeeded <= 0) {
            return 0;
        }
        $ticksPerHour = max(1, ticks_from_real_seconds(3600));
        $hourlyFp = $grossPerTickFp * $ticksPerHour;
        $savedFp = intdiv($hourlyFp * (int)MARKET_RATE_HOURS_PER_VP_FP, FP_SCALE) * $vp;
        $saved = intdiv($savedFp, FP_SCALE);
        $cap = intdiv((int)$coinsNeeded * (int)MARKET_MAX_DISCOUNT_FRACTION_FP, FP_SCALE);
        return max(0, min($saved, $cap));
    }

    // ---------------------------------------------------------------
    // Season events ticker (drama budget: the act, never the number)
    // ---------------------------------------------------------------

    public static function emitEvent($db, $seasonId, $actorPlayerId, $kind, $publicText, $tick) {
        if (!EVENTS_PUBLIC_TICKER_ENABLED || !self::tableExists($db, 'season_events')) {
            return;
        }
        $db->query(
            "INSERT INTO season_events (season_id, actor_player_id, event_kind, public_text, event_tick)
             VALUES (?, ?, ?, ?, ?)",
            [(int)$seasonId, $actorPlayerId !== null ? (int)$actorPlayerId : null,
             substr((string)$kind, 0, 32), substr((string)$publicText, 0, 255), (int)$tick]
        );
    }

    public static function listEvents($db, $seasonId, $limit = 25) {
        if (!self::tableExists($db, 'season_events')) {
            return [];
        }
        return $db->fetchAll(
            "SELECT e.event_id, e.event_kind, e.public_text, e.event_tick, p.handle AS actor_handle
             FROM season_events e
             LEFT JOIN players p ON p.player_id = e.actor_player_id
             WHERE e.season_id = ?
             ORDER BY e.event_id DESC
             LIMIT " . max(1, min(100, (int)$limit)),
            [(int)$seasonId]
        );
    }

    // ---------------------------------------------------------------

    public static function tableExists($db, $tableName) {
        static $cache = [];
        $tableName = (string)$tableName;
        if (!array_key_exists($tableName, $cache)) {
            $row = $db->fetch(
                "SELECT COUNT(*) AS c FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = ?",
                [$tableName]
            );
            $cache[$tableName] = ((int)($row['c'] ?? 0)) > 0;
        }
        return $cache[$tableName];
    }

    /** Public state payload for the client (family_state API action). */
    public static function statePayload($db, $seasonId, $playerId, $participation = null) {
        $isActive = self::active($db);
        $payload = [
            'enabled' => $isActive,
            'families' => [],
            'holdings' => [],
            'affinity_family_id' => null,
            'affinity_repicked' => false,
            'forge' => [
                'transmute_enabled' => $isActive && FORGE_TRANSMUTE_ENABLED,
                'distil_enabled' => $isActive && FORGE_DISTIL_ENABLED,
            ],
            'caps' => [
                'per_family_holding' => (int)CAPS_PER_FAMILY_HOLDING,
            ],
        ];
        if (!$isActive) {
            return $payload;
        }
        foreach (self::familyRows($db) as $row) {
            $payload['families'][] = [
                'family_id' => (int)$row['family_id'],
                'code' => (string)$row['code'],
                'name' => (string)$row['name'],
                'min_tier' => (int)$row['min_tier'],
                'enabled' => (int)$row['enabled'] === 1,
            ];
        }
        if ($seasonId && $playerId) {
            $payload['holdings'] = self::holdingsForPlayer($db, $seasonId, $playerId);
        }
        if ($participation) {
            $payload['affinity_family_id'] = isset($participation['affinity_family_id'])
                ? (int)$participation['affinity_family_id'] ?: null
                : null;
            $payload['affinity_repicked'] = !empty($participation['affinity_repicked_at_tick']);
        }
        return $payload;
    }
}
