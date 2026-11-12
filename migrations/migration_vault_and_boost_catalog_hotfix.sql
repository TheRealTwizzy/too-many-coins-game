-- One-time hotfix: align boost catalog durations and vault prices with canonical values.
-- Safe to run multiple times (idempotent updates).
-- Context: 1 tick = 60 seconds.

START TRANSACTION;

-- Canonical boost durations:
-- Trickle 1h (60), Surge 3h (180), Flow 6h (360), Tide 12h (720), Age 24h (1440).
UPDATE boost_catalog SET duration_ticks = 60   WHERE tier_required = 1;
UPDATE boost_catalog SET duration_ticks = 180  WHERE tier_required = 2;
UPDATE boost_catalog SET duration_ticks = 360  WHERE tier_required = 3;
UPDATE boost_catalog SET duration_ticks = 720  WHERE tier_required = 4;
UPDATE boost_catalog SET duration_ticks = 1440 WHERE tier_required = 5;

-- Canonical vault star prices:
-- Tier 1: 10, Tier 2: 25, Tier 3: 50, Tier 4: 100, Tier 5: 250.
UPDATE season_vault SET current_cost_stars = 10,  last_published_cost_stars = 10  WHERE tier = 1;
UPDATE season_vault SET current_cost_stars = 25,  last_published_cost_stars = 25  WHERE tier = 2;
UPDATE season_vault SET current_cost_stars = 50,  last_published_cost_stars = 50  WHERE tier = 3;
UPDATE season_vault SET current_cost_stars = 100, last_published_cost_stars = 100 WHERE tier = 4;
UPDATE season_vault SET current_cost_stars = 250, last_published_cost_stars = 250 WHERE tier = 5;

-- Keep season vault_config in sync so future cost recalculation remains canonical.
UPDATE seasons
SET vault_config = JSON_ARRAY(
    JSON_OBJECT('tier', 1, 'supply', 2500, 'cost_table', JSON_ARRAY(JSON_OBJECT('remaining', 1, 'cost', 10))),
    JSON_OBJECT('tier', 2, 'supply', 1000, 'cost_table', JSON_ARRAY(JSON_OBJECT('remaining', 1, 'cost', 25))),
    JSON_OBJECT('tier', 3, 'supply', 500,  'cost_table', JSON_ARRAY(JSON_OBJECT('remaining', 1, 'cost', 50))),
    JSON_OBJECT('tier', 4, 'supply', 250,  'cost_table', JSON_ARRAY(JSON_OBJECT('remaining', 1, 'cost', 100))),
    JSON_OBJECT('tier', 5, 'supply', 100,  'cost_table', JSON_ARRAY(JSON_OBJECT('remaining', 1, 'cost', 250)))
)
WHERE JSON_VALID(vault_config) = 1;

COMMIT;
