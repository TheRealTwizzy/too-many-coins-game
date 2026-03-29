-- Migration: non-batch sigil drop pacing state
-- Adds queued drop counters so earned drops can be delivered gradually.

ALTER TABLE season_participation
    ADD COLUMN IF NOT EXISTS pending_rng_sigil_drops BIGINT NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS pending_pity_sigil_drops BIGINT NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS sigil_next_delivery_tick BIGINT NOT NULL DEFAULT 0;
