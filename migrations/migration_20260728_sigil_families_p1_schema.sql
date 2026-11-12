-- Sigil Families P1: catalog, holdings, abilities, wards, season events, affinity.
-- Sigil Systems Spec §8 data model. Behavior-neutral: every table is inert until
-- TMC_SIGIL_FAMILIES_ENABLED is set and families are enabled per-row; the
-- positional sigils_tN array on season_participation stays authoritative and
-- holdings are maintained as a mirror for the whole rollout.
--
-- House rules: new file per change; MySQL 5.7+-safe (no ADD COLUMN IF NOT
-- EXISTS — INFORMATION_SCHEMA-guarded PREPARE/EXECUTE); idempotent; seed
-- derived tables use named column aliases (a UNION ALL of bare literals with
-- duplicate values is rejected by MySQL 8 with error 1060).

CREATE TABLE IF NOT EXISTS sigil_family (
    family_id INT UNSIGNED NOT NULL PRIMARY KEY,
    code VARCHAR(16) NOT NULL UNIQUE,
    name VARCHAR(50) NOT NULL,
    calibration_fp INT NOT NULL DEFAULT 1000000,
    min_tier TINYINT UNSIGNED NOT NULL DEFAULT 1,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sigil_catalog (
    sigil_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    family_id INT UNSIGNED NOT NULL,
    tier TINYINT UNSIGNED NOT NULL,
    utility_value INT NOT NULL DEFAULT 0,
    effect_kind VARCHAR(32) NOT NULL DEFAULT '',
    effect_fp BIGINT NOT NULL DEFAULT 0,
    duration_ticks BIGINT NOT NULL DEFAULT 0,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_catalog_family_tier (family_id, tier),
    CONSTRAINT fk_catalog_family FOREIGN KEY (family_id) REFERENCES sigil_family(family_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sigil_ability (
    ability_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    family_id INT UNSIGNED NOT NULL,
    tier_required TINYINT UNSIGNED NOT NULL DEFAULT 1,
    verb VARCHAR(32) NOT NULL,
    effect_fp BIGINT NOT NULL DEFAULT 0,
    cooldown_ticks BIGINT NOT NULL DEFAULT 0,
    blackout_cooldown_ticks BIGINT NOT NULL DEFAULT 0,
    scope ENUM('SELF', 'TARGET', 'SEASON') NOT NULL DEFAULT 'SELF',
    UNIQUE KEY uq_ability_family_verb (family_id, verb),
    CONSTRAINT fk_ability_family FOREIGN KEY (family_id) REFERENCES sigil_family(family_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS season_sigil_holdings (
    season_id BIGINT UNSIGNED NOT NULL,
    player_id BIGINT UNSIGNED NOT NULL,
    family_id INT UNSIGNED NOT NULL,
    tier TINYINT UNSIGNED NOT NULL,
    count INT NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (season_id, player_id, family_id, tier),
    INDEX idx_holdings_player (player_id, season_id),
    CONSTRAINT fk_holdings_player FOREIGN KEY (player_id) REFERENCES players(player_id),
    CONSTRAINT fk_holdings_season FOREIGN KEY (season_id) REFERENCES seasons(season_id),
    CONSTRAINT fk_holdings_family FOREIGN KEY (family_id) REFERENCES sigil_family(family_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS active_wards (
    ward_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    player_id BIGINT UNSIGNED NOT NULL,
    season_id BIGINT UNSIGNED NOT NULL,
    spent_tier TINYINT UNSIGNED NOT NULL,
    activated_tick BIGINT NOT NULL,
    expires_tick BIGINT NOT NULL,
    blocked_count INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ward_player (player_id, season_id, expires_tick),
    CONSTRAINT fk_ward_player FOREIGN KEY (player_id) REFERENCES players(player_id),
    CONSTRAINT fk_ward_season FOREIGN KEY (season_id) REFERENCES seasons(season_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS season_events (
    event_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    season_id BIGINT UNSIGNED NOT NULL,
    actor_player_id BIGINT UNSIGNED DEFAULT NULL,
    event_kind VARCHAR(32) NOT NULL,
    public_text VARCHAR(255) NOT NULL,
    event_tick BIGINT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_events_season (season_id, event_id),
    CONSTRAINT fk_events_season FOREIGN KEY (season_id) REFERENCES seasons(season_id)
) ENGINE=InnoDB;

-- season_participation gains affinity + market columns (guarded ALTERs).
SET @ddl := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE season_participation ADD COLUMN affinity_family_id INT UNSIGNED DEFAULT NULL',
    'SELECT 1')
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'season_participation'
      AND COLUMN_NAME = 'affinity_family_id');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE season_participation ADD COLUMN affinity_repicked_at_tick BIGINT DEFAULT NULL',
    'SELECT 1')
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'season_participation'
      AND COLUMN_NAME = 'affinity_repicked_at_tick');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE season_participation ADD COLUMN market_pending_vp INT NOT NULL DEFAULT 0',
    'SELECT 1')
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'season_participation'
      AND COLUMN_NAME = 'market_pending_vp');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE season_participation ADD COLUMN market_last_used_tick BIGINT NOT NULL DEFAULT 0',
    'SELECT 1')
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'season_participation'
      AND COLUMN_NAME = 'market_last_used_tick');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Family rows. All disabled: enabling is a per-phase flag flip after sim gates.
-- 'wild' is the Transmute output pseudo-family: never dropped, spends as any.
INSERT INTO sigil_family (family_id, code, name, calibration_fp, min_tier, enabled)
SELECT src.family_id, src.code, src.name, src.calibration_fp, src.min_tier, src.enabled FROM (
    SELECT 1 AS family_id, 'yield' AS code, 'Yield' AS name, 1000000 AS calibration_fp, 1 AS min_tier, 0 AS enabled
    UNION ALL SELECT 2, 'time',    'Time',    1000000, 1, 0
    UNION ALL SELECT 3, 'ward',    'Ward',    1000000, 2, 0
    UNION ALL SELECT 4, 'larceny', 'Larceny', 1000000, 1, 0
    UNION ALL SELECT 5, 'market',  'Market',  1000000, 1, 0
    UNION ALL SELECT 6, 'sight',   'Sight',    200000, 1, 0
    UNION ALL SELECT 7, 'wild',    'Wildcard',1000000, 1, 0
) AS src
LEFT JOIN sigil_family sf ON sf.family_id = src.family_id
WHERE sf.family_id IS NULL;

-- Catalog: family x tier on the game's own value ladder (utility by tier;
-- Sight fixed at 0.2 VP so it can never be a power source). Rule 3: equal
-- tiers are equal value across material families.
INSERT INTO sigil_catalog (family_id, tier, utility_value, effect_kind, enabled)
SELECT src.family_id, src.tier, src.utility_value, src.effect_kind, 1 FROM (
    SELECT 1 AS family_id, 1 AS tier, 50 AS utility_value, 'boost_power' AS effect_kind
    UNION ALL SELECT 1, 2,   250, 'boost_power'
    UNION ALL SELECT 1, 3,  1000, 'boost_power'
    UNION ALL SELECT 1, 4,  3000, 'boost_power'
    UNION ALL SELECT 1, 5,  9000, 'boost_power'
    UNION ALL SELECT 1, 6, 18000, 'boost_power'
    UNION ALL SELECT 2, 1,    50, 'boost_time'
    UNION ALL SELECT 2, 2,   250, 'boost_time'
    UNION ALL SELECT 2, 3,  1000, 'boost_time'
    UNION ALL SELECT 2, 4,  3000, 'boost_time'
    UNION ALL SELECT 2, 5,  9000, 'boost_time'
    UNION ALL SELECT 2, 6, 18000, 'boost_time'
    UNION ALL SELECT 3, 2,   250, 'theft_protection'
    UNION ALL SELECT 3, 3,  1000, 'theft_protection'
    UNION ALL SELECT 3, 4,  3000, 'theft_protection'
    UNION ALL SELECT 3, 5,  9000, 'theft_protection'
    UNION ALL SELECT 3, 6, 18000, 'theft_protection'
    UNION ALL SELECT 4, 1,    50, 'theft_spend'
    UNION ALL SELECT 4, 2,   250, 'theft_spend'
    UNION ALL SELECT 4, 3,  1000, 'theft_spend'
    UNION ALL SELECT 4, 4,  3000, 'theft_spend'
    UNION ALL SELECT 4, 5,  9000, 'theft_spend'
    UNION ALL SELECT 4, 6, 18000, 'theft_spend'
    UNION ALL SELECT 5, 1,    50, 'purchase_discount'
    UNION ALL SELECT 5, 2,   250, 'purchase_discount'
    UNION ALL SELECT 5, 3,  1000, 'purchase_discount'
    UNION ALL SELECT 5, 4,  3000, 'purchase_discount'
    UNION ALL SELECT 5, 5,  9000, 'purchase_discount'
    UNION ALL SELECT 5, 6, 18000, 'purchase_discount'
    UNION ALL SELECT 6, 1,    10, 'reveal'
    UNION ALL SELECT 6, 2,    50, 'reveal'
    UNION ALL SELECT 6, 3,   200, 'reveal'
    UNION ALL SELECT 6, 4,   600, 'reveal'
    UNION ALL SELECT 6, 5,  1800, 'reveal'
    UNION ALL SELECT 6, 6,  3600, 'reveal'
    UNION ALL SELECT 7, 1,    50, 'wildcard'
    UNION ALL SELECT 7, 2,   250, 'wildcard'
    UNION ALL SELECT 7, 3,  1000, 'wildcard'
    UNION ALL SELECT 7, 4,  3000, 'wildcard'
    UNION ALL SELECT 7, 5,  9000, 'wildcard'
    UNION ALL SELECT 7, 6, 18000, 'wildcard'
) AS src
LEFT JOIN sigil_catalog sc ON sc.family_id = src.family_id AND sc.tier = src.tier
WHERE sc.sigil_id IS NULL;

-- Ability registry (verbs; numeric effects live in config, resolved at spend).
INSERT INTO sigil_ability (family_id, tier_required, verb, scope)
SELECT src.family_id, src.tier_required, src.verb, src.scope FROM (
    SELECT 1 AS family_id, 1 AS tier_required, 'boost_power' AS verb, 'SELF' AS scope
    UNION ALL SELECT 2, 1, 'boost_time',        'SELF'
    UNION ALL SELECT 3, 2, 'ward_activate',     'SELF'
    UNION ALL SELECT 4, 3, 'theft_attempt',     'TARGET'
    UNION ALL SELECT 5, 1, 'market_prime',      'SELF'
    UNION ALL SELECT 6, 1, 'sight_reveal',      'SEASON'
) AS src
LEFT JOIN sigil_ability sa ON sa.family_id = src.family_id AND sa.verb = src.verb
WHERE sa.ability_id IS NULL;
