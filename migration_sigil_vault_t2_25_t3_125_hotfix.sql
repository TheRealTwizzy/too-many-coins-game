-- Hotfix: Set canonical Sigil Vault prices to T1=5, T2=25, T3=125 stars.
-- Updates live season_vault rows for all non-expired seasons and aligns
-- the seasons.vault_config JSON blob used for future season seeding reads.
-- Safe to run multiple times (idempotent SET).

START TRANSACTION;

-- Update live vault inventory prices.
UPDATE season_vault sv
JOIN seasons s ON s.season_id = sv.season_id
SET
    sv.current_cost_stars = CASE sv.tier
        WHEN 1 THEN 5
        WHEN 2 THEN 25
        WHEN 3 THEN 125
        ELSE sv.current_cost_stars
    END,
    sv.last_published_cost_stars = CASE sv.tier
        WHEN 1 THEN 5
        WHEN 2 THEN 25
        WHEN 3 THEN 125
        ELSE sv.last_published_cost_stars
    END
WHERE s.status IN ('Scheduled', 'Active', 'Blackout')
  AND sv.tier BETWEEN 1 AND 3;

-- Keep canonical seasons.vault_config aligned for future reads/new season defaults.
UPDATE seasons
SET vault_config = JSON_ARRAY(
    JSON_OBJECT('tier', 1, 'supply', 1000, 'cost_table', JSON_ARRAY(JSON_OBJECT('remaining', 1, 'cost', 5))),
    JSON_OBJECT('tier', 2, 'supply', 500, 'cost_table', JSON_ARRAY(JSON_OBJECT('remaining', 1, 'cost', 25))),
    JSON_OBJECT('tier', 3, 'supply', 250, 'cost_table', JSON_ARRAY(JSON_OBJECT('remaining', 1, 'cost', 125)))
)
WHERE status IN ('Scheduled', 'Active', 'Blackout')
  AND JSON_VALID(vault_config) = 1;

COMMIT;
