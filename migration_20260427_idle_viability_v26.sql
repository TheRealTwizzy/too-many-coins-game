-- Idle viability v2.6 play-test tuning.
-- Scope: raise idle UBI share from 25% to 30% for current play-test seasons.
-- Safe to re-run. Does not reset players or mutable gameplay state.

UPDATE seasons
SET base_ubi_idle_factor_fp = 300000
WHERE status IN ('Scheduled', 'Active', 'Blackout')
  AND base_ubi_active_per_tick = 30
  AND base_ubi_idle_factor_fp = 250000;

SELECT 'idle_viability_v26_complete' AS status;
