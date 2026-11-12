-- Compat replacement for migration_20260329_hoarding_sink_active_seasons_hotfix.sql
-- Adds explicit hoarding-sink tuning fields and seeds active seasons safely.
-- This migration is designed for live rollout: active seasons receive conservative
-- tuning defaults immediately, but sink remains disabled until explicitly enabled.
--
-- Replaces: migration_20260329_hoarding_sink_active_seasons_hotfix.sql
-- Reason:   The original uses ADD COLUMN IF NOT EXISTS which is not supported on
--           all MySQL variants in production. This version uses PREPARE/EXECUTE
--           with INFORMATION_SCHEMA guards and is idempotent on all MySQL 5.7+.
--           It also works with manual application via `mysql < file.sql`.

-- seasons table columns -------------------------------------------------------

SET @_tmc_col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'seasons'
    AND COLUMN_NAME = 'hoarding_sink_enabled');
SET @_tmc_sql = IF(@_tmc_col_exists > 0, 'SELECT 1',
    'ALTER TABLE seasons ADD COLUMN hoarding_sink_enabled TINYINT(1) NOT NULL DEFAULT 0');
PREPARE _tmc_stmt FROM @_tmc_sql;
EXECUTE _tmc_stmt;
DEALLOCATE PREPARE _tmc_stmt;

SET @_tmc_col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'seasons'
    AND COLUMN_NAME = 'hoarding_safe_hours');
SET @_tmc_sql = IF(@_tmc_col_exists > 0, 'SELECT 1',
    'ALTER TABLE seasons ADD COLUMN hoarding_safe_hours INT NOT NULL DEFAULT 12');
PREPARE _tmc_stmt FROM @_tmc_sql;
EXECUTE _tmc_stmt;
DEALLOCATE PREPARE _tmc_stmt;

SET @_tmc_col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'seasons'
    AND COLUMN_NAME = 'hoarding_safe_min_coins');
SET @_tmc_sql = IF(@_tmc_col_exists > 0, 'SELECT 1',
    'ALTER TABLE seasons ADD COLUMN hoarding_safe_min_coins BIGINT NOT NULL DEFAULT 20000');
PREPARE _tmc_stmt FROM @_tmc_sql;
EXECUTE _tmc_stmt;
DEALLOCATE PREPARE _tmc_stmt;

SET @_tmc_col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'seasons'
    AND COLUMN_NAME = 'hoarding_tier1_excess_cap');
SET @_tmc_sql = IF(@_tmc_col_exists > 0, 'SELECT 1',
    'ALTER TABLE seasons ADD COLUMN hoarding_tier1_excess_cap BIGINT NOT NULL DEFAULT 50000');
PREPARE _tmc_stmt FROM @_tmc_sql;
EXECUTE _tmc_stmt;
DEALLOCATE PREPARE _tmc_stmt;

SET @_tmc_col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'seasons'
    AND COLUMN_NAME = 'hoarding_tier2_excess_cap');
SET @_tmc_sql = IF(@_tmc_col_exists > 0, 'SELECT 1',
    'ALTER TABLE seasons ADD COLUMN hoarding_tier2_excess_cap BIGINT NOT NULL DEFAULT 200000');
PREPARE _tmc_stmt FROM @_tmc_sql;
EXECUTE _tmc_stmt;
DEALLOCATE PREPARE _tmc_stmt;

SET @_tmc_col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'seasons'
    AND COLUMN_NAME = 'hoarding_tier1_rate_hourly_fp');
SET @_tmc_sql = IF(@_tmc_col_exists > 0, 'SELECT 1',
    'ALTER TABLE seasons ADD COLUMN hoarding_tier1_rate_hourly_fp INT NOT NULL DEFAULT 200');
PREPARE _tmc_stmt FROM @_tmc_sql;
EXECUTE _tmc_stmt;
DEALLOCATE PREPARE _tmc_stmt;

SET @_tmc_col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'seasons'
    AND COLUMN_NAME = 'hoarding_tier2_rate_hourly_fp');
SET @_tmc_sql = IF(@_tmc_col_exists > 0, 'SELECT 1',
    'ALTER TABLE seasons ADD COLUMN hoarding_tier2_rate_hourly_fp INT NOT NULL DEFAULT 500');
PREPARE _tmc_stmt FROM @_tmc_sql;
EXECUTE _tmc_stmt;
DEALLOCATE PREPARE _tmc_stmt;

SET @_tmc_col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'seasons'
    AND COLUMN_NAME = 'hoarding_tier3_rate_hourly_fp');
SET @_tmc_sql = IF(@_tmc_col_exists > 0, 'SELECT 1',
    'ALTER TABLE seasons ADD COLUMN hoarding_tier3_rate_hourly_fp INT NOT NULL DEFAULT 1000');
PREPARE _tmc_stmt FROM @_tmc_sql;
EXECUTE _tmc_stmt;
DEALLOCATE PREPARE _tmc_stmt;

SET @_tmc_col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'seasons'
    AND COLUMN_NAME = 'hoarding_sink_cap_ratio_fp');
SET @_tmc_sql = IF(@_tmc_col_exists > 0, 'SELECT 1',
    'ALTER TABLE seasons ADD COLUMN hoarding_sink_cap_ratio_fp INT NOT NULL DEFAULT 350000');
PREPARE _tmc_stmt FROM @_tmc_sql;
EXECUTE _tmc_stmt;
DEALLOCATE PREPARE _tmc_stmt;

SET @_tmc_col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'seasons'
    AND COLUMN_NAME = 'hoarding_idle_multiplier_fp');
SET @_tmc_sql = IF(@_tmc_col_exists > 0, 'SELECT 1',
    'ALTER TABLE seasons ADD COLUMN hoarding_idle_multiplier_fp INT NOT NULL DEFAULT 1250000');
PREPARE _tmc_stmt FROM @_tmc_sql;
EXECUTE _tmc_stmt;
DEALLOCATE PREPARE _tmc_stmt;

-- season_participation table columns ------------------------------------------

SET @_tmc_col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'season_participation'
    AND COLUMN_NAME = 'hoarding_sink_total');
SET @_tmc_sql = IF(@_tmc_col_exists > 0, 'SELECT 1',
    'ALTER TABLE season_participation ADD COLUMN hoarding_sink_total BIGINT NOT NULL DEFAULT 0');
PREPARE _tmc_stmt FROM @_tmc_sql;
EXECUTE _tmc_stmt;
DEALLOCATE PREPARE _tmc_stmt;

-- Backfill currently live seasons with conservative starter tuning.
-- 'Active' = season is open for participation; 'Blackout' = season is in its
-- end-of-season cooldown period but still live in the DB.
-- Both are considered live/in-progress seasons and should receive tuning defaults.
-- Keep sink disabled (hoarding_sink_enabled = 0) for controlled cohort enablement.
UPDATE seasons
SET hoarding_sink_enabled        = 0,
    hoarding_safe_hours           = 12,
    hoarding_safe_min_coins       = 20000,
    hoarding_tier1_excess_cap     = 50000,
    hoarding_tier2_excess_cap     = 200000,
    hoarding_tier1_rate_hourly_fp = 200,
    hoarding_tier2_rate_hourly_fp = 500,
    hoarding_tier3_rate_hourly_fp = 1000,
    hoarding_sink_cap_ratio_fp    = 350000,
    hoarding_idle_multiplier_fp   = 1250000
WHERE status IN ('Active', 'Blackout');
