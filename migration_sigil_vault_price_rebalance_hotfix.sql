-- Reprice Sigil Vault tiers to latest balance targets.
-- Applies to all seasons so displayed and charged values stay aligned.

UPDATE season_vault
SET
    current_cost_stars = CASE tier
        WHEN 1 THEN 5
        WHEN 2 THEN 25
        WHEN 3 THEN 75
        WHEN 4 THEN 150
        WHEN 5 THEN 300
        ELSE current_cost_stars
    END,
    last_published_cost_stars = CASE tier
        WHEN 1 THEN 5
        WHEN 2 THEN 25
        WHEN 3 THEN 75
        WHEN 4 THEN 150
        WHEN 5 THEN 300
        ELSE last_published_cost_stars
    END
WHERE tier BETWEEN 1 AND 5;
