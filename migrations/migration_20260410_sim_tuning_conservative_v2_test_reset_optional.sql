-- Test-only rebalance promotion: conservative-v2 + fresh gameplay reset preserving account/auth.
-- Scope:
-- - Applies conservative-v2 season economy values.
-- - Resets season/runtime gameplay state for a fresh play-test cycle.
-- - Preserves players identity/auth columns and login/session access.

SET FOREIGN_KEY_CHECKS = 0;

-- 1) Apply conservative-v2 tuning and clear season runtime surfaces.
UPDATE seasons
SET hoarding_tier2_rate_hourly_fp = 535,
    hoarding_tier3_rate_hourly_fp = 1070,
    hoarding_idle_multiplier_fp = 1287500,
    starprice_reactivation_window_ticks = 81,
    market_affordability_bias_fp = 970000,
    starprice_max_downstep_fp = 12960,
    status = 'Scheduled',
    season_expired = 0,
    expiration_finalized = 0,
    current_star_price = LEAST(star_price_cap, GREATEST(100, COALESCE(current_star_price, 100))),
    market_anchor_price = LEAST(star_price_cap, GREATEST(100, COALESCE(market_anchor_price, 100))),
    blackout_star_price_snapshot = NULL,
    blackout_started_tick = NULL,
    pending_star_burn_coins = 0,
    star_burn_ema_fp = 0,
    net_mint_ema_fp = 0,
    market_pressure_fp = 1000000,
    total_coins_supply = 0,
    total_coins_supply_end_of_tick = 0,
    coins_active_total = 0,
    coins_idle_total = 0,
    coins_offline_total = 0,
    effective_price_supply = 0,
    last_processed_tick = start_time;

-- 2) Preserve account/auth while detaching all players from season state.
UPDATE players
SET joined_season_id = NULL,
    participation_enabled = 0,
    idle_modal_active = 0,
    activity_state = 'Active',
    idle_since_tick = NULL,
    last_activity_tick = NULL,
    online_current = 0;

-- 3) Reset server epoch/tick bootstrap so the next request rebuilds season timing from tick 0.
DELETE FROM server_state WHERE id = 1;

-- 4) Reset runtime gameplay tables (preserve players/auth and social graph).
SET @has_yearly_state := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'yearly_state'
);
SET @sql := IF(@has_yearly_state > 0, 'TRUNCATE TABLE `yearly_state`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_active_freezes := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'active_freezes'
);
SET @sql := IF(@has_active_freezes > 0, 'TRUNCATE TABLE `active_freezes`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_active_boosts := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'active_boosts'
);
SET @sql := IF(@has_active_boosts > 0, 'TRUNCATE TABLE `active_boosts`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_sigil_drop_log := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'sigil_drop_log'
);
SET @sql := IF(@has_sigil_drop_log > 0, 'TRUNCATE TABLE `sigil_drop_log`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_sigil_drop_tracking := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'sigil_drop_tracking'
);
SET @sql := IF(@has_sigil_drop_tracking > 0, 'TRUNCATE TABLE `sigil_drop_tracking`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_player_season_vault := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'player_season_vault'
);
SET @sql := IF(@has_player_season_vault > 0, 'TRUNCATE TABLE `player_season_vault`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_season_vault := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'season_vault'
);
SET @sql := IF(@has_season_vault > 0, 'TRUNCATE TABLE `season_vault`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_season_participation := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'season_participation'
);
SET @sql := IF(@has_season_participation > 0, 'TRUNCATE TABLE `season_participation`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_trades := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'trades'
);
SET @sql := IF(@has_trades > 0, 'TRUNCATE TABLE `trades`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_sigil_theft_attempts := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'sigil_theft_attempts'
);
SET @sql := IF(@has_sigil_theft_attempts > 0, 'TRUNCATE TABLE `sigil_theft_attempts`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_economy_ledger := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'economy_ledger'
);
SET @sql := IF(@has_economy_ledger > 0, 'TRUNCATE TABLE `economy_ledger`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_pending_actions := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'pending_actions'
);
SET @sql := IF(@has_pending_actions > 0, 'TRUNCATE TABLE `pending_actions`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_badges := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'badges'
);
SET @sql := IF(@has_badges > 0, 'TRUNCATE TABLE `badges`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_player_notifications := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'player_notifications'
);
SET @sql := IF(@has_player_notifications > 0, 'TRUNCATE TABLE `player_notifications`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET FOREIGN_KEY_CHECKS = 1;

SELECT 'sim_tuning_conservative_v2_test_reset_complete' AS status;
