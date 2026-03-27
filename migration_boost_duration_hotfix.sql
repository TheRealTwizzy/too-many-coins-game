-- One-time hotfix: normalize legacy boost durations/descriptions in existing databases.
-- Safe to run multiple times (idempotent updates).
-- Context: 1 tick = 1 minute.
-- - SELF boosts should be minute-scale (1/2/3 ticks).
-- - GLOBAL seasonal boosts remain hour-scale (60/120 ticks).

START TRANSACTION;

-- Fix legacy self boosts that were previously seeded as 60/120/180 ticks (hours wording)
-- and normalize to minute-based canonical values.
UPDATE boost_catalog
SET
    duration_ticks = CASE
        WHEN duration_ticks = 60 THEN 1
        WHEN duration_ticks = 120 THEN 2
        WHEN duration_ticks = 180 THEN 3
        ELSE duration_ticks
    END,
    description = CASE
        WHEN description LIKE '%for 1 hour%' THEN REPLACE(description, 'for 1 hour', 'for 1 minute')
        WHEN description LIKE '%for 2 hours%' THEN REPLACE(description, 'for 2 hours', 'for 2 minutes')
        WHEN description LIKE '%for 3 hours%' THEN REPLACE(description, 'for 3 hours', 'for 3 minutes')
        ELSE description
    END
WHERE
    scope = 'SELF'
    AND (
        duration_ticks IN (60, 120, 180)
        OR description LIKE '%for 1 hour%'
        OR description LIKE '%for 2 hours%'
        OR description LIKE '%for 3 hours%'
    );

-- Ensure seasonal/global defaults are explicitly hour-scale as intended.
-- These updates are no-ops if already correct.
UPDATE boost_catalog
SET duration_ticks = 60
WHERE scope = 'GLOBAL' AND modifier_fp = 150000;

UPDATE boost_catalog
SET duration_ticks = 120
WHERE scope = 'GLOBAL' AND modifier_fp = 300000;

COMMIT;
