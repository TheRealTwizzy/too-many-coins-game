-- Verify post-reset season timing and playability assumptions.
--
-- Usage (example):
--   mysql -u USER -p DB_NAME < tools/verify-season-timing.sql

-- 1) Basic season timing rows
SELECT
    season_id,
    start_time,
    end_time,
    blackout_time,
    (end_time - start_time) AS duration_ticks,
    (end_time - blackout_time) AS blackout_ticks
FROM seasons
ORDER BY start_time ASC
LIMIT 6;

-- 2) Cadence between adjacent seasons
SELECT
    s1.season_id,
    s1.start_time,
    (
        SELECT MIN(s2.start_time)
        FROM seasons s2
        WHERE s2.start_time > s1.start_time
    ) - s1.start_time AS cadence_ticks
FROM seasons s1
ORDER BY s1.start_time ASC
LIMIT 6;

-- 3) Computed statuses against current server tick
SELECT
    s.season_id,
    s.status AS stored_status,
    CASE
        WHEN ss.global_tick_index < s.start_time THEN 'Scheduled'
        WHEN ss.global_tick_index >= s.end_time THEN 'Expired'
        WHEN ss.global_tick_index >= s.blackout_time THEN 'Blackout'
        ELSE 'Active'
    END AS computed_status,
    ss.global_tick_index AS now_tick
FROM seasons s
JOIN server_state ss ON ss.id = 1
ORDER BY s.start_time ASC
LIMIT 6;

-- 4) Account/auth preservation sanity counts
SELECT
    (SELECT COUNT(*) FROM players) AS players_count,
    (SELECT COUNT(*) FROM handle_registry) AS handle_registry_count,
    (SELECT COUNT(*) FROM handle_history) AS handle_history_count;

-- 5) Active-participant playability snapshot
SELECT
    (SELECT COUNT(*) FROM seasons WHERE status = 'Active') AS active_season_rows,
    (SELECT COUNT(*) FROM players WHERE joined_season_id IS NOT NULL AND participation_enabled = 1) AS active_players,
    (SELECT COUNT(*) FROM season_participation) AS season_participation_rows;
