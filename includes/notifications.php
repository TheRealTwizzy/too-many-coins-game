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
        $severity = isset($options['severity']) ? (string)$options['severity'] : 'info';
        if (!in_array($severity, ['info', 'success', 'warning', 'danger'], true)) {
            $severity = 'info';
        }
        $actionUrl = isset($options['action_url']) ? (string)$options['action_url'] : null;

        $db->query(
            "INSERT INTO player_notifications
             (player_id, category, severity, title, body, event_key, payload_json, action_url, is_read, read_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE notification_id = LAST_INSERT_ID(notification_id)",
            [
                (int)$playerId,
                (string)$category,
                $severity,
                (string)$title,
                $body !== null ? (string)$body : null,
                $eventKey,
                $payload,
                $actionUrl,
                $isRead,
                $readAt
            ]
        );

        return (int)$db->getConnection()->lastInsertId();
    }

    /**
     * Broadcast to every active player, in chunked multi-row inserts.
     *
     * This used to call create() once per player inside a PHP loop - one INSERT
     * and one round trip each, no batching and no transaction. At 10k players a
     * single staff broadcast was 10k round trips inside one HTTP request, which
     * times out part-way through and leaves a partial broadcast with no way to
     * tell how far it got or to resume it.
     */
    public static function createForAll($category, $title, $body = null, $options = []) {
        $db = Database::getInstance();
        $players = $db->fetchAll(
            "SELECT player_id FROM players WHERE profile_deleted_at IS NULL ORDER BY player_id ASC"
        );
        if (empty($players)) {
            return 0;
        }

        $isRead = !empty($options['is_read']) ? 1 : 0;
        $readAt = $isRead ? date('Y-m-d H:i:s') : null;
        $eventKey = isset($options['event_key']) ? (string)$options['event_key'] : null;
        $payload = array_key_exists('payload', $options) ? json_encode($options['payload']) : null;
        $severity = isset($options['severity']) ? (string)$options['severity'] : 'info';
        if (!in_array($severity, ['info', 'success', 'warning', 'danger'], true)) {
            $severity = 'info';
        }
        $actionUrl = isset($options['action_url']) ? (string)$options['action_url'] : null;

        // 500 rows x 10 columns keeps each statement well inside max_allowed_packet
        // and the placeholder limit, while cutting round trips by the same factor.
        $chunkSize = 500;
        $count = 0;

        foreach (array_chunk($players, $chunkSize) as $chunk) {
            $valuesSql = [];
            $params = [];
            foreach ($chunk as $player) {
                $valuesSql[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                array_push(
                    $params,
                    (int)$player['player_id'],
                    (string)$category,
                    $severity,
                    (string)$title,
                    $body !== null ? (string)$body : null,
                    $eventKey,
                    $payload,
                    $actionUrl,
                    $isRead,
                    $readAt
                );
            }

            $db->query(
                "INSERT INTO player_notifications
                 (player_id, category, severity, title, body, event_key, payload_json, action_url, is_read, read_at)
                 VALUES " . implode(', ', $valuesSql) . "
                 ON DUPLICATE KEY UPDATE notification_id = notification_id",
                $params
            );
            $count += count($chunk);
        }

        return $count;
    }

    public static function listForPlayer($playerId, $limit = 50) {
        $db = Database::getInstance();
        $safeLimit = max(1, min(100, (int)$limit));
        $rows = $db->fetchAll(
            "SELECT notification_id, category, severity, title, body, payload_json, action_url, is_read, created_at, read_at
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

    public static function getByIdForPlayer($playerId, $notificationId) {
        $db = Database::getInstance();
        $row = $db->fetch(
            "SELECT notification_id, category, severity, title, body, payload_json, action_url, is_read, created_at, read_at
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
        $row['severity'] = (string)($row['severity'] ?? 'info');
        $row['action_url'] = $row['action_url'] ?? null;
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
