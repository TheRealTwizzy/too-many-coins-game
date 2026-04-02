-- Hard reset seasons/economy while preserving accounts+auth identity.
-- Scope: eject all players from seasons, wipe season economic state/telemetry,
-- zero player currencies (including global stars), and create a fresh active season.

START TRANSACTION;

SET @now_tick := COALESCE((SELECT global_tick_index FROM server_state WHERE id = 1), 0);
SET @template_season_id := (SELECT season_id FROM seasons ORDER BY season_id DESC LIMIT 1);
SET @season_duration := COALESCE((SELECT MAX(end_time - start_time) FROM seasons), 20160);
SET @blackout_duration := COALESCE((SELECT MAX(end_time - blackout_time) FROM seasons), 4320);
SET @season_duration := IF(@season_duration > 0, @season_duration, 20160);
SET @blackout_duration := IF(@blackout_duration > 0 AND @blackout_duration < @season_duration, @blackout_duration, 4320);
SET @new_start_time := @now_tick + 1;
SET @new_end_time := @new_start_time + @season_duration;
SET @new_blackout_time := @new_end_time - @blackout_duration;

-- Force everyone out of current season and clear volatile activity flags.
UPDATE players
SET joined_season_id = NULL,
    participation_enabled = 0,
    idle_modal_active = 0,
    activity_state = 'Active',
    idle_since_tick = NULL,
    last_activity_tick = NULL,
    recent_active_ticks = 0;

-- Remove all player currencies while preserving account/auth identity.
UPDATE players
SET global_stars = 0
WHERE role = 'Player';

-- Remove season-scoped economy and telemetry data.
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
DELETE FROM badges;

-- Retire all existing seasons and reset published aggregate telemetry.
UPDATE seasons
SET status = 'Expired',
    season_expired = 1,
    expiration_finalized = 1,
    current_star_price = 100,
    total_coins_supply = 0,
    total_coins_supply_end_of_tick = 0,
    coins_active_total = 0,
    coins_idle_total = 0,
    effective_price_supply = 0,
    last_processed_tick = @now_tick;

-- Create a brand-new active season using latest season tuning as template.
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
)
SELECT
    @new_start_time,
    @new_end_time,
    @new_blackout_time,
    UNHEX(SHA2(CONCAT(UUID(), RAND(), NOW(6)), 256)),
    'Active',
    0,
    0,
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
    100,
    0,
    0,
    0,
    0,
    0,
    @new_start_time
FROM seasons
WHERE season_id = @template_season_id
LIMIT 1;

SET @new_season_id := LAST_INSERT_ID();

-- Seed the fresh season vault surface.
INSERT INTO season_vault (season_id, tier, initial_supply, remaining_supply, current_cost_stars, last_published_cost_stars)
VALUES
    (@new_season_id, 1, 500, 500, 50, 50),
    (@new_season_id, 2, 250, 250, 250, 250),
    (@new_season_id, 3, 125, 125, 1000, 1000);

-- Ensure server mode is normal after reset.
UPDATE server_state SET server_mode = 'NORMAL' WHERE id = 1;

COMMIT;
