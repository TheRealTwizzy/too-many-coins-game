<?php
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/moderation.php';
require_once __DIR__ . '/audit.php';

class StaffChatService {
    public static function startThread(array $actor, int $targetId, string $subject, string $body): array {
        if (!Permissions::isStaff($actor)) {
            return ['error' => 'Staff permission required'];
        }

        $db = Database::getInstance();
        $target = self::getActivePlayer($targetId);
        if (!$target) {
            return ['error' => 'Target player not found'];
        }

        $body = self::cleanBody($body);
        if ($body === '') {
            return ['error' => 'Message body required'];
        }
        if (strlen($body) > 1000) {
            return ['error' => 'Message body must be 1000 characters or fewer'];
        }

        $subject = trim($subject);
        if ($subject === '') {
            $subject = null;
        } elseif (strlen($subject) > 120) {
            return ['error' => 'Subject must be 120 characters or fewer'];
        }

        $db->beginTransaction();
        try {
            $db->query(
                "INSERT INTO staff_chat_threads (target_player_id, opened_by, subject)
                 VALUES (?, ?, ?)",
                [$targetId, (int)$actor['player_id'], $subject]
            );
            $threadId = (int)$db->getConnection()->lastInsertId();

            $db->query(
                "INSERT INTO staff_chat_messages (thread_id, sender_id, body, read_by_staff_at)
                 VALUES (?, ?, ?, NOW())",
                [$threadId, (int)$actor['player_id'], $body]
            );

            Audit::record(
                (int)$actor['player_id'],
                $targetId,
                'staff_chat_start',
                $subject,
                null,
                ['thread_id' => $threadId, 'subject' => $subject]
            );

            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }

        return [
            'success' => true,
            'thread_id' => $threadId,
            'thread' => self::getThreadForViewer($actor, $threadId)
        ];
    }

    public static function listThreads(array $viewer): array {
        $db = Database::getInstance();
        if (Permissions::isStaff($viewer)) {
            $rows = $db->fetchAll(
                "SELECT sct.thread_id, sct.target_player_id, sct.opened_by, sct.status, sct.subject,
                        sct.closed_at, sct.closed_by, sct.created_at, sct.updated_at,
                        target.handle AS target_handle, target.role AS target_role,
                        opener.handle AS opened_by_handle, opener.role AS opened_by_role,
                        last_msg.body AS last_message_body, last_msg.created_at AS last_message_at,
                        last_sender.handle AS last_sender_handle, last_sender.role AS last_sender_role
                 FROM staff_chat_threads sct
                 JOIN players target ON target.player_id = sct.target_player_id
                 JOIN players opener ON opener.player_id = sct.opened_by
                 LEFT JOIN staff_chat_messages last_msg
                   ON last_msg.staff_message_id = (
                       SELECT scm.staff_message_id
                       FROM staff_chat_messages scm
                       WHERE scm.thread_id = sct.thread_id
                       ORDER BY scm.created_at DESC, scm.staff_message_id DESC
                       LIMIT 1
                   )
                 LEFT JOIN players last_sender ON last_sender.player_id = last_msg.sender_id
                 ORDER BY sct.updated_at DESC, sct.thread_id DESC
                 LIMIT 100"
            );
        } else {
            $rows = $db->fetchAll(
                "SELECT sct.thread_id, sct.target_player_id, sct.opened_by, sct.status, sct.subject,
                        sct.closed_at, sct.closed_by, sct.created_at, sct.updated_at,
                        target.handle AS target_handle, target.role AS target_role,
                        opener.handle AS opened_by_handle, opener.role AS opened_by_role,
                        last_msg.body AS last_message_body, last_msg.created_at AS last_message_at,
                        last_sender.handle AS last_sender_handle, last_sender.role AS last_sender_role
                 FROM staff_chat_threads sct
                 JOIN players target ON target.player_id = sct.target_player_id
                 JOIN players opener ON opener.player_id = sct.opened_by
                 LEFT JOIN staff_chat_messages last_msg
                   ON last_msg.staff_message_id = (
                       SELECT scm.staff_message_id
                       FROM staff_chat_messages scm
                       WHERE scm.thread_id = sct.thread_id
                       ORDER BY scm.created_at DESC, scm.staff_message_id DESC
                       LIMIT 1
                   )
                 LEFT JOIN players last_sender ON last_sender.player_id = last_msg.sender_id
                 WHERE sct.target_player_id = ?
                 ORDER BY sct.updated_at DESC, sct.thread_id DESC
                 LIMIT 100",
                [(int)$viewer['player_id']]
            );
        }

        return array_map([self::class, 'normalizeThread'], $rows);
    }

    public static function getMessages(array $viewer, int $threadId): array {
        $thread = self::getThreadForViewer($viewer, $threadId);
        if (!$thread) {
            return ['error' => 'Thread not found'];
        }

        $messages = Database::getInstance()->fetchAll(
            "SELECT scm.staff_message_id, scm.thread_id, scm.sender_id, scm.body,
                    scm.read_by_player_at, scm.read_by_staff_at, scm.created_at,
                    p.handle AS sender_handle, p.role AS sender_role
             FROM staff_chat_messages scm
             JOIN players p ON p.player_id = scm.sender_id
             WHERE scm.thread_id = ?
             ORDER BY scm.created_at ASC, scm.staff_message_id ASC",
            [$threadId]
        );

        return [
            'success' => true,
            'thread' => $thread,
            'messages' => array_map([self::class, 'normalizeMessage'], $messages)
        ];
    }

    public static function sendMessage(array $sender, int $threadId, string $body): array {
        $thread = self::getThreadForViewer($sender, $threadId);
        if (!$thread) {
            return ['error' => 'Thread not found'];
        }
        if (($thread['status'] ?? 'OPEN') !== 'OPEN') {
            return ['error' => 'Thread is closed'];
        }

        $body = self::cleanBody($body);
        if ($body === '') {
            return ['error' => 'Message body required'];
        }
        if (strlen($body) > 1000) {
            return ['error' => 'Message body must be 1000 characters or fewer'];
        }

        if (!Permissions::isStaff($sender)) {
            $mute = ModerationService::isMuted((int)$sender['player_id'], 'STAFF');
            if ($mute) {
                return ['error' => 'You are muted in staff chat', 'mute' => $mute];
            }
        }

        $db = Database::getInstance();
        $readByPlayerAt = Permissions::isStaff($sender) ? null : date('Y-m-d H:i:s');
        $readByStaffAt = Permissions::isStaff($sender) ? date('Y-m-d H:i:s') : null;

        $db->beginTransaction();
        try {
            $db->query(
                "INSERT INTO staff_chat_messages (thread_id, sender_id, body, read_by_player_at, read_by_staff_at)
                 VALUES (?, ?, ?, ?, ?)",
                [$threadId, (int)$sender['player_id'], $body, $readByPlayerAt, $readByStaffAt]
            );
            $messageId = (int)$db->getConnection()->lastInsertId();
            $db->query("UPDATE staff_chat_threads SET updated_at = NOW() WHERE thread_id = ?", [$threadId]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }

        $message = $db->fetch(
            "SELECT scm.staff_message_id, scm.thread_id, scm.sender_id, scm.body,
                    scm.read_by_player_at, scm.read_by_staff_at, scm.created_at,
                    p.handle AS sender_handle, p.role AS sender_role
             FROM staff_chat_messages scm
             JOIN players p ON p.player_id = scm.sender_id
             WHERE scm.staff_message_id = ?",
            [$messageId]
        );

        return [
            'success' => true,
            'message' => self::normalizeMessage($message),
            'thread' => self::getThreadForViewer($sender, $threadId)
        ];
    }

    private static function getActivePlayer(int $playerId): ?array {
        if ($playerId <= 0) {
            return null;
        }

        $row = Database::getInstance()->fetch(
            "SELECT player_id, handle, role, profile_deleted_at
             FROM players
             WHERE player_id = ? AND profile_deleted_at IS NULL",
            [$playerId]
        );

        return $row ?: null;
    }

    private static function getThreadForViewer(array $viewer, int $threadId): ?array {
        if ($threadId <= 0) {
            return null;
        }

        $db = Database::getInstance();
        $params = [$threadId];
        $visibilitySql = '';
        if (!Permissions::isStaff($viewer)) {
            $visibilitySql = " AND sct.target_player_id = ?";
            $params[] = (int)$viewer['player_id'];
        }

        $row = $db->fetch(
            "SELECT sct.thread_id, sct.target_player_id, sct.opened_by, sct.status, sct.subject,
                    sct.closed_at, sct.closed_by, sct.created_at, sct.updated_at,
                    target.handle AS target_handle, target.role AS target_role,
                    opener.handle AS opened_by_handle, opener.role AS opened_by_role
             FROM staff_chat_threads sct
             JOIN players target ON target.player_id = sct.target_player_id
             JOIN players opener ON opener.player_id = sct.opened_by
             WHERE sct.thread_id = ?{$visibilitySql}
             LIMIT 1",
            $params
        );

        return $row ? self::normalizeThread($row) : null;
    }

    private static function cleanBody(string $body): string {
        return trim(str_replace(["\r\n", "\r"], "\n", $body));
    }

    private static function normalizeThread(array $row): array {
        return [
            'thread_id' => (int)$row['thread_id'],
            'target_player_id' => (int)$row['target_player_id'],
            'target_handle' => $row['target_handle'] ?? null,
            'target_role' => $row['target_role'] ?? null,
            'opened_by' => (int)$row['opened_by'],
            'opened_by_handle' => $row['opened_by_handle'] ?? null,
            'opened_by_role' => $row['opened_by_role'] ?? null,
            'status' => (string)($row['status'] ?? 'OPEN'),
            'subject' => $row['subject'] ?? null,
            'closed_at' => $row['closed_at'] ?? null,
            'closed_by' => isset($row['closed_by']) ? (int)$row['closed_by'] : null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
            'last_message_body' => $row['last_message_body'] ?? null,
            'last_message_at' => $row['last_message_at'] ?? null,
            'last_sender_handle' => $row['last_sender_handle'] ?? null,
            'last_sender_role' => $row['last_sender_role'] ?? null,
        ];
    }

    private static function normalizeMessage(array $row): array {
        return [
            'staff_message_id' => (int)$row['staff_message_id'],
            'thread_id' => (int)$row['thread_id'],
            'sender_id' => (int)$row['sender_id'],
            'sender_handle' => $row['sender_handle'] ?? null,
            'sender_role' => $row['sender_role'] ?? null,
            'body' => (string)$row['body'],
            'read_by_player_at' => $row['read_by_player_at'] ?? null,
            'read_by_staff_at' => $row['read_by_staff_at'] ?? null,
            'created_at' => $row['created_at'] ?? null,
        ];
    }
}
