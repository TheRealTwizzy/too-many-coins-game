-- Activity decay memory field for deterministic inactivity decay windows.
ALTER TABLE players
    ADD COLUMN recent_active_ticks BIGINT NOT NULL DEFAULT 0 AFTER last_activity_tick;
