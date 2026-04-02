<?php
/**
 * Too Many Coins - Notifications
 * Persistent per-player notification log helpers.
 */
require_once __DIR__ . '/database.php';

class Notifications {
    public static function create($playerId, $category, $title, $body = null, $options = []) {
        $db = Database::getInstance();
        $isRead = !empty($options['is_read']) ? 1 : 0;
        $readAt = $isRead ? date('Y-m-d H:i:s') : null;
        $eventKey = isset($options['event_key']) ? (string)$options['event_key'] : null;
        $payload = array_key_exists('payload', $options)
            ? json_encode($options['payload'])
            : null;

        $db->query(
            "INSERT INTO player_notifications
             (player_id, category, title, body, event_key, payload_json, is_read, read_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE notification_id = LAST_INSERT_ID(notification_id)",
            [
                (int)$playerId,
                (string)$category,
                (string)$title,
                $body !== null ? (string)$body : null,
                $eventKey,
                $payload,
                $isRead,
                $readAt
            ]
        );

        return (int)$db->getConnection()->lastInsertId();
    }

    public static function listForPlayer($playerId, $limit = 50) {
        $db = Database::getInstance();
        $safeLimit = max(1, min(100, (int)$limit));
        $rows = $db->fetchAll(
            "SELECT notification_id, category, title, body, payload_json, is_read, created_at, read_at
             FROM player_notifications
             WHERE player_id = ? AND removed_at IS NULL
             ORDER BY created_at DESC, notification_id DESC
             LIMIT {$safeLimit}",
            [(int)$playerId]
        );

        foreach ($rows as &$row) {
            $row = self::normalizeRow($row);
        }
        unset($row);

        return $rows;
    }

    public static function listForPlayerWithUnread($playerId, $limit = 50) {
        $db = Database::getInstance();
        $safeLimit = max(1, min(100, (int)$limit));

        $rows = $db->fetchAll(
            "SELECT notification_id, category, title, body, payload_json, is_read, created_at, read_at,
                    (SELECT COUNT(*)
                     FROM player_notifications pn2
                     WHERE pn2.player_id = ?
                       AND pn2.removed_at IS NULL
                       AND pn2.is_read = 0) AS unread_count
             FROM player_notifications pn
             WHERE pn.player_id = ? AND pn.removed_at IS NULL
             ORDER BY pn.created_at DESC, pn.notification_id DESC
             LIMIT {$safeLimit}",
            [(int)$playerId, (int)$playerId]
        );

        $unreadCount = 0;
        foreach ($rows as &$row) {
            $unreadCount = max($unreadCount, (int)($row['unread_count'] ?? 0));
            unset($row['unread_count']);
            $row = self::normalizeRow($row);
        }
        unset($row);

        if (empty($rows)) {
            $unreadCount = self::unreadCount($playerId);
        }

        return [
            'notifications' => $rows,
            'unread_count' => (int)$unreadCount,
        ];
    }

    public static function getByIdForPlayer($playerId, $notificationId) {
        $db = Database::getInstance();
        $row = $db->fetch(
            "SELECT notification_id, category, title, body, payload_json, is_read, created_at, read_at
             FROM player_notifications
             WHERE player_id = ? AND notification_id = ? AND removed_at IS NULL",
            [(int)$playerId, (int)$notificationId]
        );

        if (!$row) return null;
        return self::normalizeRow($row);
    }

    public static function unreadCount($playerId) {
        $db = Database::getInstance();
        $row = $db->fetch(
            "SELECT COUNT(*) AS c
             FROM player_notifications
             WHERE player_id = ? AND removed_at IS NULL AND is_read = 0",
            [(int)$playerId]
        );

        return (int)($row['c'] ?? 0);
    }

    public static function markRead($playerId, $notificationIds) {
        $ids = self::sanitizeIds($notificationIds);
        if (count($ids) === 0) return 0;

        $db = Database::getInstance();
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $params = array_merge([(int)$playerId], $ids);

        $stmt = $db->query(
            "UPDATE player_notifications
             SET is_read = 1, read_at = COALESCE(read_at, NOW())
             WHERE player_id = ?
               AND removed_at IS NULL
               AND notification_id IN ({$placeholders})",
            $params
        );

        return $stmt->rowCount();
    }

    public static function markAllRead($playerId) {
        $db = Database::getInstance();
        $stmt = $db->query(
            "UPDATE player_notifications
             SET is_read = 1, read_at = COALESCE(read_at, NOW())
             WHERE player_id = ? AND removed_at IS NULL AND is_read = 0",
            [(int)$playerId]
        );
        return $stmt->rowCount();
    }

    public static function remove($playerId, $notificationIds) {
        $ids = self::sanitizeIds($notificationIds);
        if (count($ids) === 0) return 0;

        $db = Database::getInstance();
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $params = array_merge([(int)$playerId], $ids);

        $stmt = $db->query(
            "UPDATE player_notifications
             SET removed_at = NOW(),
                 is_read = 1,
                 read_at = COALESCE(read_at, NOW())
             WHERE player_id = ?
               AND removed_at IS NULL
               AND notification_id IN ({$placeholders})",
            $params
        );

        return $stmt->rowCount();
    }

    private static function sanitizeIds($ids) {
        if (!is_array($ids)) {
            $ids = [$ids];
        }

        $out = [];
        foreach ($ids as $id) {
            $n = (int)$id;
            if ($n > 0) {
                $out[$n] = $n;
            }
        }

        return array_values($out);
    }

    private static function normalizeRow($row) {
        $row['notification_id'] = (int)$row['notification_id'];
        $row['is_read'] = (bool)$row['is_read'];
        $payload = $row['payload_json'] ?? null;
        $row['payload'] = $payload ? json_decode($payload, true) : null;
        unset($row['payload_json']);
        // Ensure DATETIME fields carry explicit UTC designator so JS `new Date()`
        // always parses them as UTC regardless of browser/engine behaviour.
        $row['created_at'] = self::isoUtc($row['created_at'] ?? null);
        $row['read_at']    = self::isoUtc($row['read_at'] ?? null);
        return $row;
    }

    /**
     * Convert a MySQL DATETIME string ("YYYY-MM-DD HH:MM:SS") to an ISO 8601
     * UTC string ("YYYY-MM-DDTHH:MM:SS+00:00") suitable for unambiguous JS
     * Date parsing.  Returns null for null/empty input.
     */
    private static function isoUtc(?string $dt): ?string {
        if ($dt === null || $dt === '') return null;
        return str_replace(' ', 'T', $dt) . '+00:00';
    }
}
