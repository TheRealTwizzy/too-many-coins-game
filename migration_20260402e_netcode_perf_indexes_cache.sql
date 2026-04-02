-- Performance hotfix: reduce tick/leaderboard query pressure and add shared cache table.

START TRANSACTION;

SET @has_idx := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'players'
      AND index_name = 'idx_joined_season_active_player'
);
SET @sql := IF(
    @has_idx = 0,
    'ALTER TABLE players ADD INDEX idx_joined_season_active_player (joined_season_id, participation_enabled, player_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_idx := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'season_participation'
      AND index_name = 'idx_season_player_lookup'
);
SET @sql := IF(
    @has_idx = 0,
    'ALTER TABLE season_participation ADD INDEX idx_season_player_lookup (season_id, player_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_idx := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'active_boosts'
      AND index_name = 'idx_active_boosts_scope_expire_player'
);
SET @sql := IF(
    @has_idx = 0,
    'ALTER TABLE active_boosts ADD INDEX idx_active_boosts_scope_expire_player (season_id, scope, is_active, expires_tick, player_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS leaderboard_cache (
    season_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    generated_tick BIGINT NOT NULL,
    row_count INT NOT NULL DEFAULT 0,
    payload_json LONGTEXT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_generated_tick (generated_tick),
    FOREIGN KEY (season_id) REFERENCES seasons(season_id)
) ENGINE=InnoDB;

COMMIT;
