-- Rank the global leaderboard on LIFETIME EARNED Global Stars, not the
-- spendable balance.
--
-- The leaderboard ordered by players.global_stars DESC, and purchaseCosmetic
-- decrements that same column - and cosmetics are the only Global Star sink.
-- So buying anything permanently lowered your rank, one-for-one. The shop the
-- README calls "pure prestige" cost the actual prestige metric, which made the
-- entire 24-item catalog (~17,775 stars to complete) something no competitive
-- player should ever touch, and guaranteed the top of the leaderboard was
-- populated by players who owned no cosmetics.
--
-- Splitting the two roles the column was playing:
--   global_stars           -> spendable wallet (unchanged)
--   global_stars_lifetime  -> monotonic earned total, used for ranking
--
-- Backfill uses SUM(season_participation.global_stars_earned), which has been
-- written per season all along. GREATEST(..., global_stars) guarantees nobody
-- ranks below what their current balance already implies, covering any stars
-- granted through a path that did not write global_stars_earned.

SET @has_col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'players'
      AND COLUMN_NAME = 'global_stars_lifetime'
);
SET @sql := IF(@has_col = 0,
    'ALTER TABLE players ADD COLUMN global_stars_lifetime BIGINT NOT NULL DEFAULT 0 AFTER global_stars',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_idx := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'players'
      AND INDEX_NAME = 'idx_global_stars_lifetime'
);
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE players ADD INDEX idx_global_stars_lifetime (global_stars_lifetime DESC)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill from the per-season record.
UPDATE players p
LEFT JOIN (
    SELECT player_id, COALESCE(SUM(global_stars_earned), 0) AS earned
    FROM season_participation
    GROUP BY player_id
) sp ON sp.player_id = p.player_id
SET p.global_stars_lifetime = GREATEST(COALESCE(sp.earned, 0), p.global_stars);

SELECT 'global_stars_lifetime_ranking_complete' AS status;
