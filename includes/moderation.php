<?php
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/account.php';
require_once __DIR__ . '/notifications.php';

class ModerationService {
    public static function searchUsers(array $actor, string $query): array {
        $like = '%' . strtolower(trim($query)) . '%';
        $rows = Database::getInstance()->fetchAll(
            "SELECT player_id, handle, email, role, profile_visibility, profile_deleted_at, created_at, last_seen_at
             FROM players
             WHERE LOWER(handle) LIKE ? OR LOWER(email) LIKE ? OR player_id = ?
             ORDER BY player_id DESC
             LIMIT 50",
            [$like, $like, (int)$query]
        );

        return array_values(array_filter($rows, function ($row) use ($actor) {
            return Permissions::canActOnTarget($actor, $row) || (int)$actor['player_id'] === (int)$row['player_id'];
        }));
    }

    public static function getUser(array $actor, int $targetId): array {
        $db = Database::getInstance();
        $target = $db->fetch("SELECT * FROM players WHERE player_id = ?", [$targetId]);
        if (!$target) return ['error' => 'Player not found'];
        if (!Permissions::canActOnTarget($actor, $target) && (int)$actor['player_id'] !== $targetId) {
            return ['error' => 'Insufficient permission for target'];
        }
        return [
            'success' => true,
            'user' => [
                'player_id' => (int)$target['player_id'],
                'handle' => $target['handle'],
                'email' => $target['email'],
                'role' => $target['role'],
                'bio' => $target['bio'] ?? '',
                'profile_status' => $target['profile_status'] ?? '',
                'profile_visibility' => $target['profile_visibility'],
                'profile_deleted_at' => $target['profile_deleted_at'] ?? null,
                'created_at' => $target['created_at'] ?? null,
                'last_seen_at' => $target['last_seen_at'] ?? null,
            ],
            'mutes' => self::activeMutes($targetId),
            'audit' => Audit::recentForTarget($targetId, 25)
        ];
    }

    public static function updateUser(array $actor, int $targetId, array $input): array {
        $db = Database::getInstance();
        $target = $db->fetch("SELECT * FROM players WHERE player_id = ?", [$targetId]);
        if (!$target) return ['error' => 'Player not found'];
        if (!Permissions::canActOnTarget($actor, $target)) return ['error' => 'Insufficient permission for target'];

        $bio = trim((string)($input['bio'] ?? ($target['bio'] ?? '')));
        $status = trim((string)($input['profile_status'] ?? ($target['profile_status'] ?? '')));
        $visibility = strtoupper(trim((string)($input['profile_visibility'] ?? $target['profile_visibility'])));
        if (strlen($bio) > 280) return ['error' => 'Bio must be 280 characters or fewer'];
        if (strlen($status) > 80) return ['error' => 'Status must be 80 characters or fewer'];
        if (!in_array($visibility, ['PUBLIC', 'FRIENDS_ONLY', 'HIDDEN'], true)) return ['error' => 'Invalid visibility'];

        $db->query(
            "UPDATE players SET bio = ?, profile_status = ?, profile_visibility = ? WHERE player_id = ?",
            [$bio, $status, $visibility, $targetId]
        );
        $after = $db->fetch("SELECT * FROM players WHERE player_id = ?", [$targetId]);
        Audit::record($actor['player_id'], $targetId, 'staff_user_update', trim((string)($input['reason'] ?? 'Staff account update')), $target, $after);
        return ['success' => true];
    }

    public static function removeMessage(array $actor, int $messageId, string $reason): array {
        $db = Database::getInstance();
        $msg = $db->fetch("SELECT * FROM chat_messages WHERE message_id = ?", [$messageId]);
        if (!$msg) return ['error' => 'Message not found'];
        $sender = $db->fetch("SELECT * FROM players WHERE player_id = ?", [(int)$msg['sender_id']]);
        if (!$sender) return ['error' => 'Message sender not found'];
        if (!Permissions::canActOnTarget($actor, $sender)) return ['error' => 'Insufficient permission for target'];

        $db->query(
            "UPDATE chat_messages SET is_removed = 1, removed_by = ?, removed_at = NOW(), removal_reason = ? WHERE message_id = ?",
            [(int)$actor['player_id'], $reason, $messageId]
        );
        Audit::record($actor['player_id'], (int)$msg['sender_id'], 'chat_message_remove', $reason, $msg, ['is_removed' => 1]);
        return ['success' => true];
    }

    public static function muteUser(array $actor, int $targetId, string $scope, ?int $minutes, string $reason): array {
        $db = Database::getInstance();
        $target = $db->fetch("SELECT * FROM players WHERE player_id = ?", [$targetId]);
        if (!$target) return ['error' => 'Player not found'];
        if (!Permissions::canActOnTarget($actor, $target)) return ['error' => 'Insufficient permission for target'];

        $scope = strtoupper($scope);
        if (!in_array($scope, ['GLOBAL', 'SEASON', 'STAFF', 'ALL'], true)) return ['error' => 'Invalid mute scope'];
        $expires = $minutes && $minutes > 0 ? date('Y-m-d H:i:s', time() + ($minutes * 60)) : null;
        $db->query(
            "INSERT INTO chat_mutes (target_player_id, actor_player_id, scope, reason, expires_at)
             VALUES (?, ?, ?, ?, ?)",
            [$targetId, (int)$actor['player_id'], $scope, $reason, $expires]
        );
        Notifications::create($targetId, 'moderation', 'Chat muted', 'Your chat access has been limited by staff.', ['severity' => 'warning']);
        Audit::record($actor['player_id'], $targetId, 'chat_mute', $reason, null, ['scope' => $scope, 'expires_at' => $expires]);
        return ['success' => true, 'expires_at' => $expires];
    }

    public static function unmuteUser(array $actor, int $muteId, string $reason): array {
        $db = Database::getInstance();
        $mute = $db->fetch("SELECT * FROM chat_mutes WHERE mute_id = ? AND revoked_at IS NULL", [$muteId]);
        if (!$mute) return ['error' => 'Mute not found'];
        $target = $db->fetch("SELECT * FROM players WHERE player_id = ?", [(int)$mute['target_player_id']]);
        if (!$target) return ['error' => 'Player not found'];
        if (!Permissions::canActOnTarget($actor, $target)) return ['error' => 'Insufficient permission for target'];

        $db->query("UPDATE chat_mutes SET revoked_at = NOW(), revoked_by = ? WHERE mute_id = ?", [(int)$actor['player_id'], $muteId]);
        Audit::record($actor['player_id'], (int)$mute['target_player_id'], 'chat_unmute', $reason, $mute, ['revoked' => true]);
        return ['success' => true];
    }

    public static function activeMutes(int $playerId): array {
        return Database::getInstance()->fetchAll(
            "SELECT * FROM chat_mutes
             WHERE target_player_id = ? AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > NOW())
             ORDER BY created_at DESC",
            [$playerId]
        );
    }

    public static function isMuted(int $playerId, string $scope): ?array {
        $scope = strtoupper($scope);
        return Database::getInstance()->fetch(
            "SELECT * FROM chat_mutes
             WHERE target_player_id = ?
               AND revoked_at IS NULL
               AND (expires_at IS NULL OR expires_at > NOW())
               AND (scope = 'ALL' OR scope = ?)
             ORDER BY created_at DESC
             LIMIT 1",
            [$playerId, $scope]
        ) ?: null;
    }
}
