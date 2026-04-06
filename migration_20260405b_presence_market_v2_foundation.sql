-- Presence + market v2 foundation scaffolding.
--
-- Adds:
--   1. Season-level v2 market config/state fields.
--   2. Offline pricing telemetry.
--   3. Per-player reactivation thaw scaffolding.
--
-- Safe to run multiple times. MySQL 5.7+ compatible.

-- seasons.starprice_model_version ---------------------------------------------

SET @_tmc_col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'seasons'
    AND COLUMN_NAME = 'starprice_model_version');
SET @_tmc_sql = IF(@_tmc_col_exists > 0, 'SELECT 1',
    'ALTER TABLE seasons ADD COLUMN starprice_model_version TINYINT NOT NULL DEFAULT 1');
PREPARE _tmc_stmt FROM @_tmc_sql;
EXECUTE _tmc_stmt;
DEALLOCATE PREPARE _tmc_stmt;

-- seasons.starprice_reactivation_window_ticks --------------------------------

SET @_tmc_col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'seasons'
    AND COLUMN_NAME = 'starprice_reactivation_window_ticks');
SET @_tmc_sql = IF(@_tmc_col_exists > 0, 'SELECT 1',
    'ALTER TABLE seasons ADD COLUMN starprice_reactivation_window_ticks INT NOT NULL DEFAULT 75');
PREPARE _tmc_stmt FROM @_tmc_sql;
EXECUTE _tmc_stmt;
DEALLOCATE PREPARE _tmc_stmt;

-- seasons.starprice_demand_table ----------------------------------------------

SET @_tmc_col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'seasons'
    AND COLUMN_NAME = 'starprice_demand_table');
SET @_tmc_sql = IF(@_tmc_col_exists > 0, 'SELECT 1',
    'ALTER TABLE seasons ADD COLUMN starprice_demand_table JSON DEFAULT NULL');
PREPARE _tmc_stmt FROM @_tmc_sql;
EXECUTE _tmc_stmt;
DEALLOCATE PREPARE _tmc_stmt;

-- seasons.market_affordability_bias_fp ----------------------------------------

SET @_tmc_col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'seasons'
    AND COLUMN_NAME = 'market_affordability_bias_fp');
SET @_tmc_sql = IF(@_tmc_col_exists > 0, 'SELECT 1',
    'ALTER TABLE seasons ADD COLUMN market_affordability_bias_fp INT NOT NULL DEFAULT 1000000');
PREPARE _tmc_stmt FROM @_tmc_sql;
EXECUTE _tmc_stmt;
DEALLOCATE PREPARE _tmc_stmt;

-- seasons.market_anchor_price -------------------------------------------------

SET @_tmc_col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'seasons'
    AND COLUMN_NAME = 'market_anchor_price');
SET @_tmc_sql = IF(@_tmc_col_exists > 0, 'SELECT 1',
    'ALTER TABLE seasons ADD COLUMN market_anchor_price BIGINT NOT NULL DEFAULT 100');
PREPARE _tmc_stmt FROM @_tmc_sql;
EXECUTE _tmc_stmt;
DEALLOCATE PREPARE _tmc_stmt;

-- seasons.blackout_star_price_snapshot ----------------------------------------

SET @_tmc_col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'seasons'
    AND COLUMN_NAME = 'blackout_star_price_snapshot');
SET @_tmc_sql = IF(@_tmc_col_exists > 0, 'SELECT 1',
    'ALTER TABLE seasons ADD COLUMN blackout_star_price_snapshot BIGINT DEFAULT NULL');
PREPARE _tmc_stmt FROM @_tmc_sql;
EXECUTE _tmc_stmt;
DEALLOCATE PREPARE _tmc_stmt;

-- seasons.blackout_started_tick -----------------------------------------------

SET @_tmc_col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'seasons'
    AND COLUMN_NAME = 'blackout_started_tick');
SET @_tmc_sql = IF(@_tmc_col_exists > 0, 'SELECT 1',
    'ALTER TABLE seasons ADD COLUMN blackout_started_tick BIGINT DEFAULT NULL');
PREPARE _tmc_stmt FROM @_tmc_sql;
EXECUTE _tmc_stmt;
DEALLOCATE PREPARE _tmc_stmt;

-- seasons.pending_star_burn_coins ---------------------------------------------

SET @_tmc_col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'seasons'
    AND COLUMN_NAME = 'pending_star_burn_coins');
SET @_tmc_sql = IF(@_tmc_col_exists > 0, 'SELECT 1',
    'ALTER TABLE seasons ADD COLUMN pending_star_burn_coins BIGINT NOT NULL DEFAULT 0');
PREPARE _tmc_stmt FROM @_tmc_sql;
EXECUTE _tmc_stmt;
DEALLOCATE PREPARE _tmc_stmt;

-- seasons.star_burn_ema_fp ----------------------------------------------------

SET @_tmc_col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'seasons'
    AND COLUMN_NAME = 'star_burn_ema_fp');
SET @_tmc_sql = IF(@_tmc_col_exists > 0, 'SELECT 1',
    'ALTER TABLE seasons ADD COLUMN star_burn_ema_fp BIGINT NOT NULL DEFAULT 0');
PREPARE _tmc_stmt FROM @_tmc_sql;
EXECUTE _tmc_stmt;
DEALLOCATE PREPARE _tmc_stmt;

-- seasons.net_mint_ema_fp -----------------------------------------------------

SET @_tmc_col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'seasons'
    AND COLUMN_NAME = 'net_mint_ema_fp');
SET @_tmc_sql = IF(@_tmc_col_exists > 0, 'SELECT 1',
    'ALTER TABLE seasons ADD COLUMN net_mint_ema_fp BIGINT NOT NULL DEFAULT 0');
PREPARE _tmc_stmt FROM @_tmc_sql;
EXECUTE _tmc_stmt;
DEALLOCATE PREPARE _tmc_stmt;

-- seasons.market_pressure_fp --------------------------------------------------

SET @_tmc_col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'seasons'
    AND COLUMN_NAME = 'market_pressure_fp');
SET @_tmc_sql = IF(@_tmc_col_exists > 0, 'SELECT 1',
    'ALTER TABLE seasons ADD COLUMN market_pressure_fp INT NOT NULL DEFAULT 1000000');
PREPARE _tmc_stmt FROM @_tmc_sql;
EXECUTE _tmc_stmt;
DEALLOCATE PREPARE _tmc_stmt;

-- seasons.coins_offline_total -------------------------------------------------

SET @_tmc_col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'seasons'
    AND COLUMN_NAME = 'coins_offline_total');
SET @_tmc_sql = IF(@_tmc_col_exists > 0, 'SELECT 1',
    'ALTER TABLE seasons ADD COLUMN coins_offline_total BIGINT NOT NULL DEFAULT 0');
PREPARE _tmc_stmt FROM @_tmc_sql;
EXECUTE _tmc_stmt;
DEALLOCATE PREPARE _tmc_stmt;

-- season_participation.reactivation_balance_snapshot --------------------------

SET @_tmc_col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'season_participation'
    AND COLUMN_NAME = 'reactivation_balance_snapshot');
SET @_tmc_sql = IF(@_tmc_col_exists > 0, 'SELECT 1',
    'ALTER TABLE season_participation ADD COLUMN reactivation_balance_snapshot BIGINT NOT NULL DEFAULT 0');
PREPARE _tmc_stmt FROM @_tmc_sql;
EXECUTE _tmc_stmt;
DEALLOCATE PREPARE _tmc_stmt;

-- season_participation.reactivation_start_tick --------------------------------

SET @_tmc_col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'season_participation'
    AND COLUMN_NAME = 'reactivation_start_tick');
SET @_tmc_sql = IF(@_tmc_col_exists > 0, 'SELECT 1',
    'ALTER TABLE season_participation ADD COLUMN reactivation_start_tick BIGINT DEFAULT NULL');
PREPARE _tmc_stmt FROM @_tmc_sql;
EXECUTE _tmc_stmt;
DEALLOCATE PREPARE _tmc_stmt;

-- Backfill sensible defaults for live seasons.
UPDATE seasons
SET starprice_model_version = 1,
    starprice_reactivation_window_ticks = 75,
    starprice_demand_table = COALESCE(
        starprice_demand_table,
        '[{"ratio_fp":850000,"multiplier_fp":900000},{"ratio_fp":1000000,"multiplier_fp":1000000},{"ratio_fp":1150000,"multiplier_fp":1080000},{"ratio_fp":1300000,"multiplier_fp":1120000}]'
    ),
    market_affordability_bias_fp = 1000000,
    market_anchor_price = GREATEST(100, LEAST(current_star_price, star_price_cap)),
    pending_star_burn_coins = 0,
    star_burn_ema_fp = 0,
    net_mint_ema_fp = 0,
    market_pressure_fp = 1000000,
    coins_offline_total = 0
WHERE status IN ('Scheduled', 'Active', 'Blackout');