-- Active return gameplay flow config surface and participation pacing state.
-- Idempotent on MySQL variants that do not support ADD COLUMN IF NOT EXISTS.

SET @_tmc_col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'seasons' AND COLUMN_NAME = 'return_pulse_min_gap_ticks');
SET @_tmc_sql = IF(@_tmc_col_exists = 0,
    'ALTER TABLE seasons ADD COLUMN return_pulse_min_gap_ticks BIGINT NOT NULL DEFAULT 5 AFTER market_affordability_bias_fp',
    'SELECT 1');
PREPARE _tmc_stmt FROM @_tmc_sql;
EXECUTE _tmc_stmt;
DEALLOCATE PREPARE _tmc_stmt;

SET @_tmc_col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'seasons' AND COLUMN_NAME = 'return_pulse_cooldown_ticks');
SET @_tmc_sql = IF(@_tmc_col_exists = 0,
    'ALTER TABLE seasons ADD COLUMN return_pulse_cooldown_ticks BIGINT NOT NULL DEFAULT 30 AFTER return_pulse_min_gap_ticks',
    'SELECT 1');
PREPARE _tmc_stmt FROM @_tmc_sql;
EXECUTE _tmc_stmt;
DEALLOCATE PREPARE _tmc_stmt;

SET @_tmc_col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'seasons' AND COLUMN_NAME = 'active_dry_spell_ticks');
SET @_tmc_sql = IF(@_tmc_col_exists = 0,
    'ALTER TABLE seasons ADD COLUMN active_dry_spell_ticks BIGINT NOT NULL DEFAULT 5 AFTER return_pulse_cooldown_ticks',
    'SELECT 1');
PREPARE _tmc_stmt FROM @_tmc_sql;
EXECUTE _tmc_stmt;
DEALLOCATE PREPARE _tmc_stmt;

SET @_tmc_col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'seasons' AND COLUMN_NAME = 'participation_pulse_reward_tier');
SET @_tmc_sql = IF(@_tmc_col_exists = 0,
    'ALTER TABLE seasons ADD COLUMN participation_pulse_reward_tier TINYINT NOT NULL DEFAULT 1 AFTER active_dry_spell_ticks',
    'SELECT 1');
PREPARE _tmc_stmt FROM @_tmc_sql;
EXECUTE _tmc_stmt;
DEALLOCATE PREPARE _tmc_stmt;

SET @_tmc_col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'season_participation' AND COLUMN_NAME = 'last_meaningful_economy_tick');
SET @_tmc_sql = IF(@_tmc_col_exists = 0,
    'ALTER TABLE season_participation ADD COLUMN last_meaningful_economy_tick BIGINT NOT NULL DEFAULT 0 AFTER reactivation_start_tick',
    'SELECT 1');
PREPARE _tmc_stmt FROM @_tmc_sql;
EXECUTE _tmc_stmt;
DEALLOCATE PREPARE _tmc_stmt;

SET @_tmc_col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'season_participation' AND COLUMN_NAME = 'last_return_pulse_tick');
SET @_tmc_sql = IF(@_tmc_col_exists = 0,
    'ALTER TABLE season_participation ADD COLUMN last_return_pulse_tick BIGINT NOT NULL DEFAULT 0 AFTER last_meaningful_economy_tick',
    'SELECT 1');
PREPARE _tmc_stmt FROM @_tmc_sql;
EXECUTE _tmc_stmt;
DEALLOCATE PREPARE _tmc_stmt;

SET @_tmc_col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'season_participation' AND COLUMN_NAME = 'last_active_pulse_tick');
SET @_tmc_sql = IF(@_tmc_col_exists = 0,
    'ALTER TABLE season_participation ADD COLUMN last_active_pulse_tick BIGINT NOT NULL DEFAULT 0 AFTER last_return_pulse_tick',
    'SELECT 1');
PREPARE _tmc_stmt FROM @_tmc_sql;
EXECUTE _tmc_stmt;
DEALLOCATE PREPARE _tmc_stmt;

SET @_tmc_col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'season_participation' AND COLUMN_NAME = 'return_pulses_total');
SET @_tmc_sql = IF(@_tmc_col_exists = 0,
    'ALTER TABLE season_participation ADD COLUMN return_pulses_total INT NOT NULL DEFAULT 0 AFTER last_active_pulse_tick',
    'SELECT 1');
PREPARE _tmc_stmt FROM @_tmc_sql;
EXECUTE _tmc_stmt;
DEALLOCATE PREPARE _tmc_stmt;

SET @_tmc_col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'season_participation' AND COLUMN_NAME = 'active_pulses_total');
SET @_tmc_sql = IF(@_tmc_col_exists = 0,
    'ALTER TABLE season_participation ADD COLUMN active_pulses_total INT NOT NULL DEFAULT 0 AFTER return_pulses_total',
    'SELECT 1');
PREPARE _tmc_stmt FROM @_tmc_sql;
EXECUTE _tmc_stmt;
DEALLOCATE PREPARE _tmc_stmt;

UPDATE seasons
SET return_pulse_min_gap_ticks = GREATEST(1, CEIL(300 * GREATEST(1, end_time - start_time) / 1209600)),
    return_pulse_cooldown_ticks = GREATEST(1, CEIL(1800 * GREATEST(1, end_time - start_time) / 1209600)),
    active_dry_spell_ticks = GREATEST(1, CEIL(300 * GREATEST(1, end_time - start_time) / 1209600)),
    participation_pulse_reward_tier = 1
WHERE status IN ('Active', 'Scheduled', 'Blackout');

UPDATE season_participation sp
JOIN seasons s ON s.season_id = sp.season_id
SET sp.last_meaningful_economy_tick = COALESCE(NULLIF(sp.first_joined_at, 0), s.start_time)
WHERE sp.last_meaningful_economy_tick = 0;

SELECT 'active_return_gameplay_flow_complete' AS status;
