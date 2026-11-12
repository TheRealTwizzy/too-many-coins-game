-- Migration: Legion critical mass - season-wide modifier events
-- Date: 2026-07-30
--
-- Consuming a critical mass of Legion (wildcard) sigils of one tier triggers
-- a random season-wide modifier event (swarm / frenzy / foresight). The event
-- itself is announced loudly to every participant via the season ticker and a
-- season-wide notification; the TRIGGER is deliberately undocumented player
-- side - an easter egg discovered by experimenting with wildcards.
--
-- One event may be active per season at a time; enforcement is a conditional
-- INSERT ... WHERE NOT EXISTS at the action layer, inside the same
-- transaction as the sigil spend.
--
-- started_tick/ends_tick are game ticks. Events are only ever inserted at the
-- current tick, so catch-up tick replays are deterministic: a tick processed
-- later sees exactly the events that were live when that tick occurred.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS only, safe to re-run.

CREATE TABLE IF NOT EXISTS season_modifier_events (
    event_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    season_id BIGINT UNSIGNED NOT NULL,
    source_player_id BIGINT UNSIGNED DEFAULT NULL,
    event_kind VARCHAR(32) NOT NULL,
    source_tier TINYINT UNSIGNED NOT NULL,
    started_tick BIGINT NOT NULL,
    ends_tick BIGINT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_modevents_season_active (season_id, ends_tick),
    CONSTRAINT fk_modevents_season FOREIGN KEY (season_id) REFERENCES seasons(season_id),
    CONSTRAINT fk_modevents_player FOREIGN KEY (source_player_id) REFERENCES players(player_id)
) ENGINE=InnoDB;
