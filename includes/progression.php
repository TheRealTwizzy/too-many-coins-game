<?php
/**
 * Too Many Coins - Progression gates (feature discovery)
 *
 * Design goal (owner directive): every player-to-game-economy interaction is
 * eventually hidden until its prerequisites are met - you cannot see sigils
 * before your first drop, cannot discover a tier before first holding or
 * forging one, and later whole systems (chat, trade, clans, custom seasons)
 * reveal themselves through play. This class is the foundation; features
 * adopt it incrementally.
 *
 * A discovery is account knowledge, not inventory: rows survive season
 * resets, lock-ins and re-joins. Losing your sigils does not make you forget
 * that sigils exist.
 *
 * FEATURE KEY ROADMAP (dotted lowercase domain.item):
 *
 *   key                | unlocked by                                | status
 *   -------------------+--------------------------------------------+---------
 *   sigils.ui          | first drop, forge, or starter grant        | shipped
 *   sigils.tier.1..6   | first holding/forging of that tier         | shipped
 *   families.panel     | families active AND first family sigil     | shipped
 *   theft.ui           | first Larceny-capable sigil held           | roadmap
 *   boosts.ui          | first boost-eligible sigil                 | roadmap
 *   chat               | TBD (first season join?)                   | roadmap
 *   trade              | TBD                                        | roadmap
 *   clans              | TBD                                        | roadmap
 *   seasons.custom     | TBD                                        | roadmap
 *
 * Gating contract with the client: game_state publishes player.unlocks as
 * null when the flag is off (null = ungated, everything visible - old-client
 * compatible) or an array of discovered keys when on. The client's
 * ctx.unlocked(key) treats null as true.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

class Progression {
    private static $tableChecked = null;

    public static function tableReady($db) {
        if (self::$tableChecked !== null) {
            return self::$tableChecked;
        }
        $row = $db->fetch(
            "SELECT COUNT(*) AS c FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'player_feature_unlocks'"
        );
        self::$tableChecked = ((int)($row['c'] ?? 0)) === 1;
        return self::$tableChecked;
    }

    public static function enabled($db) {
        return TMC_PROGRESSION_GATES_ENABLED && self::tableReady($db);
    }

    /**
     * Record a discovery. Gated on tableReady, NOT on enabled(): discoveries
     * keep accumulating while the flag is off, so flipping it on later never
     * hides something a player has already seen. INSERT IGNORE on the
     * two-column PK makes repeats free.
     */
    public static function unlock($db, $playerId, $featureKey, $tick = 0) {
        if (!self::tableReady($db)) {
            return;
        }
        $db->query(
            "INSERT IGNORE INTO player_feature_unlocks (player_id, feature_key, unlocked_at_tick)
             VALUES (?, ?, ?)",
            [(int)$playerId, (string)$featureKey, (int)$tick]
        );
    }

    public static function has($db, $playerId, $featureKey) {
        if (!self::tableReady($db)) {
            return false;
        }
        $row = $db->fetch(
            "SELECT 1 AS x FROM player_feature_unlocks WHERE player_id = ? AND feature_key = ?",
            [(int)$playerId, (string)$featureKey]
        );
        return (bool)$row;
    }

    /** @return string[] discovered keys, sorted for stable payloads. */
    public static function keysForPlayer($db, $playerId) {
        if (!self::tableReady($db)) {
            return [];
        }
        $rows = $db->fetchAll(
            "SELECT feature_key FROM player_feature_unlocks WHERE player_id = ? ORDER BY feature_key",
            [(int)$playerId]
        );
        return array_map(static function ($row) {
            return (string)$row['feature_key'];
        }, $rows);
    }

    /**
     * Backfill for players who predate the table (or the triggers): anything
     * their current participation proves they have seen is unlocked on read,
     * so enabling the flag can never take UI away from an established player.
     */
    public static function backfillFromParticipation($db, $playerId, $participation) {
        if (!self::tableReady($db) || !$participation) {
            return;
        }
        $sawAny = false;
        for ($tier = 1; $tier <= 6; $tier++) {
            if ((int)($participation['sigils_t' . $tier] ?? 0) > 0) {
                self::unlock($db, $playerId, 'sigils.tier.' . $tier);
                $sawAny = true;
            }
        }
        if ($sawAny || (int)($participation['sigil_drops_total'] ?? 0) > 0) {
            self::unlock($db, $playerId, 'sigils.ui');
        }
    }
}
