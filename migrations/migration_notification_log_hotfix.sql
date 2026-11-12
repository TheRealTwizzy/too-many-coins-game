CREATE TABLE IF NOT EXISTS player_notifications (
    notification_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    player_id BIGINT UNSIGNED NOT NULL,
    category VARCHAR(40) NOT NULL,
    title VARCHAR(100) NOT NULL,
    body VARCHAR(255) DEFAULT NULL,
    event_key VARCHAR(120) DEFAULT NULL,
    payload_json JSON DEFAULT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME DEFAULT NULL,
    removed_at DATETIME DEFAULT NULL,
    INDEX idx_player_feed (player_id, removed_at, created_at DESC),
    INDEX idx_player_unread (player_id, is_read, removed_at, created_at DESC),
    UNIQUE KEY uk_player_event (player_id, event_key),
    FOREIGN KEY (player_id) REFERENCES players(player_id)
) ENGINE=InnoDB;
