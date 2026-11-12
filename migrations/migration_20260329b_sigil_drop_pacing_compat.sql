-- Compat replacement for migration_20260329_sigil_drop_pacing_non_batch.sql
-- Adds queued drop counters so earned drops can be delivered gradually.
--
-- Replaces: migration_20260329_sigil_drop_pacing_non_batch.sql
-- Reason:   The original uses ADD COLUMN IF NOT EXISTS which is not supported on
--           all MySQL variants in production. This version uses PREPARE/EXECUTE
--           with INFORMATION_SCHEMA guards and is idempotent on all MySQL 5.7+.
--           It also works with manual application via `mysql < file.sql`.

-- Add pending_rng_sigil_drops column if it does not already exist.
SET @_tmc_col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'season_participation'
    AND COLUMN_NAME = 'pending_rng_sigil_drops');
SET @_tmc_sql = IF(@_tmc_col_exists > 0, 'SELECT 1',
    'ALTER TABLE season_participation ADD COLUMN pending_rng_sigil_drops BIGINT NOT NULL DEFAULT 0');
PREPARE _tmc_stmt FROM @_tmc_sql;
EXECUTE _tmc_stmt;
DEALLOCATE PREPARE _tmc_stmt;

-- Add pending_pity_sigil_drops column if it does not already exist.
SET @_tmc_col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'season_participation'
    AND COLUMN_NAME = 'pending_pity_sigil_drops');
SET @_tmc_sql = IF(@_tmc_col_exists > 0, 'SELECT 1',
    'ALTER TABLE season_participation ADD COLUMN pending_pity_sigil_drops BIGINT NOT NULL DEFAULT 0');
PREPARE _tmc_stmt FROM @_tmc_sql;
EXECUTE _tmc_stmt;
DEALLOCATE PREPARE _tmc_stmt;

-- Add sigil_next_delivery_tick column if it does not already exist.
SET @_tmc_col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'season_participation'
    AND COLUMN_NAME = 'sigil_next_delivery_tick');
SET @_tmc_sql = IF(@_tmc_col_exists > 0, 'SELECT 1',
    'ALTER TABLE season_participation ADD COLUMN sigil_next_delivery_tick BIGINT NOT NULL DEFAULT 0');
PREPARE _tmc_stmt FROM @_tmc_sql;
EXECUTE _tmc_stmt;
DEALLOCATE PREPARE _tmc_stmt;
