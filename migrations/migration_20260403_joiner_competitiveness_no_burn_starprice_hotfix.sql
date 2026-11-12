-- Joiner competitiveness hotfix (no-burn policy)
-- Date: 2026-04-03
--
-- Goals:
-- 1) Reduce inflation pressure from idle hoards by using active-only price supply.
-- 2) Flatten star-price curve so late joiners can still buy stars competitively.
-- 3) Keep explicit hoarding burn disabled.
-- 4) Apply immediately to live seasons that are still in play.
--
-- Safe to re-run.

UPDATE seasons
SET
  hoarding_sink_enabled = 0,
  starprice_active_only = 1,
  starprice_idle_weight_fp = 0,
  starprice_table = '[{"m":0,"price":100},{"m":25000,"price":220},{"m":100000,"price":520},{"m":500000,"price":1600},{"m":2000000,"price":4200}]',
  star_price_cap = 6000,
  starprice_max_upstep_fp = 1000,
  starprice_max_downstep_fp = 12000,
  current_star_price = LEAST(6000, GREATEST(current_star_price, 100))
WHERE status IN ('Scheduled', 'Active', 'Blackout');

-- Rollback guidance (manual):
-- Restore previous seasonal values from a snapshot, or re-run an update with prior
-- starprice_* and cap values for the affected season_id rows.
