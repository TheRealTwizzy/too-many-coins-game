<?php
require_once __DIR__ . '/database.php';

class Audit {
    /**
     * Keys that must never be written into an audit snapshot.
     *
     * Several callers hand this class a whole `SELECT * FROM players` row as
     * the "before" state - which is the honest thing to want for an audit
     * trail, and also means the row carries the target's password hash and
     * their LIVE session token. Those then came straight back out through
     * staff_user_get, so any Moderator could lift a working session token for
     * anyone they had ever edited and act as them. Verified: one
     * staff_user_update wrote both into staff_audit_log.
     *
     * Redacting at the point of capture rather than asking every caller to
     * remember: the callers are right that they want the row, and a rule that
     * depends on being remembered at fifteen call sites is not a rule.
     */
    private const REDACTED_KEYS = [
        'password_hash',
        'password',
        'session_token',
        'email_verification_token',
        'deletion_token',
        'reset_token',
        'season_seed',
    ];

    /** Recursively strip secrets from an audit snapshot. */
    private static function redact($value) {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $inner) {
                if (is_string($key) && in_array(strtolower($key), self::REDACTED_KEYS, true)) {
                    // Recorded as present-but-hidden rather than dropped, so a
                    // reviewer can still see that the field took part in the
                    // change without being handed its value.
                    $out[$key] = $inner === null ? null : '[redacted]';
                    continue;
                }
                $out[$key] = self::redact($inner);
            }
            return $out;
        }
        return $value;
    }

    public static function record($actorId, $targetId, string $actionType, ?string $reason = null, $before = null, $after = null): void {
        $db = Database::getInstance();
        $before = self::redact($before);
        $after = self::redact($after);
        $db->query(
            "INSERT INTO staff_audit_log
             (actor_player_id, target_player_id, action_type, reason, before_json, after_json, request_ip)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                $actorId ? (int)$actorId : null,
                $targetId ? (int)$targetId : null,
                $actionType,
                $reason,
                $before !== null ? json_encode($before) : null,
                $after !== null ? json_encode($after) : null,
                $_SERVER['REMOTE_ADDR'] ?? null
            ]
        );
    }

    public static function recentForTarget(int $targetId, int $limit = 25): array {
        $db = Database::getInstance();
        $safeLimit = max(1, min(100, $limit));
        $rows = $db->fetchAll(
            "SELECT sal.*, p.handle AS actor_handle
             FROM staff_audit_log sal
             LEFT JOIN players p ON p.player_id = sal.actor_player_id
             WHERE sal.target_player_id = ?
             ORDER BY sal.created_at DESC
             LIMIT {$safeLimit}",
            [$targetId]
        );

        // Redact on the way out as well as on the way in. Capture-time
        // redaction only protects rows written from now on; every row already
        // in the table was captured under the old rule and still holds the
        // secrets. Since this is the only path that serves them to a human,
        // filtering here closes the existing exposure without a migration
        // that would rewrite the audit history it exists to preserve.
        foreach ($rows as &$row) {
            foreach (['before_json', 'after_json'] as $col) {
                if (empty($row[$col]) || !is_string($row[$col])) continue;
                $decoded = json_decode($row[$col], true);
                if ($decoded === null) continue;
                $row[$col] = json_encode(self::redact($decoded));
            }
        }
        unset($row);

        return $rows;
    }
}
