<?php
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/permissions.php';

class AccountService {
    public static function getAccount(array $player): array {
        return [
            'player_id' => (int)$player['player_id'],
            'handle' => (string)$player['handle'],
            'email' => (string)$player['email'],
            'role' => (string)$player['role'],
            'bio' => (string)($player['bio'] ?? ''),
            'profile_status' => (string)($player['profile_status'] ?? ''),
            'profile_visibility' => (string)($player['profile_visibility'] ?? 'PUBLIC'),
            'email_verified_at' => isset($player['email_verified_at']) ? $player['email_verified_at'] : null,
        ];
    }

    public static function updateAccount(array $player, array $input): array {
        $bio = trim((string)($input['bio'] ?? ''));
        $status = trim((string)($input['profile_status'] ?? ''));
        $visibility = strtoupper(trim((string)($input['profile_visibility'] ?? 'PUBLIC')));

        if (strlen($bio) > 280) return ['error' => 'Bio must be 280 characters or fewer'];
        if (strlen($status) > 80) return ['error' => 'Status must be 80 characters or fewer'];
        if (!in_array($visibility, ['PUBLIC', 'FRIENDS_ONLY', 'HIDDEN'], true)) {
            return ['error' => 'Invalid profile visibility'];
        }

        $db = Database::getInstance();
        $before = self::getAccount($player);
        $db->query(
            "UPDATE players SET bio = ?, profile_status = ?, profile_visibility = ? WHERE player_id = ?",
            [$bio, $status, $visibility, (int)$player['player_id']]
        );
        $updated = $db->fetch("SELECT * FROM players WHERE player_id = ?", [(int)$player['player_id']]);
        Audit::record($player['player_id'], $player['player_id'], 'account_update', null, $before, self::getAccount($updated));
        return ['success' => true, 'account' => self::getAccount($updated)];
    }

    public static function changePassword(array $player, array $input): array {
        $current = (string)($input['current_password'] ?? '');
        $new = (string)($input['new_password'] ?? '');
        $confirm = (string)($input['confirm_password'] ?? '');

        if (!password_verify($current, (string)$player['password_hash'])) {
            return ['error' => 'Current password is incorrect'];
        }
        if ($new !== $confirm) {
            return ['error' => 'New passwords do not match'];
        }
        if (strlen($new) < 6) {
            return ['error' => 'Password must be at least 6 characters'];
        }
        if (strlen($new) > 128) {
            return ['error' => 'Password is too long'];
        }

        // Changing the password must invalidate the old session.
        //
        // It did not, and sessions never expire server-side, so a token stolen
        // before the change kept working forever afterwards. That inverts the
        // meaning of the action: the one thing a player does when they think
        // they have been compromised did not evict the intruder, and told them
        // "Your password was changed successfully" while the attacker stayed
        // signed in.
        //
        // The new token is returned so the caller who just authenticated with
        // their current password is not logged out by their own remediation.
        // Every other holder of the old token is.
        $hash = password_hash($new, PASSWORD_BCRYPT);
        $newToken = bin2hex(random_bytes(32));
        Database::getInstance()->query(
            "UPDATE players SET password_hash = ?, session_token = ? WHERE player_id = ?",
            [$hash, $newToken, (int)$player['player_id']]
        );
        self::issueSessionCookie($newToken);

        Audit::record($player['player_id'], $player['player_id'], 'password_change');
        Notifications::create(
            $player['player_id'],
            'account_security',
            'Password changed',
            'Your password was changed, and every other signed-in device was signed out.',
            ['severity' => 'success']
        );
        return ['success' => true, 'token' => $newToken];
    }

    /**
     * Re-issue the session cookie with the same flags Auth::login sets.
     *
     * HttpOnly and SameSite matter as much here as at login - a rotation that
     * downgraded the cookie would trade one weakness for another.
     */
    private static function issueSessionCookie(string $token): void {
        if (headers_sent()) return;
        setcookie('tmc_session', $token, [
            'expires' => time() + (86400 * 30),
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function createVerificationToken(int $actorId, int $targetId, string $actionType, ?array $payload = null): array {
        $raw = bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);
        $expires = date('Y-m-d H:i:s', time() + ((int)TMC_VERIFICATION_TOKEN_MINUTES * 60));
        Database::getInstance()->query(
            "INSERT INTO account_verification_tokens
             (actor_player_id, target_player_id, action_type, token_hash, payload_json, expires_at, request_ip, request_user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $actorId,
                $targetId,
                $actionType,
                $hash,
                $payload ? json_encode($payload) : null,
                $expires,
                $_SERVER['REMOTE_ADDR'] ?? null,
                substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)
            ]
        );
        return ['raw' => $raw, 'expires_at' => $expires];
    }

    private static function consumeVerificationToken(string $token, string $actionType): ?array {
        $row = self::findVerificationToken($token, $actionType);
        if (!$row) return null;
        if (!self::consumeVerificationTokenRow($row)) return null;
        return $row;
    }

    private static function findVerificationToken(string $token, string $actionType): ?array {
        $hash = hash('sha256', $token);
        $row = Database::getInstance()->fetch(
            "SELECT * FROM account_verification_tokens
             WHERE token_hash = ? AND action_type = ? AND consumed_at IS NULL AND expires_at > NOW()",
            [$hash, $actionType]
        );
        return $row ?: null;
    }

    private static function consumeVerificationTokenRow(array $row): bool {
        $db = Database::getInstance();
        $stmt = $db->query(
            "UPDATE account_verification_tokens
             SET consumed_at = NOW()
             WHERE token_id = ? AND consumed_at IS NULL AND expires_at > NOW()",
            [(int)$row['token_id']]
        );
        return $stmt->rowCount() === 1;
    }

    /**
     * Send (or re-send) an email confirmation link.
     *
     * Never throws and never reports a mail failure to the caller as an error,
     * because both callers are wrong places to fail: registration must not be
     * lost because SMTP was down, and a resend that says "failed" invites the
     * player to hammer it. The boolean comes back so registration can decide
     * what to tell them, and the transport failure is already in the log.
     */
    public static function sendEmailVerification(array $player): array {
        if (!empty($player['email_verified_at'])) {
            return ['success' => true, 'already_verified' => true];
        }

        // Dev-log mode writes the link to the error log and sends nothing. That
        // is correct for a test lane and catastrophic in a live one now that an
        // unconfirmed account can do nothing at all: every new player would be
        // permanently locked out, with the only evidence a line in a log nobody
        // is reading. Say so distinctly, on every attempt, so it is greppable.
        if (TMC_MAIL_DEV_LOG) {
            error_log(
                '[mail-dev] WARNING: TMC_MAIL_DEV_LOG is on, so this confirmation '
                . 'link was logged and NOT sent. Accounts cannot be used until '
                . 'confirmed - set TMC_MAIL_DEV_LOG=false and configure SMTP '
                . 'before opening registration.'
            );
        }

        $token = self::createVerificationToken(
            (int)$player['player_id'], (int)$player['player_id'], 'EMAIL_VERIFY'
        );
        $url = self::verificationUrl('EMAIL_VERIFY', $token['raw']);
        $sent = Mailer::send(
            (string)$player['email'],
            'Confirm your Too Many Coins email',
            "Confirm your email address to join a season:\n\n{$url}\n\n"
            . "This link expires at {$token['expires_at']} UTC. If it does, ask for a new one from the game."
        );

        return ['success' => true, 'sent' => $sent, 'expires_at' => $token['expires_at']];
    }

    /**
     * Consume a confirmation link.
     *
     * The stamp is guarded on the column still being NULL so two clicks on the
     * same link - a mail client prefetching it, then the player - cannot
     * rewrite the confirmation time. The token itself is already single-use;
     * this is the second lock on the same door, because the cost is one WHERE
     * clause and the failure is silent otherwise.
     */
    public static function confirmEmailVerification(string $token): array {
        $row = self::consumeVerificationToken($token, 'EMAIL_VERIFY');
        if (!$row) {
            return [
                'error' => 'That confirmation link is invalid or has expired.',
                'reason_code' => 'verification_token_invalid',
            ];
        }

        $db = Database::getInstance();
        $playerId = (int)$row['target_player_id'];
        $db->query(
            "UPDATE players SET email_verified_at = NOW()
             WHERE player_id = ? AND email_verified_at IS NULL",
            [$playerId]
        );

        $player = $db->fetch("SELECT handle, email_verified_at FROM players WHERE player_id = ?", [$playerId]);
        if (!$player || empty($player['email_verified_at'])) {
            return ['error' => 'Could not confirm that address.', 'reason_code' => 'verification_failed'];
        }

        Audit::record($playerId, $playerId, 'email_verified', 'Confirmed via emailed link');
        return ['success' => true, 'handle' => $player['handle'], 'email_verified_at' => $player['email_verified_at']];
    }

    public static function requestSelfDeletion(array $player, string $reason): array {
        $token = self::createVerificationToken((int)$player['player_id'], (int)$player['player_id'], 'SELF_DELETE', ['reason' => $reason]);
        $url = self::verificationUrl('SELF_DELETE', $token['raw']);
        Mailer::send((string)$player['email'], 'Confirm Too Many Coins account deletion', "Confirm account deletion:\n\n{$url}\n\nThis link expires at {$token['expires_at']} UTC.");
        Audit::record($player['player_id'], $player['player_id'], 'account_delete_request', $reason);
        return ['success' => true, 'expires_at' => $token['expires_at']];
    }

    public static function confirmSelfDeletion(string $token): array {
        $row = self::consumeVerificationToken($token, 'SELF_DELETE');
        if (!$row) return ['error' => 'Verification token is invalid or expired'];
        return self::softDelete((int)$row['actor_player_id'], (int)$row['target_player_id'], self::payloadReason($row), 'self_account_delete');
    }

    public static function requestStaffDeletion(array $actor, int $targetId, string $reason): array {
        $db = Database::getInstance();
        $target = $db->fetch("SELECT * FROM players WHERE player_id = ?", [$targetId]);
        if (!$target) return ['error' => 'Player not found'];
        if (!empty($target['profile_deleted_at'])) return ['error' => 'Player is already deleted'];
        if (!Permissions::canActOnTarget($actor, $target)) return ['error' => 'Insufficient permission for target'];

        $reason = trim($reason) !== '' ? trim($reason) : 'Staff-requested deletion';
        $token = self::createVerificationToken(
            (int)$actor['player_id'],
            $targetId,
            'STAFF_DELETE',
            ['reason' => $reason]
        );
        $url = self::verificationUrl('STAFF_DELETE', $token['raw']);

        Mailer::send(
            (string)$actor['email'],
            'Confirm Too Many Coins staff account deletion',
            "Confirm staff account deletion for {$target['handle']} (#{$targetId}):\n\n{$url}\n\nThis link expires at {$token['expires_at']} UTC."
        );
        Notifications::create(
            $targetId,
            'account_security',
            'Account deletion initiated',
            'Staff has initiated an account deletion action for your account.',
            ['severity' => 'danger', 'payload' => ['actor_player_id' => (int)$actor['player_id']]]
        );
        Audit::record((int)$actor['player_id'], $targetId, 'staff_account_delete_request', $reason, $target, ['verification_sent_to_actor' => true]);

        return ['success' => true, 'expires_at' => $token['expires_at']];
    }

    public static function confirmStaffDeletion(array $actor, string $token): array {
        $row = self::findVerificationToken($token, 'STAFF_DELETE');
        if (!$row) return ['error' => 'Verification token is invalid or expired'];
        if ((int)$row['actor_player_id'] !== (int)$actor['player_id']) {
            Audit::record((int)$actor['player_id'], (int)$row['target_player_id'], 'staff_account_delete_confirm_rejected', 'Verification actor mismatch');
            return ['error' => 'Verification token is not valid for this account'];
        }

        $db = Database::getInstance();
        $targetId = (int)$row['target_player_id'];
        $target = $db->fetch("SELECT * FROM players WHERE player_id = ?", [$targetId]);
        if (!$target) return ['error' => 'Player not found'];
        if (!empty($target['profile_deleted_at'])) return ['error' => 'Player is already deleted'];
        if (!Permissions::canActOnTarget($actor, $target)) return ['error' => 'Insufficient permission for target'];

        try {
            $db->beginTransaction();

            $target = $db->fetch("SELECT * FROM players WHERE player_id = ? FOR UPDATE", [$targetId]);
            if (!$target) {
                $db->rollback();
                return ['error' => 'Player not found'];
            }
            if (!empty($target['profile_deleted_at'])) {
                $db->rollback();
                return ['error' => 'Player is already deleted'];
            }
            if (!Permissions::canActOnTarget($actor, $target)) {
                $db->rollback();
                return ['error' => 'Insufficient permission for target'];
            }
            if (!self::consumeVerificationTokenRow($row)) {
                $db->rollback();
                return ['error' => 'Verification token is invalid or expired'];
            }

            $result = self::softDeleteLoadedTarget((int)$actor['player_id'], $target, self::payloadReason($row), 'staff_account_delete_confirm');
            if (!empty($result['error'])) {
                $db->rollback();
                return $result;
            }

            $db->commit();
            return $result;
        } catch (Throwable $e) {
            if ($db->getConnection()->inTransaction()) {
                $db->rollback();
            }
            throw $e;
        }
    }

    public static function softDelete(int $actorId, int $targetId, ?string $reason, string $auditAction): array {
        $db = Database::getInstance();
        $target = $db->fetch("SELECT player_id, handle, email, profile_deleted_at FROM players WHERE player_id = ?", [$targetId]);
        if (!$target) return ['error' => 'Player not found'];
        return self::softDeleteLoadedTarget($actorId, $target, $reason, $auditAction);
    }

    private static function softDeleteLoadedTarget(int $actorId, array $target, ?string $reason, string $auditAction): array {
        $db = Database::getInstance();
        $targetId = (int)$target['player_id'];
        if (!empty($target['profile_deleted_at'])) return ['success' => true, 'already_deleted' => true];
        $stmt = $db->query(
            "UPDATE players
             SET profile_deleted_at = NOW(), profile_deleted_by = ?, profile_deletion_reason = ?,
                 session_token = NULL, online_current = 0
             WHERE player_id = ? AND profile_deleted_at IS NULL",
            [$actorId, $reason, $targetId]
        );
        if ($stmt->rowCount() !== 1) return ['success' => true, 'already_deleted' => true];
        Notifications::create($targetId, 'account_security', 'Account deleted', 'Your account has been deleted.', ['severity' => 'danger']);
        Audit::record($actorId, $targetId, $auditAction, $reason, $target, ['profile_deleted_at' => 'NOW']);
        return ['success' => true];
    }

    private static function payloadReason(array $tokenRow): ?string {
        $payload = !empty($tokenRow['payload_json']) ? json_decode($tokenRow['payload_json'], true) : [];
        return is_array($payload) ? (string)($payload['reason'] ?? '') : null;
    }

    private static function verificationUrl(string $action, string $token): string {
        $base = TMC_PUBLIC_BASE_URL !== '' ? TMC_PUBLIC_BASE_URL : '';
        return $base . '/?screen=account&verify_action=' . rawurlencode($action) . '&token=' . rawurlencode($token);
    }
}
