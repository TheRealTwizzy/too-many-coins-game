SET @has_global_star_carry := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'players'
      AND COLUMN_NAME = 'global_stars_fractional_fp'
);

SET @add_global_star_carry_sql := IF(
    @has_global_star_carry = 0,
    'ALTER TABLE players ADD COLUMN global_stars_fractional_fp BIGINT NOT NULL DEFAULT 0 AFTER global_stars',
    'SELECT 1'
);

PREPARE stmt_add_global_star_carry FROM @add_global_star_carry_sql;
EXECUTE stmt_add_global_star_carry;
DEALLOCATE PREPARE stmt_add_global_star_carry;

UPDATE cosmetic_catalog
SET price_global_stars = CASE css_class
    WHEN 'frame-bronze' THEN 25
    WHEN 'frame-silver' THEN 80
    WHEN 'frame-gold' THEN 250
    WHEN 'frame-diamond' THEN 800
    WHEN 'frame-celestial' THEN 2400
    WHEN 'name-ember' THEN 25
    WHEN 'name-ocean' THEN 80
    WHEN 'name-verdant' THEN 250
    WHEN 'name-royal' THEN 800
    WHEN 'name-prismatic' THEN 2400
    WHEN 'bg-parchment' THEN 25
    WHEN 'bg-midnight' THEN 80
    WHEN 'bg-aurora' THEN 250
    WHEN 'bg-volcanic' THEN 800
    WHEN 'bg-void' THEN 2400
    WHEN 'title-newcomer' THEN 25
    WHEN 'title-trader' THEN 80
    WHEN 'title-strategist' THEN 250
    WHEN 'title-magnate' THEN 800
    WHEN 'title-legend' THEN 2400
    WHEN 'effect-sparkle' THEN 80
    WHEN 'effect-flame' THEN 250
    WHEN 'effect-lightning' THEN 800
    WHEN 'effect-supernova' THEN 2400
    ELSE price_global_stars
END
WHERE css_class IN (
    'frame-bronze', 'frame-silver', 'frame-gold', 'frame-diamond', 'frame-celestial',
    'name-ember', 'name-ocean', 'name-verdant', 'name-royal', 'name-prismatic',
    'bg-parchment', 'bg-midnight', 'bg-aurora', 'bg-volcanic', 'bg-void',
    'title-newcomer', 'title-trader', 'title-strategist', 'title-magnate', 'title-legend',
    'effect-sparkle', 'effect-flame', 'effect-lightning', 'effect-supernova'
);