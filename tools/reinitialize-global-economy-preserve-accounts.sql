-- Reinitialize season/tick economy state and permanent player economy state
-- while preserving account/auth identity data.
--
-- Preserved identity/auth tables (not touched):
--   players.email, players.password_hash, players.session_token,
--   handle_registry, handle_history
--
-- Additional global economy reset scope beyond season reset:
--   players.global_stars, player_cosmetics
--
-- Usage (example):
--   mysql -u USER -p DB_NAME < tools/reinitialize-global-economy-preserve-accounts.sql

SET FOREIGN_KEY_CHECKS = 0;

-- Detach all players from any active season while preserving account/auth columns.
-- Also wipe permanent player economy state.
UPDATE players
SET global_stars = 0,
    joined_season_id = NULL,
    participation_enabled = 0,
    idle_modal_active = 0,
    activity_state = 'Active',
    idle_since_tick = NULL,
    last_activity_tick = NULL;

SET @has_player_cosmetics := (
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'player_cosmetics'
);
SET @sql := IF(@has_player_cosmetics > 0, 'TRUNCATE TABLE `player_cosmetics`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Reset server bootstrap state so a fresh request/tick recreates canonical timing rows.
SET @has_server_state := (
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'server_state'
);
SET @sql := IF(@has_server_state > 0, 'DELETE FROM `server_state` WHERE id = 1', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_yearly_state := (
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'yearly_state'
);
SET @sql := IF(@has_yearly_state > 0, 'TRUNCATE TABLE `yearly_state`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_active_freezes := (
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'active_freezes'
);
SET @sql := IF(@has_active_freezes > 0, 'TRUNCATE TABLE `active_freezes`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_active_boosts := (
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'active_boosts'
);
SET @sql := IF(@has_active_boosts > 0, 'TRUNCATE TABLE `active_boosts`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_sigil_drop_log := (
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'sigil_drop_log'
);
SET @sql := IF(@has_sigil_drop_log > 0, 'TRUNCATE TABLE `sigil_drop_log`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_sigil_drop_tracking := (
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'sigil_drop_tracking'
);
SET @sql := IF(@has_sigil_drop_tracking > 0, 'TRUNCATE TABLE `sigil_drop_tracking`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_player_season_vault := (
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'player_season_vault'
);
SET @sql := IF(@has_player_season_vault > 0, 'TRUNCATE TABLE `player_season_vault`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_season_vault := (
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'season_vault'
);
SET @sql := IF(@has_season_vault > 0, 'TRUNCATE TABLE `season_vault`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_season_participation := (
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'season_participation'
);
SET @sql := IF(@has_season_participation > 0, 'TRUNCATE TABLE `season_participation`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_trades := (
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'trades'
);
SET @sql := IF(@has_trades > 0, 'TRUNCATE TABLE `trades`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_sigil_theft_attempts := (
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'sigil_theft_attempts'
);
SET @sql := IF(@has_sigil_theft_attempts > 0, 'TRUNCATE TABLE `sigil_theft_attempts`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_economy_ledger := (
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'economy_ledger'
);
SET @sql := IF(@has_economy_ledger > 0, 'TRUNCATE TABLE `economy_ledger`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_pending_actions := (
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'pending_actions'
);
SET @sql := IF(@has_pending_actions > 0, 'TRUNCATE TABLE `pending_actions`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_seasons := (
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'seasons'
);
SET @sql := IF(@has_seasons > 0, 'TRUNCATE TABLE `seasons`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET FOREIGN_KEY_CHECKS = 1;

SELECT 'global_economy_reinit_complete' AS status;