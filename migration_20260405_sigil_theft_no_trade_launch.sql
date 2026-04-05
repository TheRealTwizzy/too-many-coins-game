-- Remove trade runtime structures and add sigil theft attempt persistence.

SET @_tmc_sql = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'seasons'
              AND COLUMN_NAME = 'trade_fee_tiers'
        ),
        'ALTER TABLE seasons DROP COLUMN trade_fee_tiers',
        'SELECT 1'
    )
);
PREPARE _tmc_stmt FROM @_tmc_sql;
EXECUTE _tmc_stmt;
DEALLOCATE PREPARE _tmc_stmt;

SET @_tmc_sql = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'seasons'
              AND COLUMN_NAME = 'trade_min_fee_coins'
        ),
        'ALTER TABLE seasons DROP COLUMN trade_min_fee_coins',
        'SELECT 1'
    )
);
PREPARE _tmc_stmt FROM @_tmc_sql;
EXECUTE _tmc_stmt;
DEALLOCATE PREPARE _tmc_stmt;

DROP TABLE IF EXISTS trades;

CREATE TABLE IF NOT EXISTS sigil_theft_attempts (
    theft_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    season_id BIGINT UNSIGNED NOT NULL,
    attacker_player_id BIGINT UNSIGNED NOT NULL,
    target_player_id BIGINT UNSIGNED NOT NULL,
    spent_sigils JSON NOT NULL,
    requested_sigils JSON NOT NULL,
    transferred_sigils JSON NOT NULL,
    spend_value BIGINT NOT NULL DEFAULT 0,
    requested_value BIGINT NOT NULL DEFAULT 0,
    success_chance_fp INT NOT NULL DEFAULT 0,
    rng_roll_fp INT NOT NULL DEFAULT 0,
    result ENUM('SUCCESS', 'FAILED') NOT NULL,
    cooldown_expires_tick BIGINT NOT NULL,
    protection_expires_tick BIGINT NOT NULL,
    created_tick BIGINT NOT NULL,
    resolved_tick BIGINT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_theft_attacker (attacker_player_id, season_id, created_tick),
    INDEX idx_theft_target (target_player_id, season_id, created_tick),
    INDEX idx_theft_cooldown (season_id, attacker_player_id, cooldown_expires_tick),
    INDEX idx_theft_protection (season_id, target_player_id, protection_expires_tick),
    FOREIGN KEY (season_id) REFERENCES seasons(season_id),
    FOREIGN KEY (attacker_player_id) REFERENCES players(player_id),
    FOREIGN KEY (target_player_id) REFERENCES players(player_id)
) ENGINE=InnoDB;