-- Enable and rebalance the hoarding sink.
--
-- WHY IT WAS DEAD
-- hoarding_sink_enabled was 0 in every path that sets it: the new-season
-- INSERT, rebalanceExistingSeasons, and three prior migrations. Nothing ever
-- set it to 1, so calculateHoardingSinkCoinsPerTick returned on its first line
-- and ~11 season columns plus the EARLY/MID phase gate were inert.
--
-- Flipping the flag alone would still have done nothing, for two reasons in
-- the code (both fixed alongside this migration):
--   1. The sink was computed in WHOLE COINS PER TICK. At the deployed 5s
--      cadence a 30 coins/min player grosses 2.5 coins/tick, so the smallest
--      expressible sink was 1 coin/tick = 40% of gross. Off or brutal, nothing
--      between.
--   2. The cap itself floored to zero: it divided by FP_SCALE twice, giving
--      intdiv(2_500_000 * 350_000, 1e12) = 0, clamping any sink to nothing.
-- The sink is now fixed point, so it can express fractions of a coin per tick.
--
-- THE RATES
-- The old rates were calibrated for whole-coin output and are ~50x too small
-- for fixed point: tier1 at 200fp is 0.02%/hour, which is invisible. Retuned
-- so the drain is legible without being punitive.
--
--   tier 1 (first 50k over buffer)   1%/hour
--   tier 2 (next 200k)               3%/hour
--   tier 3 (beyond)                  6%/hour
--   ceiling                          30% of gross rate
--
-- Resulting curve for a 30 coins/min player (12h buffer = 21,600 coins):
--
--   held      excess    sink/hr   % of gross
--   15,000         0          0     0%     <- under buffer, untouched
--   25,000     3,400         33     2%
--   40,000    18,400        183    10%
--   60,000    38,400        383    21%
--   80,000+   58,400+       540    30%     <- ceiling binds
--
-- DESIGN NOTES
-- - The sink reduces INCOME, it does not debit the balance. A hoarder's stack
--   never shrinks, it just stops growing as fast. Losing banked coins reads as
--   punishment; losing rate reads as pressure.
-- - The 30% ceiling means the sink can never reverse progress. There is no
--   state where playing normally makes you poorer.
-- - hoarding_idle_multiplier_fp drops from 1287500 to 1000000. Idle players
--   already earn 30% of active UBI; taxing them a further 29% harder punished
--   stepping away twice over. The sink is now presence-neutral, and Offline is
--   exempt entirely (economy.php returns 0), so sleeping is never penalised.
-- - Still gated to EARLY and MID phases only. LATE_ACTIVE is when players are
--   weighing lock-in and draining them there removes the decision; BLACKOUT is
--   settlement.
--
-- Applies to existing seasons as well as new ones. That is deliberate: unlike
-- the star price change, the sink cannot devalue anything a player already
-- holds - it only slows accrual above a 12-hour buffer, and any player under
-- that buffer sees no change at all.

UPDATE seasons
SET hoarding_sink_enabled         = 1,
    hoarding_safe_hours           = 12,
    hoarding_safe_min_coins       = 20000,
    hoarding_tier1_excess_cap     = 50000,
    hoarding_tier2_excess_cap     = 200000,
    hoarding_tier1_rate_hourly_fp = 10000,
    hoarding_tier2_rate_hourly_fp = 30000,
    hoarding_tier3_rate_hourly_fp = 60000,
    hoarding_sink_cap_ratio_fp    = 300000,
    hoarding_idle_multiplier_fp   = 1000000
WHERE season_expired = 0;

SELECT COUNT(*) AS seasons_updated, 'hoarding_sink_enabled' AS status
FROM seasons WHERE hoarding_sink_enabled = 1;
