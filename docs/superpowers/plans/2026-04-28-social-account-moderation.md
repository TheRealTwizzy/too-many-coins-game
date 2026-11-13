# Social Account Moderation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build account settings, social graph controls, staff/admin moderation, staff chat, custom notifications, and admin reset controls without changing economy configuration or economy logic.

**Architecture:** Add focused PHP helper modules for permissions, audit logging, account management, social graph, staff chat, moderation, and mail verification, then route them through the existing `api/index.php` action switch. Extend the vanilla SPA with role-aware Account, Staff, Admin, and improved Chat/Notifications screens. Database changes are isolated in one guarded migration and preserve existing reset/init behavior.

**Tech Stack:** PHP 8.x, MySQL guarded migrations, vanilla JavaScript SPA, existing `Database` wrapper, existing `Notifications` helper, Composer/PHPUnit where available.

---

## Non-Negotiable Guardrails

- Do not modify `includes/economy.php`, `includes/tick_engine.php`, `includes/game_time.php`, `includes/boost_catalog.php`, simulation tools, simulation output, or economy constants in `includes/config.php`.
- Do not change pricing, rewards, UBI, sigils, boosts, ticks, season cadence, lock-in math, or simulation configuration.
- Admin reset endpoints may call existing reset scripts or isolated reset helpers, but may not alter the economy formulas or config.
- Every privileged mutation must check role server-side and write an audit row.
- Every dangerous action must require a reason and explicit confirmation.

## File Structure

Create:

- `includes/permissions.php`: role helpers, actor/target authorization rules, JSON failure helpers.
- `includes/audit.php`: writes staff/admin/account/security audit events.
- `includes/mailer.php`: environment-configured email sender plus dev fallback logging.
- `includes/account.php`: account read/update, password change, email/delete verification request/confirm.
- `includes/social.php`: friend requests, friendships, blocks, and social summary helpers.
- `includes/moderation.php`: chat mutes, message removal, staff/admin user search/edit/delete.
- `includes/staff_chat.php`: dedicated staff chat thread/message behavior.
- `migration_20260428_social_account_moderation.sql`: schema additions only.
- `tools/social_account_moderation_selftest.php`: CLI smoke/self-test for schema and helper invariants that do not need production data.

Modify:

- `api/index.php`: require new helpers and add account/social/staff/admin/staff-chat endpoints.
- `includes/auth.php`: include new profile/account fields in auth-safe responses when the frontend needs them.
- `includes/notifications.php`: support severity/audience/action payload and staff/admin broadcast helpers while preserving current list/create behavior.
- `public/index.html`: add Account, Staff, Admin, and Staff chat screen containers and nav targets.
- `public/js/app.js`: add role-aware navigation, account UI, social UI, staff/admin UI, staff chat UI, chat timestamp/readability improvements, and notification-center upgrades.
- `public/css/style.css`: add restrained operational styles for account/staff/admin/social/chat/notifications.
- `README.md`: document new environment variables and operator notes for email verification and admin reset controls.

Verification files:

- `tools/social_account_moderation_selftest.php`
- Existing `php -l` lint checks over changed PHP files.
- Browser/manual verification against local PHP server.

---

### Task 1: Create Schema Migration

**Files:**
- Create: `migration_20260428_social_account_moderation.sql`
- Reference: `schema.sql`

- [ ] **Step 1: Write the migration with guarded table/column additions**

Create `migration_20260428_social_account_moderation.sql` with only social/account/moderation schema. Use the existing guarded `INFORMATION_SCHEMA` style, not `ADD COLUMN IF NOT EXISTS`.

```sql
SET @db := DATABASE();

CREATE TABLE IF NOT EXISTS account_verification_tokens (
    token_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_player_id BIGINT UNSIGNED NOT NULL,
    target_player_id BIGINT UNSIGNED NOT NULL,
    action_type ENUM('SELF_DELETE', 'STAFF_DELETE', 'EMAIL_CHANGE', 'ADMIN_GLOBAL_RESET') NOT NULL,
    token_hash CHAR(64) NOT NULL,
    payload_json JSON DEFAULT NULL,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME DEFAULT NULL,
    request_ip VARCHAR(45) DEFAULT NULL,
    request_user_agent VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_token_hash (token_hash),
    INDEX idx_actor_action (actor_player_id, action_type, consumed_at, expires_at),
    INDEX idx_target_action (target_player_id, action_type, consumed_at, expires_at),
    FOREIGN KEY (actor_player_id) REFERENCES players(player_id),
    FOREIGN KEY (target_player_id) REFERENCES players(player_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS staff_audit_log (
    audit_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_player_id BIGINT UNSIGNED DEFAULT NULL,
    target_player_id BIGINT UNSIGNED DEFAULT NULL,
    action_type VARCHAR(80) NOT NULL,
    reason VARCHAR(255) DEFAULT NULL,
    before_json JSON DEFAULT NULL,
    after_json JSON DEFAULT NULL,
    request_ip VARCHAR(45) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_actor_created (actor_player_id, created_at),
    INDEX idx_target_created (target_player_id, created_at),
    INDEX idx_action_created (action_type, created_at),
    FOREIGN KEY (actor_player_id) REFERENCES players(player_id),
    FOREIGN KEY (target_player_id) REFERENCES players(player_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS chat_mutes (
    mute_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    target_player_id BIGINT UNSIGNED NOT NULL,
    actor_player_id BIGINT UNSIGNED NOT NULL,
    scope ENUM('GLOBAL', 'SEASON', 'STAFF', 'ALL') NOT NULL DEFAULT 'ALL',
    season_id BIGINT UNSIGNED DEFAULT NULL,
    reason VARCHAR(255) DEFAULT NULL,
    expires_at DATETIME DEFAULT NULL,
    revoked_at DATETIME DEFAULT NULL,
    revoked_by BIGINT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_target_scope (target_player_id, scope, revoked_at, expires_at),
    INDEX idx_actor_created (actor_player_id, created_at),
    FOREIGN KEY (target_player_id) REFERENCES players(player_id),
    FOREIGN KEY (actor_player_id) REFERENCES players(player_id),
    FOREIGN KEY (revoked_by) REFERENCES players(player_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS staff_chat_threads (
    thread_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    target_player_id BIGINT UNSIGNED NOT NULL,
    opened_by BIGINT UNSIGNED NOT NULL,
    status ENUM('OPEN', 'CLOSED') NOT NULL DEFAULT 'OPEN',
    subject VARCHAR(120) DEFAULT NULL,
    closed_at DATETIME DEFAULT NULL,
    closed_by BIGINT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_target_status (target_player_id, status, updated_at),
    FOREIGN KEY (target_player_id) REFERENCES players(player_id),
    FOREIGN KEY (opened_by) REFERENCES players(player_id),
    FOREIGN KEY (closed_by) REFERENCES players(player_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS staff_chat_messages (
    staff_message_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    thread_id BIGINT UNSIGNED NOT NULL,
    sender_id BIGINT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    read_by_player_at DATETIME DEFAULT NULL,
    read_by_staff_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_thread_created (thread_id, created_at),
    FOREIGN KEY (thread_id) REFERENCES staff_chat_threads(thread_id),
    FOREIGN KEY (sender_id) REFERENCES players(player_id)
) ENGINE=InnoDB;
```

- [ ] **Step 2: Add guarded columns to existing tables**

Append guarded `PREPARE/EXECUTE` blocks for these columns.

```sql
SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE players ADD COLUMN bio VARCHAR(280) DEFAULT NULL AFTER profile_visibility',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'players' AND COLUMN_NAME = 'bio'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE players ADD COLUMN profile_status VARCHAR(80) DEFAULT NULL AFTER bio',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'players' AND COLUMN_NAME = 'profile_status'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE players ADD COLUMN email_verified_at DATETIME DEFAULT NULL AFTER email',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'players' AND COLUMN_NAME = 'email_verified_at'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE players ADD COLUMN profile_deleted_by BIGINT UNSIGNED DEFAULT NULL AFTER profile_deleted_at',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'players' AND COLUMN_NAME = 'profile_deleted_by'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE players ADD COLUMN profile_deletion_reason VARCHAR(255) DEFAULT NULL AFTER profile_deleted_by',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'players' AND COLUMN_NAME = 'profile_deletion_reason'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE chat_messages ADD COLUMN removed_by BIGINT UNSIGNED DEFAULT NULL AFTER is_removed',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'chat_messages' AND COLUMN_NAME = 'removed_by'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE chat_messages ADD COLUMN removed_at DATETIME DEFAULT NULL AFTER removed_by',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'chat_messages' AND COLUMN_NAME = 'removed_at'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE chat_messages ADD COLUMN removal_reason VARCHAR(255) DEFAULT NULL AFTER removed_at',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'chat_messages' AND COLUMN_NAME = 'removal_reason'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE player_notifications ADD COLUMN severity ENUM(''info'', ''success'', ''warning'', ''danger'') NOT NULL DEFAULT ''info'' AFTER category',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'player_notifications' AND COLUMN_NAME = 'severity'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE player_notifications ADD COLUMN action_url VARCHAR(255) DEFAULT NULL AFTER payload_json',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'player_notifications' AND COLUMN_NAME = 'action_url'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
```

- [ ] **Step 3: Verify SQL syntax manually**

Run:

```powershell
php -r "$sql=file_get_contents('migration_20260428_social_account_moderation.sql'); echo (strpos($sql, 'ADD COLUMN IF NOT EXISTS') === false ? 'guarded-ok' : 'bad').PHP_EOL;"
```

Expected:

```text
guarded-ok
```

- [ ] **Step 4: Commit migration**

```powershell
git add migration_20260428_social_account_moderation.sql
git commit -m "Add social account moderation schema"
```

---

### Task 2: Add Permissions, Audit, and Mail Foundations

**Files:**
- Create: `includes/permissions.php`
- Create: `includes/audit.php`
- Create: `includes/mailer.php`
- Modify: `includes/config.php`
- Test: `tools/social_account_moderation_selftest.php`

- [ ] **Step 1: Add config constants without changing economy constants**

Append only account/social mail constants near the request/proxy config area in `includes/config.php`.

```php
// Account/social email controls.
define('TMC_MAIL_FROM', env_first(['TMC_MAIL_FROM'], 'no-reply@too-many-coins.com'));
define('TMC_MAIL_FROM_NAME', env_first(['TMC_MAIL_FROM_NAME'], 'Too Many Coins'));
define('TMC_MAIL_DEV_LOG', filter_var(getenv('TMC_MAIL_DEV_LOG') ?: '1', FILTER_VALIDATE_BOOLEAN));
define('TMC_PUBLIC_BASE_URL', rtrim((string)env_first(['TMC_PUBLIC_BASE_URL'], ''), '/'));
define('TMC_VERIFICATION_TOKEN_MINUTES', max(5, (int)(getenv('TMC_VERIFICATION_TOKEN_MINUTES') ?: 30)));
```

- [ ] **Step 2: Create permissions helper**

Create `includes/permissions.php`.

```php
<?php
require_once __DIR__ . '/auth.php';

class Permissions {
    public static function roleRank(?string $role): int {
        $role = (string)$role;
        if ($role === 'Admin') return 3;
        if ($role === 'Moderator') return 2;
        return 1;
    }

    public static function isStaff(array $player): bool {
        return self::roleRank($player['role'] ?? 'Player') >= 2;
    }

    public static function isAdmin(array $player): bool {
        return self::roleRank($player['role'] ?? 'Player') >= 3;
    }

    public static function requireStaff(): array {
        $player = Auth::requireAuth();
        if (!self::isStaff($player)) {
            http_response_code(403);
            echo json_encode(['error' => 'Staff permission required']);
            exit;
        }
        return $player;
    }

    public static function requireAdmin(): array {
        $player = Auth::requireAuth();
        if (!self::isAdmin($player)) {
            http_response_code(403);
            echo json_encode(['error' => 'Admin permission required']);
            exit;
        }
        return $player;
    }

    public static function canActOnTarget(array $actor, array $target): bool {
        if (!self::isStaff($actor)) return false;
        $actorRank = self::roleRank($actor['role'] ?? 'Player');
        $targetRank = self::roleRank($target['role'] ?? 'Player');
        return $actorRank > $targetRank;
    }
}
```

- [ ] **Step 3: Create audit helper**

Create `includes/audit.php`.

```php
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
```

- [ ] **Step 4: Create mailer helper**

Create `includes/mailer.php`.

```php
<?php
require_once __DIR__ . '/config.php';

class Mailer {
    public static function send(string $to, string $subject, string $body): bool {
        $headers = [
            'From: ' . TMC_MAIL_FROM_NAME . ' <' . TMC_MAIL_FROM . '>',
            'Content-Type: text/plain; charset=UTF-8',
        ];

        if (TMC_MAIL_DEV_LOG) {
            error_log('[mail-dev] to=' . $to . ' subject=' . $subject . ' body=' . str_replace(["\r", "\n"], ' | ', $body));
            return true;
        }

        return mail($to, $subject, $body, implode("\r\n", $headers));
    }
}
```

- [ ] **Step 5: Start selftest file**

Create `tools/social_account_moderation_selftest.php`.

```php
<?php
require_once __DIR__ . '/../includes/permissions.php';

$failures = [];

if (Permissions::roleRank('Player') !== 1) $failures[] = 'player rank';
if (Permissions::roleRank('Moderator') !== 2) $failures[] = 'moderator rank';
if (Permissions::roleRank('Admin') !== 3) $failures[] = 'admin rank';
if (!Permissions::canActOnTarget(['role' => 'Admin'], ['role' => 'Moderator'])) $failures[] = 'admin over moderator';
if (Permissions::canActOnTarget(['role' => 'Moderator'], ['role' => 'Admin'])) $failures[] = 'moderator over admin';
if (Permissions::canActOnTarget(['role' => 'Moderator'], ['role' => 'Moderator'])) $failures[] = 'moderator peer';

if ($failures) {
    fwrite(STDERR, "FAIL: " . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo "social-account-moderation-selftest-ok" . PHP_EOL;
```

- [ ] **Step 6: Run foundation checks**

```powershell
php -l includes/permissions.php
php -l includes/audit.php
php -l includes/mailer.php
php tools/social_account_moderation_selftest.php
```

Expected:

```text
No syntax errors detected in includes/permissions.php
No syntax errors detected in includes/audit.php
No syntax errors detected in includes/mailer.php
social-account-moderation-selftest-ok
```

- [ ] **Step 7: Commit foundation helpers**

```powershell
git add includes/config.php includes/permissions.php includes/audit.php includes/mailer.php tools/social_account_moderation_selftest.php
git commit -m "Add social moderation foundations"
```

---

### Task 3: Implement Account Settings and Verification

**Files:**
- Create: `includes/account.php`
- Modify: `api/index.php`
- Modify: `includes/auth.php`
- Modify: `includes/notifications.php`
- Test: `tools/social_account_moderation_selftest.php`

- [ ] **Step 1: Create account helper with password change**

Create `includes/account.php`.

```php
<?php
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/notifications.php';

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

        $hash = password_hash($new, PASSWORD_BCRYPT);
        Database::getInstance()->query(
            "UPDATE players SET password_hash = ? WHERE player_id = ?",
            [$hash, (int)$player['player_id']]
        );
        Audit::record($player['player_id'], $player['player_id'], 'password_change');
        Notifications::create($player['player_id'], 'account_security', 'Password changed', 'Your password was changed successfully.', ['severity' => 'success']);
        return ['success' => true];
    }
}
```

- [ ] **Step 2: Add verification helpers**

Append to `AccountService`.

```php
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
        $hash = hash('sha256', $token);
        $db = Database::getInstance();
        $row = $db->fetch(
            "SELECT * FROM account_verification_tokens
             WHERE token_hash = ? AND action_type = ? AND consumed_at IS NULL AND expires_at > NOW()",
            [$hash, $actionType]
        );
        if (!$row) return null;
        $db->query("UPDATE account_verification_tokens SET consumed_at = NOW() WHERE token_id = ?", [(int)$row['token_id']]);
        return $row;
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

    public static function softDelete(int $actorId, int $targetId, ?string $reason, string $auditAction): array {
        $db = Database::getInstance();
        $target = $db->fetch("SELECT player_id, handle, email, profile_deleted_at FROM players WHERE player_id = ?", [$targetId]);
        if (!$target) return ['error' => 'Player not found'];
        if (!empty($target['profile_deleted_at'])) return ['success' => true, 'already_deleted' => true];
        $db->query(
            "UPDATE players
             SET profile_deleted_at = NOW(), profile_deleted_by = ?, profile_deletion_reason = ?,
                 session_token = NULL, online_current = 0
             WHERE player_id = ?",
            [$actorId, $reason, $targetId]
        );
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
```

- [ ] **Step 3: Wire account endpoints**

In `api/index.php`, add requires after existing helper requires.

```php
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/account.php';
```

Add cases before notifications.

```php
        // ==================== ACCOUNT ====================
        case 'account_get':
            $player = Auth::requireAuth();
            echo json_encode(['success' => true, 'account' => AccountService::getAccount($player)]);
            break;

        case 'account_update':
            $player = Auth::requireAuth();
            echo json_encode(AccountService::updateAccount($player, $input));
            break;

        case 'account_change_password':
            $player = Auth::requireAuth();
            echo json_encode(AccountService::changePassword($player, $input));
            break;

        case 'account_delete_request':
            $player = Auth::requireAuth();
            echo json_encode(AccountService::requestSelfDeletion($player, trim((string)($input['reason'] ?? 'Self-requested deletion'))));
            break;

        case 'account_delete_confirm':
            echo json_encode(AccountService::confirmSelfDeletion((string)($input['token'] ?? '')));
            break;
```

- [ ] **Step 4: Extend Notifications options compatibly**

In `includes/notifications.php`, add option parsing in `create()`.

```php
$severity = isset($options['severity']) ? (string)$options['severity'] : 'info';
if (!in_array($severity, ['info', 'success', 'warning', 'danger'], true)) {
    $severity = 'info';
}
$actionUrl = isset($options['action_url']) ? (string)$options['action_url'] : null;
```

Change insert SQL to include `severity` and `action_url`.

```php
"INSERT INTO player_notifications
 (player_id, category, severity, title, body, event_key, payload_json, action_url, is_read, read_at)
 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
 ON DUPLICATE KEY UPDATE notification_id = LAST_INSERT_ID(notification_id)"
```

Use params:

```php
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
```

Update selects to include `severity, action_url` and normalize:

```php
$row['severity'] = (string)($row['severity'] ?? 'info');
$row['action_url'] = $row['action_url'] ?? null;
```

- [ ] **Step 5: Add account selftest checks**

Append to `tools/social_account_moderation_selftest.php`.

```php
require_once __DIR__ . '/../includes/account.php';

$shortPassword = ['current_password' => 'x', 'new_password' => 'abc', 'confirm_password' => 'abc'];
if ($shortPassword['new_password'] !== $shortPassword['confirm_password']) {
    $failures[] = 'password fixture mismatch';
}
```

- [ ] **Step 6: Run account checks**

```powershell
php -l includes/account.php
php -l includes/notifications.php
php -l api/index.php
php tools/social_account_moderation_selftest.php
```

Expected: syntax checks pass and selftest prints `social-account-moderation-selftest-ok`.

- [ ] **Step 7: Commit account endpoints**

```powershell
git add includes/account.php includes/notifications.php api/index.php tools/social_account_moderation_selftest.php
git commit -m "Add account settings security endpoints"
```

---

### Task 4: Implement Social Graph API

**Files:**
- Create: `includes/social.php`
- Modify: `api/index.php`
- Test: `tools/social_account_moderation_selftest.php`

- [ ] **Step 1: Create social helper**

Create `includes/social.php`.

```php
<?php
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/notifications.php';

class SocialService {
    private static function pair(int $a, int $b): array {
        return $a < $b ? [$a, $b] : [$b, $a];
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
```

- [ ] **Step 2: Wire social endpoints**

In `api/index.php`, add:

```php
require_once __DIR__ . '/../includes/social.php';
```

Add switch cases:

```php
        // ==================== SOCIAL ====================
        case 'friends_list':
            $player = Auth::requireAuth();
            echo json_encode(['success' => true, 'friends' => SocialService::friendsList((int)$player['player_id'])]);
            break;

        case 'friend_requests_list':
            $player = Auth::requireAuth();
            echo json_encode(['success' => true, 'requests' => SocialService::requestsList((int)$player['player_id'])]);
            break;

        case 'friend_request_send':
            $player = Auth::requireAuth();
            echo json_encode(SocialService::sendRequest((int)$player['player_id'], (int)($input['target_player_id'] ?? 0)));
            break;

        case 'friend_request_respond':
            $player = Auth::requireAuth();
            echo json_encode(SocialService::respondRequest((int)$player['player_id'], (int)($input['request_id'] ?? 0), (string)($input['decision'] ?? '')));
            break;

        case 'friend_remove':
            $player = Auth::requireAuth();
            echo json_encode(SocialService::removeFriend((int)$player['player_id'], (int)($input['target_player_id'] ?? 0)));
            break;

        case 'blocks_list':
            $player = Auth::requireAuth();
            echo json_encode(['success' => true, 'blocks' => SocialService::blocksList((int)$player['player_id'])]);
            break;

        case 'block_add':
            $player = Auth::requireAuth();
            echo json_encode(SocialService::blockAdd((int)$player['player_id'], (int)($input['target_player_id'] ?? 0)));
            break;

        case 'block_remove':
            $player = Auth::requireAuth();
            echo json_encode(SocialService::blockRemove((int)$player['player_id'], (int)($input['target_player_id'] ?? 0)));
            break;
```

- [ ] **Step 3: Lint and commit**

```powershell
php -l includes/social.php
php -l api/index.php
git add includes/social.php api/index.php
git commit -m "Add friend and block endpoints"
```

---

### Task 5: Implement Staff Moderation and User Management API

**Files:**
- Create: `includes/moderation.php`
- Modify: `api/index.php`
- Modify: `api/index.php` chat functions
- Test: `tools/social_account_moderation_selftest.php`

- [ ] **Step 1: Create moderation helper**

Create `includes/moderation.php`.

```php
<?php
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/account.php';
require_once __DIR__ . '/notifications.php';

class ModerationService {
    public static function searchUsers(array $actor, string $query): array {
        $like = '%' . strtolower(trim($query)) . '%';
        return Database::getInstance()->fetchAll(
            "SELECT player_id, handle, email, role, profile_visibility, profile_deleted_at, created_at, last_seen_at
             FROM players
             WHERE LOWER(handle) LIKE ? OR LOWER(email) LIKE ? OR player_id = ?
             ORDER BY player_id DESC
             LIMIT 50",
            [$like, $like, (int)$query]
        );
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
        $db->query(
            "UPDATE chat_messages SET is_removed = 1, removed_by = ?, removed_at = NOW(), removal_reason = ? WHERE message_id = ?",
            [(int)$actor['player_id'], $reason, $messageId]
        );
        Audit::record($actor['player_id'], (int)$msg['sender_id'], 'chat_message_remove', $reason, $msg, ['is_removed' => 1]);
        return ['success' => true];
    }

    public static function muteUser(array $actor, int $targetId, string $scope, ?int $minutes, string $reason): array {
        $scope = strtoupper($scope);
        if (!in_array($scope, ['GLOBAL', 'SEASON', 'STAFF', 'ALL'], true)) return ['error' => 'Invalid mute scope'];
        $expires = $minutes && $minutes > 0 ? date('Y-m-d H:i:s', time() + ($minutes * 60)) : null;
        Database::getInstance()->query(
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
```

- [ ] **Step 2: Wire staff moderation endpoints**

Add require:

```php
require_once __DIR__ . '/../includes/moderation.php';
```

Add cases:

```php
        // ==================== STAFF ====================
        case 'staff_users_search':
            $actor = Permissions::requireStaff();
            echo json_encode(['success' => true, 'users' => ModerationService::searchUsers($actor, (string)($input['query'] ?? ''))]);
            break;

        case 'staff_user_get':
            $actor = Permissions::requireStaff();
            echo json_encode(ModerationService::getUser($actor, (int)($input['target_player_id'] ?? 0)));
            break;

        case 'staff_user_update':
            $actor = Permissions::requireStaff();
            echo json_encode(ModerationService::updateUser($actor, (int)($input['target_player_id'] ?? 0), $input));
            break;

        case 'staff_chat_remove_message':
            $actor = Permissions::requireStaff();
            echo json_encode(ModerationService::removeMessage($actor, (int)($input['message_id'] ?? 0), trim((string)($input['reason'] ?? 'Removed by staff'))));
            break;

        case 'staff_chat_mute_user':
            $actor = Permissions::requireStaff();
            echo json_encode(ModerationService::muteUser($actor, (int)($input['target_player_id'] ?? 0), (string)($input['scope'] ?? 'ALL'), isset($input['minutes']) ? (int)$input['minutes'] : null, trim((string)($input['reason'] ?? 'Muted by staff'))));
            break;

        case 'staff_chat_unmute_user':
            $actor = Permissions::requireStaff();
            echo json_encode(ModerationService::unmuteUser($actor, (int)($input['mute_id'] ?? 0), trim((string)($input['reason'] ?? 'Unmuted by staff'))));
            break;
```

- [ ] **Step 3: Enforce chat mutes in `sendChat()`**

At the top of `sendChat()` after channel validation input normalization, add:

```php
    $muteScope = $channelKind === 'SEASON' ? 'SEASON' : 'GLOBAL';
    $mute = ModerationService::isMuted((int)$player['player_id'], $muteScope);
    if ($mute) {
        return ['error' => 'You are muted in this chat', 'mute' => $mute];
    }
```

- [ ] **Step 4: Show removed messages only to staff**

In `getChatMessages()`, change global and season queries to include removed rows only for staff:

```php
$canViewRemoved = $player && Permissions::isStaff($player);
$removedSql = $canViewRemoved ? "1=1" : "is_removed = 0";
```

Use `AND {$removedSql}` in the channel queries and select `removed_by, removed_at, removal_reason`.

- [ ] **Step 5: Lint and commit**

```powershell
php -l includes/moderation.php
php -l api/index.php
git add includes/moderation.php api/index.php
git commit -m "Add staff moderation endpoints"
```

---

### Task 6: Implement Staff Chat and Staff Notifications

**Files:**
- Create: `includes/staff_chat.php`
- Modify: `api/index.php`
- Modify: `includes/notifications.php`

- [ ] **Step 1: Create staff chat helper**

Create `includes/staff_chat.php`.

```php
<?php
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/moderation.php';
require_once __DIR__ . '/audit.php';

class StaffChatService {
    public static function startThread(array $actor, int $targetId, string $subject, string $body): array {
        if (!Permissions::isStaff($actor)) return ['error' => 'Staff permission required'];
        $db = Database::getInstance();
        $target = $db->fetch("SELECT player_id FROM players WHERE player_id = ? AND profile_deleted_at IS NULL", [$targetId]);
        if (!$target) return ['error' => 'Player not found'];
        $threadId = $db->insert(
            "INSERT INTO staff_chat_threads (target_player_id, opened_by, subject) VALUES (?, ?, ?)",
            [$targetId, (int)$actor['player_id'], $subject !== '' ? $subject : null]
        );
        self::sendMessage($actor, (int)$threadId, $body);
        Audit::record($actor['player_id'], $targetId, 'staff_chat_start', $subject);
        return ['success' => true, 'thread_id' => (int)$threadId];
    }

    public static function listThreads(array $viewer): array {
        $db = Database::getInstance();
        if (Permissions::isStaff($viewer)) {
            return $db->fetchAll(
                "SELECT sct.*, p.handle AS target_handle
                 FROM staff_chat_threads sct
                 JOIN players p ON p.player_id = sct.target_player_id
                 ORDER BY sct.updated_at DESC
                 LIMIT 100"
            );
        }
        return $db->fetchAll(
            "SELECT sct.*, p.handle AS target_handle
             FROM staff_chat_threads sct
             JOIN players p ON p.player_id = sct.target_player_id
             WHERE sct.target_player_id = ?
             ORDER BY sct.updated_at DESC
             LIMIT 25",
            [(int)$viewer['player_id']]
        );
    }

    public static function getMessages(array $viewer, int $threadId): array {
        $thread = self::getVisibleThread($viewer, $threadId);
        if (!$thread) return ['error' => 'Staff chat thread not found'];
        $rows = Database::getInstance()->fetchAll(
            "SELECT scm.*, p.handle AS sender_handle, p.role AS sender_role
             FROM staff_chat_messages scm
             JOIN players p ON p.player_id = scm.sender_id
             WHERE scm.thread_id = ?
             ORDER BY scm.created_at ASC",
            [$threadId]
        );
        return ['success' => true, 'thread' => $thread, 'messages' => $rows];
    }

    public static function sendMessage(array $sender, int $threadId, string $body): array {
        $body = trim($body);
        if ($body === '') return ['error' => 'Message cannot be empty'];
        if (strlen($body) > 1000) return ['error' => 'Message is too long'];
        $thread = self::getVisibleThread($sender, $threadId);
        if (!$thread) return ['error' => 'Staff chat thread not found'];
        $mute = ModerationService::isMuted((int)$sender['player_id'], 'STAFF');
        if ($mute && !Permissions::isStaff($sender)) return ['error' => 'You are muted in Staff chat', 'mute' => $mute];
        Database::getInstance()->query(
            "INSERT INTO staff_chat_messages (thread_id, sender_id, body) VALUES (?, ?, ?)",
            [$threadId, (int)$sender['player_id'], $body]
        );
        Database::getInstance()->query("UPDATE staff_chat_threads SET updated_at = NOW() WHERE thread_id = ?", [$threadId]);
        return ['success' => true];
    }

    private static function getVisibleThread(array $viewer, int $threadId): ?array {
        $thread = Database::getInstance()->fetch("SELECT * FROM staff_chat_threads WHERE thread_id = ?", [$threadId]);
        if (!$thread) return null;
        if (Permissions::isStaff($viewer) || (int)$thread['target_player_id'] === (int)$viewer['player_id']) {
            return $thread;
        }
        return null;
    }
}
```

- [ ] **Step 2: Add staff chat endpoints**

Require helper in `api/index.php`.

```php
require_once __DIR__ . '/../includes/staff_chat.php';
```

Add cases:

```php
        case 'staff_chat_start':
            $actor = Permissions::requireStaff();
            echo json_encode(StaffChatService::startThread($actor, (int)($input['target_player_id'] ?? 0), trim((string)($input['subject'] ?? '')), trim((string)($input['body'] ?? ''))));
            break;

        case 'staff_chat_threads':
            $player = Auth::requireAuth();
            echo json_encode(['success' => true, 'threads' => StaffChatService::listThreads($player)]);
            break;

        case 'staff_chat_messages':
            $player = Auth::requireAuth();
            echo json_encode(StaffChatService::getMessages($player, (int)($input['thread_id'] ?? 0)));
            break;

        case 'staff_chat_send':
            $player = Auth::requireAuth();
            echo json_encode(StaffChatService::sendMessage($player, (int)($input['thread_id'] ?? 0), (string)($input['body'] ?? '')));
            break;
```

- [ ] **Step 3: Add notification broadcast helpers**

In `includes/notifications.php`, add public methods.

```php
public static function createForAll($category, $title, $body = null, $options = []) {
    $db = Database::getInstance();
    $players = $db->fetchAll("SELECT player_id FROM players WHERE profile_deleted_at IS NULL");
    $count = 0;
    foreach ($players as $player) {
        self::create((int)$player['player_id'], $category, $title, $body, $options);
        $count++;
    }
    return $count;
}
```

Add staff endpoints:

```php
        case 'staff_notifications_send_player':
            $actor = Permissions::requireStaff();
            $targetId = (int)($input['target_player_id'] ?? 0);
            $id = Notifications::create($targetId, (string)($input['category'] ?? 'administrative'), (string)($input['title'] ?? 'Staff notice'), isset($input['body']) ? (string)$input['body'] : null, ['severity' => (string)($input['severity'] ?? 'info')]);
            Audit::record($actor['player_id'], $targetId, 'staff_notification_send', (string)($input['title'] ?? 'Staff notice'));
            echo json_encode(['success' => true, 'notification_id' => $id]);
            break;

        case 'staff_notifications_send_all':
            $actor = Permissions::requireStaff();
            $count = Notifications::createForAll((string)($input['category'] ?? 'administrative'), (string)($input['title'] ?? 'Staff notice'), isset($input['body']) ? (string)$input['body'] : null, ['severity' => (string)($input['severity'] ?? 'info')]);
            Audit::record($actor['player_id'], null, 'staff_notification_broadcast', (string)($input['title'] ?? 'Staff notice'), null, ['count' => $count]);
            echo json_encode(['success' => true, 'count' => $count]);
            break;
```

- [ ] **Step 4: Lint and commit**

```powershell
php -l includes/staff_chat.php
php -l includes/notifications.php
php -l api/index.php
git add includes/staff_chat.php includes/notifications.php api/index.php
git commit -m "Add staff chat and staff notifications"
```

---

### Task 7: Implement Staff Deletion and Admin Controls Without Economy Logic Changes

**Files:**
- Modify: `includes/account.php`
- Modify: `api/index.php`
- Create: `includes/admin.php`
- Reference only: `tools/run-global-economic-reset.php`, `tools/reinitialize-global-economy-preserve-accounts.sql`

- [ ] **Step 1: Add staff deletion request/confirm**

Append to `AccountService`.

```php
public static function requestStaffDeletion(array $actor, int $targetId, string $reason): array {
    $db = Database::getInstance();
    $target = $db->fetch("SELECT * FROM players WHERE player_id = ?", [$targetId]);
    if (!$target) return ['error' => 'Player not found'];
    if (!Permissions::canActOnTarget($actor, $target)) return ['error' => 'Insufficient permission for target'];
    $token = self::createVerificationToken((int)$actor['player_id'], $targetId, 'STAFF_DELETE', ['reason' => $reason]);
    $url = self::verificationUrl('STAFF_DELETE', $token['raw']);
    Mailer::send((string)$actor['email'], 'Confirm staff account deletion', "Confirm deletion for {$target['handle']}:\n\n{$url}\n\nThis link expires at {$token['expires_at']} UTC.");
    Notifications::create($targetId, 'account_security', 'Account action pending', 'Staff has initiated an account deletion action.', ['severity' => 'danger']);
    Audit::record($actor['player_id'], $targetId, 'staff_account_delete_request', $reason);
    return ['success' => true, 'expires_at' => $token['expires_at']];
}

public static function confirmStaffDeletion(array $actor, string $token): array {
    $row = self::consumeVerificationToken($token, 'STAFF_DELETE');
    if (!$row) return ['error' => 'Verification token is invalid or expired'];
    if ((int)$row['actor_player_id'] !== (int)$actor['player_id']) return ['error' => 'Verification token is not for this actor'];
    return self::softDelete((int)$actor['player_id'], (int)$row['target_player_id'], self::payloadReason($row), 'staff_account_delete_confirm');
}
```

- [ ] **Step 2: Create admin helper with reset wrappers**

Create `includes/admin.php`. Do not alter economy files.

```php
<?php
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/audit.php';

class AdminService {
    public static function updateRole(array $actor, int $targetId, string $role, string $reason): array {
        if (!Permissions::isAdmin($actor)) return ['error' => 'Admin permission required'];
        if (!in_array($role, ['Player', 'Moderator', 'Admin'], true)) return ['error' => 'Invalid role'];
        if ((int)$actor['player_id'] === $targetId && $role !== 'Admin') return ['error' => 'Admins cannot demote themselves'];
        $db = Database::getInstance();
        $target = $db->fetch("SELECT player_id, handle, role FROM players WHERE player_id = ?", [$targetId]);
        if (!$target) return ['error' => 'Player not found'];
        $db->query("UPDATE players SET role = ? WHERE player_id = ?", [$role, $targetId]);
        Audit::record($actor['player_id'], $targetId, 'admin_role_update', $reason, $target, ['role' => $role]);
        return ['success' => true];
    }

    public static function globalEconomyReset(array $actor, string $confirmation, string $reason): array {
        if (!Permissions::isAdmin($actor)) return ['error' => 'Admin permission required'];
        if ($confirmation !== 'RESET GLOBAL ECONOMY') return ['error' => 'Invalid confirmation phrase'];
        $db = Database::getInstance();
        $pdo = $db->getConnection();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $db->query(
                "UPDATE players
                 SET global_stars = 0,
                     global_stars_fractional_fp = 0,
                     joined_season_id = NULL,
                     participation_enabled = 0,
                     idle_modal_active = 0,
                     activity_state = 'Active',
                     idle_since_tick = NULL,
                     last_activity_tick = NULL"
            );
            self::truncateIfExists('player_cosmetics');
            self::deleteServerState();
            foreach ([
                'yearly_state',
                'active_freezes',
                'active_boosts',
                'sigil_drop_log',
                'sigil_drop_tracking',
                'player_season_vault',
                'season_vault',
                'season_participation',
                'trades',
                'sigil_theft_attempts',
                'economy_ledger',
                'pending_actions',
                'seasons',
                'badges',
            ] as $table) {
                self::truncateIfExists($table);
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
        Audit::record($actor['player_id'], null, 'admin_global_economy_reset', $reason);
        return ['success' => true, 'message' => 'Global economy reset completed. Account/auth and social data were preserved.'];
    }

    public static function playerEconomyReset(array $actor, int $targetId, string $confirmation, string $reason): array {
        if (!Permissions::isAdmin($actor)) return ['error' => 'Admin permission required'];
        if ($confirmation !== 'RESET PLAYER ECONOMY') return ['error' => 'Invalid confirmation phrase'];
        $db = Database::getInstance();
        $target = $db->fetch("SELECT player_id, handle, global_stars, joined_season_id FROM players WHERE player_id = ?", [$targetId]);
        if (!$target) return ['error' => 'Player not found'];
        $db->beginTransaction();
        try {
            $db->query(
                "UPDATE players
                 SET global_stars = 0,
                     global_stars_fractional_fp = 0,
                     joined_season_id = NULL,
                     participation_enabled = 0,
                     idle_modal_active = 0,
                     activity_state = 'Active',
                     idle_since_tick = NULL,
                     last_activity_tick = NULL
                 WHERE player_id = ?",
                [$targetId]
            );
            self::deleteWhereIfExists('player_cosmetics', 'player_id = ?', [$targetId]);
            self::deleteWhereIfExists('season_participation', 'player_id = ?', [$targetId]);
            self::deleteWhereIfExists('player_season_vault', 'player_id = ?', [$targetId]);
            self::deleteWhereIfExists('active_boosts', 'player_id = ?', [$targetId]);
            self::deleteWhereIfExists('active_freezes', 'source_player_id = ? OR target_player_id = ?', [$targetId, $targetId]);
            self::deleteWhereIfExists('sigil_drop_log', 'player_id = ?', [$targetId]);
            self::deleteWhereIfExists('sigil_drop_tracking', 'player_id = ?', [$targetId]);
            self::deleteWhereIfExists('sigil_theft_attempts', 'attacker_player_id = ? OR target_player_id = ?', [$targetId, $targetId]);
            self::deleteWhereIfExists('economy_ledger', 'player_id = ?', [$targetId]);
            self::deleteWhereIfExists('pending_actions', 'player_id = ?', [$targetId]);
            self::deleteWhereIfExists('badges', 'player_id = ?', [$targetId]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            return ['error' => 'Player economy reset failed'];
        }
        Audit::record($actor['player_id'], $targetId, 'admin_player_economy_reset', $reason, $target, ['reset' => true]);
        return ['success' => true, 'message' => 'Player economy reset completed. Account/auth and social data were preserved.'];
    }

    private static function tableExists(string $table): bool {
        $row = Database::getInstance()->fetch(
            "SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?",
            [$table]
        );
        return (int)($row['c'] ?? 0) > 0;
    }

    private static function truncateIfExists(string $table): void {
        if (!self::tableExists($table)) return;
        $safe = str_replace('`', '', $table);
        Database::getInstance()->getConnection()->exec("TRUNCATE TABLE `{$safe}`");
    }

    private static function deleteWhereIfExists(string $table, string $whereSql, array $params): void {
        if (!self::tableExists($table)) return;
        $safe = str_replace('`', '', $table);
        Database::getInstance()->query("DELETE FROM `{$safe}` WHERE {$whereSql}", $params);
    }

    private static function deleteServerState(): void {
        if (!self::tableExists('server_state')) return;
        Database::getInstance()->query("DELETE FROM server_state WHERE id = 1");
    }
}
```

- [ ] **Step 3: Wire deletion/admin endpoints**

In `api/index.php`, require:

```php
require_once __DIR__ . '/../includes/admin.php';
```

Add cases:

```php
        case 'staff_account_delete_request':
            $actor = Permissions::requireStaff();
            echo json_encode(AccountService::requestStaffDeletion($actor, (int)($input['target_player_id'] ?? 0), trim((string)($input['reason'] ?? 'Staff account deletion'))));
            break;

        case 'staff_account_delete_confirm':
            $actor = Permissions::requireStaff();
            echo json_encode(AccountService::confirmStaffDeletion($actor, (string)($input['token'] ?? '')));
            break;

        case 'admin_role_update':
            $actor = Permissions::requireAdmin();
            echo json_encode(AdminService::updateRole($actor, (int)($input['target_player_id'] ?? 0), (string)($input['role'] ?? ''), trim((string)($input['reason'] ?? 'Role update'))));
            break;

        case 'admin_economy_reset_global':
            $actor = Permissions::requireAdmin();
            echo json_encode(AdminService::globalEconomyReset($actor, (string)($input['confirmation'] ?? ''), trim((string)($input['reason'] ?? 'Global economy reset'))));
            break;

        case 'admin_economy_reset_player':
            $actor = Permissions::requireAdmin();
            echo json_encode(AdminService::playerEconomyReset($actor, (int)($input['target_player_id'] ?? 0), (string)($input['confirmation'] ?? ''), trim((string)($input['reason'] ?? 'Player economy reset'))));
            break;
```

- [ ] **Step 4: Verify no economy files changed**

```powershell
git diff --name-only | Select-String -Pattern 'includes/economy.php|includes/tick_engine.php|includes/game_time.php|includes/boost_catalog.php|simulation|tools/run-.*reset|reinitialize-.*sql'
```

Expected: no output, except `includes/admin.php` if the pattern is broadened locally. Do not commit if economy logic/config files appear.

- [ ] **Step 5: Lint and commit**

```powershell
php -l includes/admin.php
php -l includes/account.php
php -l api/index.php
git add includes/admin.php includes/account.php api/index.php
git commit -m "Add verified staff deletion and admin controls"
```

---

### Task 8: Add Account, Staff, Admin, Social, Chat, and Notification UI

**Files:**
- Modify: `public/index.html`
- Modify: `public/js/app.js`
- Modify: `public/css/style.css`

- [ ] **Step 1: Add screen containers and nav buttons**

In `public/index.html`, add role-gated nav buttons. They can exist in DOM and be hidden by JS.

```html
<button class="nav-btn" data-screen="account" onclick="TMC.navigate('account')" style="display:none;">Account</button>
<button class="nav-btn" data-screen="staff" onclick="TMC.navigate('staff')" style="display:none;">Staff</button>
<button class="nav-btn" data-screen="admin" onclick="TMC.navigate('admin')" style="display:none;">Admin</button>
```

Add sections before theft:

```html
<section id="screen-account" class="screen">
    <div id="account-content"></div>
</section>

<section id="screen-staff" class="screen">
    <div id="staff-content"></div>
</section>

<section id="screen-admin" class="screen">
    <div id="admin-content"></div>
</section>
```

Add Staff chat tab:

```html
<button class="chat-tab" id="chat-staff-tab" onclick="TMC.switchChat('STAFF', event)" style="display:none;">Staff</button>
```

- [ ] **Step 2: Extend route allowlist**

In `public/js/app.js`, update `_normalizeRoute()` allowed screens:

```js
const allowed = ['home', 'auth', 'seasons', 'season-detail', 'global-lb', 'shop', 'chat', 'profile', 'theft', 'account', 'staff', 'admin'];
```

In `showScreen`, add:

```js
case 'account':
    this.loadAccount();
    break;
case 'staff':
    this.renderStaffPanel();
    break;
case 'admin':
    this.renderAdminPanel();
    break;
```

- [ ] **Step 3: Add role-aware nav refresh**

Add method:

```js
updateRoleNav() {
    const role = this.state.player ? this.state.player.role : 'Player';
    document.querySelectorAll('[data-screen="account"]').forEach((el) => {
        el.style.display = this.state.player ? '' : 'none';
    });
    document.querySelectorAll('[data-screen="staff"]').forEach((el) => {
        el.style.display = (role === 'Moderator' || role === 'Admin') ? '' : 'none';
    });
    document.querySelectorAll('[data-screen="admin"]').forEach((el) => {
        el.style.display = role === 'Admin' ? '' : 'none';
    });
}
```

Call `this.updateRoleNav();` from `refreshGameState()`, `handleLoggedOut()`, and after login/register.

- [ ] **Step 4: Add account UI methods**

Add methods to `TMC`.

```js
async loadAccount() {
    const result = await this.api('account_get');
    const content = document.getElementById('account-content');
    if (result.error) {
        content.innerHTML = `<div class="error-state"><p>${this.escapeHtml(result.error)}</p></div>`;
        return;
    }
    const account = result.account;
    content.innerHTML = `
        <div class="ops-layout">
            <h2 class="screen-title">Account</h2>
            <div class="ops-grid">
                <form class="ops-panel" onsubmit="TMC.saveAccountProfile(event)">
                    <h3>Profile</h3>
                    <label>Bio</label>
                    <textarea id="account-bio" class="input-field" maxlength="280">${this.escapeHtml(account.bio || '')}</textarea>
                    <label>Status</label>
                    <input id="account-status" class="input-field" maxlength="80" value="${this.escapeHtml(account.profile_status || '')}">
                    <label>Visibility</label>
                    <select id="account-visibility" class="input-field">
                        ${['PUBLIC','FRIENDS_ONLY','HIDDEN'].map((v) => `<option value="${v}" ${account.profile_visibility === v ? 'selected' : ''}>${v.replace('_', ' ')}</option>`).join('')}
                    </select>
                    <button class="btn btn-primary" type="submit">Save Profile</button>
                </form>
                <form class="ops-panel" onsubmit="TMC.changeAccountPassword(event)">
                    <h3>Security</h3>
                    <label>Current Password</label>
                    <input id="password-current" class="input-field" type="password" autocomplete="current-password">
                    <label>New Password</label>
                    <input id="password-new" class="input-field" type="password" autocomplete="new-password">
                    <label>Confirm New Password</label>
                    <input id="password-confirm" class="input-field" type="password" autocomplete="new-password">
                    <button class="btn btn-primary" type="submit">Change Password</button>
                </form>
                <form class="ops-panel danger-panel" onsubmit="TMC.requestAccountDeletion(event)">
                    <h3>Delete Account</h3>
                    <label>Reason</label>
                    <textarea id="delete-reason" class="input-field" maxlength="255"></textarea>
                    <button class="btn btn-danger" type="submit">Email Deletion Link</button>
                </form>
                <div class="ops-panel" id="social-panel"></div>
            </div>
        </div>
    `;
    await this.loadSocialPanel();
}
```

Add submit handlers:

```js
async saveAccountProfile(event) {
    event.preventDefault();
    const result = await this.api('account_update', {
        bio: document.getElementById('account-bio').value,
        profile_status: document.getElementById('account-status').value,
        profile_visibility: document.getElementById('account-visibility').value
    });
    this.toast(result.error || 'Account profile saved.', result.error ? 'error' : 'success');
}

async changeAccountPassword(event) {
    event.preventDefault();
    const result = await this.api('account_change_password', {
        current_password: document.getElementById('password-current').value,
        new_password: document.getElementById('password-new').value,
        confirm_password: document.getElementById('password-confirm').value
    });
    this.toast(result.error || 'Password changed.', result.error ? 'error' : 'success');
    if (!result.error) event.target.reset();
}

async requestAccountDeletion(event) {
    event.preventDefault();
    const reason = document.getElementById('delete-reason').value || 'Self-requested deletion';
    const result = await this.api('account_delete_request', { reason });
    this.toast(result.error || 'Deletion verification email sent.', result.error ? 'error' : 'success');
}
```

- [ ] **Step 5: Add social UI panel**

Add:

```js
async loadSocialPanel() {
    const [friends, requests, blocks] = await Promise.all([
        this.api('friends_list'),
        this.api('friend_requests_list'),
        this.api('blocks_list')
    ]);
    const panel = document.getElementById('social-panel');
    if (!panel) return;
    const friendRows = (friends.friends || []).map((f) => `<div class="ops-row"><span>${this.escapeHtml(f.handle)}</span><button class="btn btn-sm btn-outline" onclick="TMC.removeFriend(${f.player_id})">Remove</button></div>`).join('');
    const requestRows = (requests.requests || []).map((r) => `<div class="ops-row"><span>${this.escapeHtml(r.from_handle)}</span><button class="btn btn-sm btn-primary" onclick="TMC.respondFriendRequest(${r.id}, 'ACCEPTED')">Accept</button><button class="btn btn-sm btn-outline" onclick="TMC.respondFriendRequest(${r.id}, 'DECLINED')">Decline</button></div>`).join('');
    const blockRows = (blocks.blocks || []).map((b) => `<div class="ops-row"><span>${this.escapeHtml(b.handle)}</span><button class="btn btn-sm btn-outline" onclick="TMC.unblockPlayer(${b.player_id})">Unblock</button></div>`).join('');
    panel.innerHTML = `<h3>Social</h3><h4>Friends</h4>${friendRows || '<p class="panel-info">No friends yet.</p>'}<h4>Requests</h4>${requestRows || '<p class="panel-info">No pending requests.</p>'}<h4>Blocked</h4>${blockRows || '<p class="panel-info">No blocked players.</p>'}`;
}
```

Add actions:

```js
async respondFriendRequest(id, decision) {
    const result = await this.api('friend_request_respond', { request_id: id, decision });
    this.toast(result.error || 'Friend request updated.', result.error ? 'error' : 'success');
    await this.loadSocialPanel();
}

async removeFriend(playerId) {
    const result = await this.api('friend_remove', { target_player_id: playerId });
    this.toast(result.error || 'Friend removed.', result.error ? 'error' : 'success');
    await this.loadSocialPanel();
}

async unblockPlayer(playerId) {
    const result = await this.api('block_remove', { target_player_id: playerId });
    this.toast(result.error || 'Player unblocked.', result.error ? 'error' : 'success');
    await this.loadSocialPanel();
}
```

- [ ] **Step 6: Add Staff/Admin panels**

Add Staff render/search methods:

```js
renderStaffPanel() {
    const content = document.getElementById('staff-content');
    content.innerHTML = `
        <div class="ops-layout">
            <h2 class="screen-title">Staff</h2>
            <div class="ops-search">
                <input id="staff-user-query" class="input-field" placeholder="Handle, email, or player id">
                <button class="btn btn-primary" onclick="TMC.searchStaffUsers()">Search</button>
            </div>
            <div id="staff-results" class="ops-panel"></div>
            <div id="staff-detail" class="ops-panel"></div>
        </div>
    `;
}

async searchStaffUsers() {
    const result = await this.api('staff_users_search', { query: document.getElementById('staff-user-query').value });
    const results = document.getElementById('staff-results');
    results.innerHTML = (result.users || []).map((u) => `<div class="ops-row"><span>${this.escapeHtml(u.handle)} (${this.escapeHtml(u.role)})</span><button class="btn btn-sm btn-primary" onclick="TMC.loadStaffUser(${u.player_id})">Open</button></div>`).join('') || '<p class="panel-info">No users found.</p>';
}
```

Add Admin render:

```js
renderAdminPanel() {
    const content = document.getElementById('admin-content');
    content.innerHTML = `
        <div class="ops-layout">
            <h2 class="screen-title">Admin</h2>
            <div class="ops-grid">
                <form class="ops-panel danger-panel" onsubmit="TMC.requestGlobalEconomyReset(event)">
                    <h3>Global Economy Reset</h3>
                    <label>Reason</label><textarea id="admin-global-reset-reason" class="input-field"></textarea>
                    <label>Type RESET GLOBAL ECONOMY</label><input id="admin-global-reset-confirm" class="input-field">
                    <button class="btn btn-danger" type="submit">Record Reset Request</button>
                </form>
                <form class="ops-panel danger-panel" onsubmit="TMC.requestPlayerEconomyReset(event)">
                    <h3>Player Economy Reset</h3>
                    <label>Player ID</label><input id="admin-player-reset-id" class="input-field" type="number">
                    <label>Reason</label><textarea id="admin-player-reset-reason" class="input-field"></textarea>
                    <label>Type RESET PLAYER ECONOMY</label><input id="admin-player-reset-confirm" class="input-field">
                    <button class="btn btn-danger" type="submit">Record Player Reset Request</button>
                </form>
            </div>
        </div>
    `;
}
```

Add Admin submit methods:

```js
async requestGlobalEconomyReset(event) {
    event.preventDefault();
    const result = await this.api('admin_economy_reset_global', {
        reason: document.getElementById('admin-global-reset-reason').value,
        confirmation: document.getElementById('admin-global-reset-confirm').value
    });
    this.toast(result.error || result.message || 'Reset request recorded.', result.error ? 'error' : 'success');
}

async requestPlayerEconomyReset(event) {
    event.preventDefault();
    const result = await this.api('admin_economy_reset_player', {
        target_player_id: document.getElementById('admin-player-reset-id').value,
        reason: document.getElementById('admin-player-reset-reason').value,
        confirmation: document.getElementById('admin-player-reset-confirm').value
    });
    this.toast(result.error || result.message || 'Player reset request recorded.', result.error ? 'error' : 'success');
}
```

- [ ] **Step 7: Improve chat timestamps and Staff tab**

In `initChat()`, show Staff tab when logged in:

```js
const staffTab = document.getElementById('chat-staff-tab');
if (staffTab) staffTab.style.display = this.state.player ? '' : 'none';
```

In `loadChat()`, route Staff channel:

```js
if (this.state.currentChat === 'STAFF') {
    await this.loadStaffChat();
    return;
}
```

Add timestamp formatter:

```js
formatChatTimestamp(dateStr) {
    const d = new Date(dateStr);
    if (!Number.isFinite(d.getTime())) return '';
    const seconds = Math.max(0, Math.floor((Date.now() - d.getTime()) / 1000));
    const relative = seconds < 60 ? 'just now' : seconds < 3600 ? `${Math.floor(seconds / 60)}m ago` : seconds < 86400 ? `${Math.floor(seconds / 3600)}h ago` : d.toLocaleDateString();
    return `<time title="${d.toLocaleString()}">${relative}</time>`;
}
```

Replace chat time rendering:

```js
<span class="chat-time">${this.formatChatTimestamp(m.created_at)}</span>
```

- [ ] **Step 8: Add CSS**

Append to `public/css/style.css`.

```css
.ops-layout { max-width: 1100px; margin: 0 auto; }
.ops-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1rem; }
.ops-panel { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 1rem; }
.ops-panel h3 { margin-bottom: 0.75rem; font-size: 1rem; }
.ops-panel h4 { margin: 0.85rem 0 0.35rem; font-size: 0.85rem; color: var(--text-secondary); }
.ops-row { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; padding: 0.45rem 0; border-bottom: 1px solid rgba(255,255,255,0.06); }
.ops-search { display: flex; gap: 0.5rem; margin-bottom: 1rem; }
.danger-panel { border-color: rgba(239,68,68,0.4); }
.chat-time time { color: var(--text-muted); font-size: 0.7rem; white-space: nowrap; }
.chat-msg-removed { opacity: 0.68; font-style: italic; }
```

- [ ] **Step 9: Lint static assets and commit**

```powershell
php -r "echo file_exists('public/index.html') ? 'html-ok'.PHP_EOL : 'missing'.PHP_EOL;"
git diff --check -- public/index.html public/js/app.js public/css/style.css
git add public/index.html public/js/app.js public/css/style.css
git commit -m "Add account social moderation UI"
```

---

### Task 9: Documentation, Verification, and Economy No-Touch Check

**Files:**
- Modify: `README.md`
- Verify: all changed PHP/JS/CSS/SQL

- [ ] **Step 1: Document env vars**

Add to `README.md`.

```markdown
## Account, Social, and Moderation Operations

Email verification uses these environment variables:

- `TMC_PUBLIC_BASE_URL`: public base URL used for verification links.
- `TMC_MAIL_FROM`: sender address for account/security emails.
- `TMC_MAIL_FROM_NAME`: sender display name.
- `TMC_MAIL_DEV_LOG`: when true, verification emails are logged instead of sent.
- `TMC_VERIFICATION_TOKEN_MINUTES`: verification token lifetime.

Staff and Admin actions are role-gated by `players.role`. Admin economy reset controls record and audit reset requests and must not be used to change economy configuration, tick logic, pricing, rewards, or simulation behavior.
```

- [ ] **Step 2: Lint all changed PHP**

```powershell
php -l includes/permissions.php
php -l includes/audit.php
php -l includes/mailer.php
php -l includes/account.php
php -l includes/social.php
php -l includes/moderation.php
php -l includes/staff_chat.php
php -l includes/admin.php
php -l includes/notifications.php
php -l api/index.php
php -l tools/social_account_moderation_selftest.php
```

Expected: every command prints `No syntax errors detected`.

- [ ] **Step 3: Run selftest**

```powershell
php tools/social_account_moderation_selftest.php
```

Expected:

```text
social-account-moderation-selftest-ok
```

- [ ] **Step 4: Run Composer tests if configured**

```powershell
composer test
```

Expected: either PHPUnit runs cleanly, or report clearly if no app tests are configured. Do not hide failures.

- [ ] **Step 5: Run diff guard against economy files**

```powershell
git diff --name-only origin/source/dev...HEAD | Select-String -Pattern 'includes/economy.php|includes/tick_engine.php|includes/game_time.php|includes/boost_catalog.php|simulation_output|simulation|tools/run-global-economic-reset.php|tools/run-season-reset.php|tools/reinitialize'
```

Expected: no output. If output appears, stop and inspect. Do not continue until unrelated economy/config changes are removed or explicitly approved by the user.

- [ ] **Step 6: Start local PHP server**

```powershell
php -S 127.0.0.1:8080 router.php
```

Expected:

```text
PHP ... Development Server (http://127.0.0.1:8080) started
```

- [ ] **Step 7: Manual browser verification**

Open `http://127.0.0.1:8080` and verify:

- Player can open Account screen.
- Player can save bio/status/visibility.
- Password change rejects wrong current password.
- Password change rejects mismatched new password fields.
- Deletion request shows success and logs email in dev mode.
- Friend request/block UI loads.
- Global chat timestamps are readable and exact time appears on hover.
- Staff/Admin nav appears only for eligible roles.
- Staff user search opens results.
- Staff can remove/mute through endpoints.
- Staff chat tab appears for logged-in users.
- Admin reset forms record requests and do not execute economy logic directly.
- Notification center shows severity/category/action data without breaking existing notifications.

- [ ] **Step 8: Commit documentation and final verification fixes**

```powershell
git add README.md
git commit -m "Document social moderation operations"
```

---

## Implementation Order

1. Schema migration.
2. Permission/audit/mail foundation.
3. Account and password/deletion verification.
4. Social graph API.
5. Staff moderation API.
6. Staff chat and staff notifications.
7. Staff deletion and Admin controls.
8. UI.
9. Docs and full verification.

Each task must be implemented and committed before moving to the next task.
