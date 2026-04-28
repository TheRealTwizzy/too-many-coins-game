<?php
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/notifications.php';

class SocialService {
    private static function pair(int $a, int $b): array {
        return $a < $b ? [$a, $b] : [$b, $a];
    }

    private static function playerExists(int $playerId): bool {
        if ($playerId <= 0) {
            return false;
        }

        $row = Database::getInstance()->fetch(
            "SELECT 1 FROM players WHERE player_id = ? AND profile_deleted_at IS NULL LIMIT 1",
            [$playerId]
        );
        return (bool)$row;
    }

    public static function friendsList(int $playerId): array {
        return Database::getInstance()->fetchAll(
            "SELECT p.player_id, p.handle, p.profile_status, p.online_current, f.created_at
             FROM friendships f
             JOIN players p ON p.player_id = IF(f.player_a = ?, f.player_b, f.player_a)
             WHERE (f.player_a = ? OR f.player_b = ?) AND p.profile_deleted_at IS NULL
             ORDER BY p.handle ASC",
            [$playerId, $playerId, $playerId]
        );
    }

    public static function requestsList(int $playerId): array {
        return Database::getInstance()->fetchAll(
            "SELECT fr.*, pf.handle AS from_handle, pt.handle AS to_handle
             FROM friend_requests fr
             JOIN players pf ON pf.player_id = fr.from_player
             JOIN players pt ON pt.player_id = fr.to_player
             WHERE (fr.from_player = ? OR fr.to_player = ?) AND fr.status = 'PENDING'
             ORDER BY fr.created_at DESC",
            [$playerId, $playerId]
        );
    }

    public static function sendRequest(int $fromId, int $toId): array {
        if ($fromId === $toId) return ['error' => 'You cannot friend yourself'];
        if (!self::playerExists($toId)) return ['error' => 'Target player not found'];
        $db = Database::getInstance();
        if (self::isBlockedEitherWay($fromId, $toId)) return ['error' => 'Friend request unavailable'];
        [$a, $b] = self::pair($fromId, $toId);
        if ($db->fetch("SELECT 1 FROM friendships WHERE player_a = ? AND player_b = ?", [$a, $b])) {
            return ['error' => 'Already friends'];
        }
        $db->query(
            "INSERT INTO friend_requests (from_player, to_player, status)
             VALUES (?, ?, 'PENDING')
             ON DUPLICATE KEY UPDATE status = 'PENDING', created_at = NOW()",
            [$fromId, $toId]
        );
        Notifications::create($toId, 'social', 'New friend request', 'You have a new friend request.', ['severity' => 'info', 'payload' => ['from_player' => $fromId]]);
        return ['success' => true];
    }

    public static function respondRequest(int $playerId, int $requestId, string $decision): array {
        $decision = strtoupper($decision);
        if (!in_array($decision, ['ACCEPTED', 'DECLINED'], true)) return ['error' => 'Invalid decision'];
        $db = Database::getInstance();
        $req = $db->fetch("SELECT * FROM friend_requests WHERE id = ? AND to_player = ? AND status = 'PENDING'", [$requestId, $playerId]);
        if (!$req) return ['error' => 'Friend request not found'];
        $db->beginTransaction();
        try {
            $db->query("UPDATE friend_requests SET status = ? WHERE id = ?", [$decision, $requestId]);
            if ($decision === 'ACCEPTED') {
                [$a, $b] = self::pair((int)$req['from_player'], (int)$req['to_player']);
                $db->query("INSERT IGNORE INTO friendships (player_a, player_b) VALUES (?, ?)", [$a, $b]);
            }
            $db->commit();
            return ['success' => true];
        } catch (Throwable $e) {
            $db->rollback();
            return ['error' => 'Could not update friend request'];
        }
    }

    public static function removeFriend(int $playerId, int $friendId): array {
        [$a, $b] = self::pair($playerId, $friendId);
        Database::getInstance()->query("DELETE FROM friendships WHERE player_a = ? AND player_b = ?", [$a, $b]);
        return ['success' => true];
    }

    public static function blocksList(int $playerId): array {
        return Database::getInstance()->fetchAll(
            "SELECT b.blocked_id AS player_id, p.handle, b.created_at
             FROM blocks b
             JOIN players p ON p.player_id = b.blocked_id
             WHERE b.blocker_id = ?
             ORDER BY p.handle ASC",
            [$playerId]
        );
    }

    public static function blockAdd(int $playerId, int $blockedId): array {
        if ($playerId === $blockedId) return ['error' => 'You cannot block yourself'];
        if (!self::playerExists($blockedId)) return ['error' => 'Target player not found'];
        $db = Database::getInstance();
        $db->query("INSERT IGNORE INTO blocks (blocker_id, blocked_id) VALUES (?, ?)", [$playerId, $blockedId]);
        [$a, $b] = self::pair($playerId, $blockedId);
        $db->query("DELETE FROM friendships WHERE player_a = ? AND player_b = ?", [$a, $b]);
        $db->query("UPDATE friend_requests SET status = 'DECLINED' WHERE status = 'PENDING' AND ((from_player = ? AND to_player = ?) OR (from_player = ? AND to_player = ?))", [$playerId, $blockedId, $blockedId, $playerId]);
        return ['success' => true];
    }

    public static function blockRemove(int $playerId, int $blockedId): array {
        Database::getInstance()->query("DELETE FROM blocks WHERE blocker_id = ? AND blocked_id = ?", [$playerId, $blockedId]);
        return ['success' => true];
    }

    public static function isBlockedEitherWay(int $a, int $b): bool {
        $row = Database::getInstance()->fetch(
            "SELECT 1 FROM blocks WHERE (blocker_id = ? AND blocked_id = ?) OR (blocker_id = ? AND blocked_id = ?) LIMIT 1",
            [$a, $b, $b, $a]
        );
        return (bool)$row;
    }
}
