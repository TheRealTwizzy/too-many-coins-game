-- Rebalance migration: player-scoped vault inventory, vault repricing/relimits, and canonical defaults.
-- Safe to rerun: uses IF NOT EXISTS / idempotent upserts.

CREATE TABLE IF NOT EXISTS player_season_vault (
    player_id BIGINT UNSIGNED NOT NULL,
    season_id BIGINT UNSIGNED NOT NULL,
    tier TINYINT UNSIGNED NOT NULL,
    initial_supply INT NOT NULL DEFAULT 0,
    remaining_supply INT NOT NULL DEFAULT 0,
    current_cost_stars BIGINT NOT NULL DEFAULT 0,
    last_published_cost_stars BIGINT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (player_id, season_id, tier),
    INDEX idx_psv_season_player (season_id, player_id),
    CONSTRAINT fk_psv_player FOREIGN KEY (player_id) REFERENCES players(player_id),
    CONSTRAINT fk_psv_season FOREIGN KEY (season_id) REFERENCES seasons(season_id)
) ENGINE=InnoDB;

-- Canonical vault defaults used for new/active seasons.
UPDATE seasons
SET vault_config = JSON_ARRAY(
    JSON_OBJECT('tier', 1, 'supply', 500, 'cost_table', JSON_ARRAY(JSON_OBJECT('remaining', 1, 'cost', 50))),
    JSON_OBJECT('tier', 2, 'supply', 250, 'cost_table', JSON_ARRAY(JSON_OBJECT('remaining', 1, 'cost', 250))),
    JSON_OBJECT('tier', 3, 'supply', 125, 'cost_table', JSON_ARRAY(JSON_OBJECT('remaining', 1, 'cost', 1000)))
);

-- Keep legacy/shared table aligned for valuation compatibility.
UPDATE season_vault
SET initial_supply = CASE tier
    WHEN 1 THEN 500
    WHEN 2 THEN 250
    WHEN 3 THEN 125
    ELSE initial_supply
END,
remaining_supply = LEAST(
    CASE tier
        WHEN 1 THEN 500
        WHEN 2 THEN 250
        WHEN 3 THEN 125
        ELSE remaining_supply
    END,
    remaining_supply
),
current_cost_stars = CASE tier
    WHEN 1 THEN 50
    WHEN 2 THEN 250
    WHEN 3 THEN 1000
    ELSE current_cost_stars
END,
last_published_cost_stars = CASE tier
    WHEN 1 THEN 50
    WHEN 2 THEN 250
    WHEN 3 THEN 1000
    ELSE last_published_cost_stars
END
WHERE tier IN (1, 2, 3);

-- Ensure legacy/shared rows exist for all seasons and tiers (1..3).
INSERT INTO season_vault (season_id, tier, initial_supply, remaining_supply, current_cost_stars, last_published_cost_stars)
SELECT s.season_id,
       t.tier,
       CASE t.tier WHEN 1 THEN 500 WHEN 2 THEN 250 WHEN 3 THEN 125 END AS initial_supply,
       CASE t.tier WHEN 1 THEN 500 WHEN 2 THEN 250 WHEN 3 THEN 125 END AS remaining_supply,
       CASE t.tier WHEN 1 THEN 50 WHEN 2 THEN 250 WHEN 3 THEN 1000 END AS current_cost_stars,
       CASE t.tier WHEN 1 THEN 50 WHEN 2 THEN 250 WHEN 3 THEN 1000 END AS last_published_cost_stars
FROM seasons s
JOIN (
    SELECT 1 AS tier UNION ALL SELECT 2 UNION ALL SELECT 3
) AS t
LEFT JOIN season_vault sv
    ON sv.season_id = s.season_id AND sv.tier = t.tier
WHERE sv.season_id IS NULL;

-- Backfill player-scoped vault rows for all existing participation rows.
INSERT INTO player_season_vault (player_id, season_id, tier, initial_supply, remaining_supply, current_cost_stars, last_published_cost_stars)
SELECT sp.player_id,
       sp.season_id,
       t.tier,
       CASE t.tier WHEN 1 THEN 500 WHEN 2 THEN 250 WHEN 3 THEN 125 END AS initial_supply,
       CASE t.tier WHEN 1 THEN 500 WHEN 2 THEN 250 WHEN 3 THEN 125 END AS remaining_supply,
       CASE t.tier WHEN 1 THEN 50 WHEN 2 THEN 250 WHEN 3 THEN 1000 END AS current_cost_stars,
       CASE t.tier WHEN 1 THEN 50 WHEN 2 THEN 250 WHEN 3 THEN 1000 END AS last_published_cost_stars
FROM season_participation sp
JOIN (
    SELECT 1 AS tier UNION ALL SELECT 2 UNION ALL SELECT 3
) AS t
ON 1 = 1
ON DUPLICATE KEY UPDATE
    player_id = player_id;