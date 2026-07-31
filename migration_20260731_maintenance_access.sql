-- Per-account bypass for the maintenance lockdown gate.
--
-- The gate lets staff through and nobody else, which makes the game
-- untestable while it is up: staff cannot join a season (seasonJoin refuses
-- any role other than Player, so competitive standings never contain a
-- moderator), and every ordinary account is blocked. Nobody could play-test
-- a gated build.
--
-- maintenance_access marks an account as allowed through the gate without
-- granting it any staff power. That keeps the two questions separate - "may
-- you play while the game is closed" and "may you act on other players" -
-- rather than forcing an operator to hand out Moderator to run a test, and it
-- is the same mechanism a closed beta needs later.
--
-- MySQL 5.7+ compatible: INFORMATION_SCHEMA guard rather than
-- ADD COLUMN IF NOT EXISTS, idempotent, works under `mysql < file.sql`.

SET @_tmc_col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'players'
    AND COLUMN_NAME = 'maintenance_access');
SET @_tmc_sql = IF(@_tmc_col_exists > 0, 'SELECT 1',
    'ALTER TABLE players ADD COLUMN maintenance_access TINYINT(1) NOT NULL DEFAULT 0');
PREPARE _tmc_stmt FROM @_tmc_sql;
EXECUTE _tmc_stmt;
DEALLOCATE PREPARE _tmc_stmt;
