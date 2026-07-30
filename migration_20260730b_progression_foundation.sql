-- Migration: progression-gating foundation
-- Date: 2026-07-30
--
-- Per-player feature discovery. A row means "this player has discovered this
-- feature"; the client hides undiscovered features entirely. Account-scoped
-- ON PURPOSE - discovery is knowledge, not inventory, so it survives season
-- resets and re-joins. The seasons-preserving reinitialize script therefore
-- does NOT clear this table; only the full global economy reset does.
--
-- Everything is inert unless TMC_PROGRESSION_GATES_ENABLED is on; unlocks are
-- still RECORDED while the flag is off (INSERT IGNORE from the trigger
-- sites), so flipping the flag on later never hides something a player has
-- already seen.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS only, safe to re-run.

CREATE TABLE IF NOT EXISTS player_feature_unlocks (
    player_id BIGINT UNSIGNED NOT NULL,
    feature_key VARCHAR(64) NOT NULL,
    unlocked_at_tick BIGINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (player_id, feature_key),
    CONSTRAINT fk_unlocks_player FOREIGN KEY (player_id) REFERENCES players(player_id)
) ENGINE=InnoDB;
