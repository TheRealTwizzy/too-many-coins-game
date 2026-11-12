-- Improve active_boosts hot lookup in tick/runtime paths:
-- WHERE player_id=? AND season_id=? AND is_active=1 AND expires_tick>=?

SET @idx_exists := (
    SELECT COUNT(1)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'active_boosts'
      AND index_name = 'idx_player_season_active_expiry'
);

SET @ddl := IF(
    @idx_exists = 0,
    'CREATE INDEX idx_player_season_active_expiry ON active_boosts (player_id, season_id, is_active, expires_tick)',
    'SELECT 1'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
