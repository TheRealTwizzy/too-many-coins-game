<?php
require_once __DIR__ . '/database.php';

class Audit {
    public static function record($actorId, $targetId, string $actionType, ?string $reason = null, $before = null, $after = null): void {
        $db = Database::getInstance();
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
        return $db->fetchAll(
            "SELECT sal.*, p.handle AS actor_handle
             FROM staff_audit_log sal
             LEFT JOIN players p ON p.player_id = sal.actor_player_id
             WHERE sal.target_player_id = ?
             ORDER BY sal.created_at DESC
             LIMIT {$safeLimit}",
            [$targetId]
        );
    }
}
