-- Enforce single player-bound, season-bound boost behavior.
-- 1) Disable any legacy GLOBAL boosts.
-- 2) Keep only one active SELF boost row per (player_id, season_id),
--    preferring the row with the latest expires_tick and then lowest id.

UPDATE active_boosts
SET is_active = 0
WHERE is_active = 1
  AND scope = 'GLOBAL';

UPDATE active_boosts ab
JOIN active_boosts keep
  ON keep.player_id = ab.player_id
 AND keep.season_id = ab.season_id
 AND keep.scope = 'SELF'
 AND keep.is_active = 1
 AND (
      keep.expires_tick > ab.expires_tick
      OR (keep.expires_tick = ab.expires_tick AND keep.id < ab.id)
 )
SET ab.is_active = 0
WHERE ab.scope = 'SELF'
  AND ab.is_active = 1;
