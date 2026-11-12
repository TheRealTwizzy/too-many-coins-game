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
        'ALTER TABLE player_notifications ADD COLUMN severity ENUM(''info'',''success'',''warning'',''danger'') NOT NULL DEFAULT ''info'' AFTER category',
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
