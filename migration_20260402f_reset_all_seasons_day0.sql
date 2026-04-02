-- World reset: end/remove all current + upcoming seasons and restart timers from day 0.
-- Preserves account/auth identity while resetting all season/economy progression.

START TRANSACTION;

SET @template_season_id := (SELECT season_id FROM seasons ORDER BY season_id DESC LIMIT 1);
SET @season_duration := COALESCE((SELECT end_time - start_time FROM seasons WHERE season_id = @template_season_id), 20160);
SET @blackout_duration := COALESCE((SELECT end_time - blackout_time FROM seasons WHERE season_id = @template_season_id), 4320);
SET @season_duration := IF(@season_duration > 0, @season_duration, 20160);
SET @blackout_duration := IF(@blackout_duration > 0 AND @blackout_duration < @season_duration, @blackout_duration, GREATEST(1, FLOOR(@season_duration / 5)));

SET @base_ubi_active_per_tick := COALESCE((SELECT base_ubi_active_per_tick FROM seasons WHERE season_id = @template_season_id), 30);
SET @base_ubi_idle_factor_fp := COALESCE((SELECT base_ubi_idle_factor_fp FROM seasons WHERE season_id = @template_season_id), 250000);
SET @ubi_min_per_tick := COALESCE((SELECT ubi_min_per_tick FROM seasons WHERE season_id = @template_season_id), 1);
SET @inflation_table := COALESCE((SELECT inflation_table FROM seasons WHERE season_id = @template_season_id), JSON_ARRAY(JSON_OBJECT('x',0,'factor_fp',1000000)));
SET @hoarding_window_ticks := COALESCE((SELECT hoarding_window_ticks FROM seasons WHERE season_id = @template_season_id), 1440);
SET @target_spend_rate_per_tick := COALESCE((SELECT target_spend_rate_per_tick FROM seasons WHERE season_id = @template_season_id), 18);
SET @hoarding_min_factor_fp := COALESCE((SELECT hoarding_min_factor_fp FROM seasons WHERE season_id = @template_season_id), 90000);
SET @hoarding_sink_enabled := COALESCE((SELECT hoarding_sink_enabled FROM seasons WHERE season_id = @template_season_id), 0);
SET @hoarding_safe_hours := COALESCE((SELECT hoarding_safe_hours FROM seasons WHERE season_id = @template_season_id), 12);
SET @hoarding_safe_min_coins := COALESCE((SELECT hoarding_safe_min_coins FROM seasons WHERE season_id = @template_season_id), 20000);
SET @hoarding_tier1_excess_cap := COALESCE((SELECT hoarding_tier1_excess_cap FROM seasons WHERE season_id = @template_season_id), 50000);
SET @hoarding_tier2_excess_cap := COALESCE((SELECT hoarding_tier2_excess_cap FROM seasons WHERE season_id = @template_season_id), 200000);
SET @hoarding_tier1_rate_hourly_fp := COALESCE((SELECT hoarding_tier1_rate_hourly_fp FROM seasons WHERE season_id = @template_season_id), 200);
SET @hoarding_tier2_rate_hourly_fp := COALESCE((SELECT hoarding_tier2_rate_hourly_fp FROM seasons WHERE season_id = @template_season_id), 500);
SET @hoarding_tier3_rate_hourly_fp := COALESCE((SELECT hoarding_tier3_rate_hourly_fp FROM seasons WHERE season_id = @template_season_id), 1000);
SET @hoarding_sink_cap_ratio_fp := COALESCE((SELECT hoarding_sink_cap_ratio_fp FROM seasons WHERE season_id = @template_season_id), 350000);
SET @hoarding_idle_multiplier_fp := COALESCE((SELECT hoarding_idle_multiplier_fp FROM seasons WHERE season_id = @template_season_id), 1250000);
SET @starprice_table := COALESCE((SELECT starprice_table FROM seasons WHERE season_id = @template_season_id), JSON_ARRAY(JSON_OBJECT('m',0,'price',100)));
SET @star_price_cap := COALESCE((SELECT star_price_cap FROM seasons WHERE season_id = @template_season_id), 12000);
SET @starprice_idle_weight_fp := COALESCE((SELECT starprice_idle_weight_fp FROM seasons WHERE season_id = @template_season_id), 250000);
SET @starprice_active_only := COALESCE((SELECT starprice_active_only FROM seasons WHERE season_id = @template_season_id), 0);
SET @starprice_max_upstep_fp := COALESCE((SELECT starprice_max_upstep_fp FROM seasons WHERE season_id = @template_season_id), 2000);
SET @starprice_max_downstep_fp := COALESCE((SELECT starprice_max_downstep_fp FROM seasons WHERE season_id = @template_season_id), 10000);
SET @trade_fee_tiers := COALESCE((SELECT trade_fee_tiers FROM seasons WHERE season_id = @template_season_id), JSON_ARRAY(JSON_OBJECT('threshold',0,'rate_fp',50000)));
SET @trade_min_fee_coins := COALESCE((SELECT trade_min_fee_coins FROM seasons WHERE season_id = @template_season_id), 10);
SET @vault_config := COALESCE((SELECT vault_config FROM seasons WHERE season_id = @template_season_id), JSON_ARRAY(
    JSON_OBJECT('tier',1,'supply',500,'cost_table',JSON_ARRAY(JSON_OBJECT('remaining',1,'cost',50))),
    JSON_OBJECT('tier',2,'supply',250,'cost_table',JSON_ARRAY(JSON_OBJECT('remaining',1,'cost',250))),
    JSON_OBJECT('tier',3,'supply',125,'cost_table',JSON_ARRAY(JSON_OBJECT('remaining',1,'cost',1000)))
));

-- Detach all players and clear volatile activity fields.
UPDATE players
SET joined_season_id = NULL,
    participation_enabled = 0,
    idle_modal_active = 0,
    activity_state = 'Active',
    idle_since_tick = NULL,
    last_activity_tick = NULL,
    recent_active_ticks = 0;

-- Remove all player currencies while preserving auth/account identity.
UPDATE players
SET global_stars = 0
WHERE role = 'Player';

-- Clear all season-scoped data.
SET @has_leaderboard_cache := (
        SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = DATABASE()
            AND table_name = 'leaderboard_cache'
);
SET @sql := IF(@has_leaderboard_cache > 0, 'DELETE FROM leaderboard_cache', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

DELETE FROM active_boosts;
DELETE FROM active_freezes;
DELETE FROM trades;
DELETE FROM season_participation;
DELETE FROM season_vault;
DELETE FROM player_season_vault;
DELETE FROM sigil_drop_log;
DELETE FROM sigil_drop_tracking;
DELETE FROM pending_actions WHERE season_id IS NOT NULL;
DELETE FROM economy_ledger WHERE season_id IS NOT NULL;
DELETE FROM chat_messages WHERE channel_kind = 'SEASON';
DELETE FROM badges WHERE season_id IS NOT NULL;
DELETE FROM seasons;

ALTER TABLE seasons AUTO_INCREMENT = 1;

-- Reset game clock to day 0 and yearly timer origin.
UPDATE server_state
SET server_mode = 'NORMAL',
    current_year_seq = 1,
    global_tick_index = 0,
    last_tick_processed_at = NULL,
    created_at = NOW();

DELETE FROM yearly_state;
INSERT INTO yearly_state (year_seq, year_seed, started_at)
VALUES (1, UNHEX(SHA2(CONCAT(UUID(), RAND(), NOW(6)), 256)), 0);

-- Create fresh Season 1 at day 0 baseline (tick 1 start).
SET @new_start_time := 1;
SET @new_end_time := @new_start_time + @season_duration;
SET @new_blackout_time := @new_end_time - @blackout_duration;

INSERT INTO seasons (
    start_time,
    end_time,
    blackout_time,
    season_seed,
    status,
    season_expired,
    expiration_finalized,
    base_ubi_active_per_tick,
    base_ubi_idle_factor_fp,
    ubi_min_per_tick,
    inflation_table,
    hoarding_window_ticks,
    target_spend_rate_per_tick,
    hoarding_min_factor_fp,
    hoarding_sink_enabled,
    hoarding_safe_hours,
    hoarding_safe_min_coins,
    hoarding_tier1_excess_cap,
    hoarding_tier2_excess_cap,
    hoarding_tier1_rate_hourly_fp,
    hoarding_tier2_rate_hourly_fp,
    hoarding_tier3_rate_hourly_fp,
    hoarding_sink_cap_ratio_fp,
    hoarding_idle_multiplier_fp,
    starprice_table,
    star_price_cap,
    starprice_idle_weight_fp,
    starprice_active_only,
    starprice_max_upstep_fp,
    starprice_max_downstep_fp,
    trade_fee_tiers,
    trade_min_fee_coins,
    vault_config,
    current_star_price,
    total_coins_supply,
    total_coins_supply_end_of_tick,
    coins_active_total,
    coins_idle_total,
    effective_price_supply,
    last_processed_tick
) VALUES (
    @new_start_time,
    @new_end_time,
    @new_blackout_time,
    UNHEX(SHA2(CONCAT(UUID(), RAND(), NOW(6)), 256)),
    'Scheduled',
    0,
    0,
    @base_ubi_active_per_tick,
    @base_ubi_idle_factor_fp,
    @ubi_min_per_tick,
    @inflation_table,
    @hoarding_window_ticks,
    @target_spend_rate_per_tick,
    @hoarding_min_factor_fp,
    @hoarding_sink_enabled,
    @hoarding_safe_hours,
    @hoarding_safe_min_coins,
    @hoarding_tier1_excess_cap,
    @hoarding_tier2_excess_cap,
    @hoarding_tier1_rate_hourly_fp,
    @hoarding_tier2_rate_hourly_fp,
    @hoarding_tier3_rate_hourly_fp,
    @hoarding_sink_cap_ratio_fp,
    @hoarding_idle_multiplier_fp,
    @starprice_table,
    @star_price_cap,
    @starprice_idle_weight_fp,
    @starprice_active_only,
    @starprice_max_upstep_fp,
    @starprice_max_downstep_fp,
    @trade_fee_tiers,
    @trade_min_fee_coins,
    @vault_config,
    100,
    0,
    0,
    0,
    0,
    0,
    @new_start_time
);

SET @new_season_id := LAST_INSERT_ID();

INSERT INTO season_vault (season_id, tier, initial_supply, remaining_supply, current_cost_stars, last_published_cost_stars)
VALUES
    (@new_season_id, 1, 500, 500, 50, 50),
    (@new_season_id, 2, 250, 250, 250, 250),
    (@new_season_id, 3, 125, 125, 1000, 1000);

COMMIT;
