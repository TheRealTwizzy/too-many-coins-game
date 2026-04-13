-- Migration: Add total_season_participation_ticks to season_participation
-- Purpose: Tracks cumulative ticks across all runs in a season (survives rejoin reset).
-- Used by: Actions::lockIn() to enforce MIN_SEASONAL_LOCK_IN_TICKS threshold.
-- Related blockers: B8 (Hardcore path), B9 (Boost-Focused path) — skip-rejoin exploit.
--
-- Design: On rejoin, resetSeasonParticipationForFreshStart accumulates
-- participation_ticks_since_join into this column before resetting it to 0.
-- tick_engine increments this column alongside participation_ticks_since_join.
-- lockIn() checks: (total_season_participation_ticks + participation_ticks_since_join)
-- >= MIN_SEASONAL_LOCK_IN_TICKS before allowing lock-in.

ALTER TABLE season_participation
  ADD COLUMN IF NOT EXISTS total_season_participation_ticks BIGINT NOT NULL DEFAULT 0;
