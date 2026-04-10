-- Test-only rollback for migration_20260410_sim_tuning_conservative_v2_test_reset.sql
-- Restores pre-tuning season economy values while preserving account/auth data.

UPDATE seasons
SET hoarding_tier2_rate_hourly_fp = 500,
    hoarding_tier3_rate_hourly_fp = 1000,
    hoarding_idle_multiplier_fp = 1250000,
    starprice_reactivation_window_ticks = 75,
    market_affordability_bias_fp = 1000000,
    starprice_max_downstep_fp = 12000;

SELECT 'revert_sim_tuning_conservative_v2_complete' AS status;
