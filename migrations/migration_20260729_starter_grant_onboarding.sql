-- Starter grant: give a first-time player something to forge with.
--
-- A new player currently joins a season holding nothing and waits out several
-- drop intervals before the sigil forge means anything. This grants a small
-- number of Tier 1 sigils on the first season a player ever joins, so the
-- combine loop is legible immediately.
--
-- Sigils rather than coins, deliberately. Coins would enter the season's coin
-- supply and nudge the inflation dampener and the star price for everyone;
-- sigils do neither.
--
-- WHY THE SECOND COLUMN EXISTS
--
-- Lock-in refunds sigils at SIGIL_REFERENCE_STARS_BY_TIER and converts the
-- total to global stars. Granted sigils are indistinguishable from earned ones
-- at that point, so an ungated grant is a signup bonus paid in global stars -
-- the permanent, cross-season currency. season_participation.starter_grant_stars
-- records the star value handed over so lock-in can net it back out.
--
-- Value rather than per-tier counts, because sigils combine: five granted T1s
-- become one T2, and a per-tier subtraction would then find no T1s to subtract
-- and refund the T2 in full. Reference value is preserved by a T1->T2 combine
-- (5 x 50 = 250) and only ever decreases above that, so netting the original
-- value out is exact at worst and conservative at best.
--
-- House rules followed: new file per change; MySQL 5.7+-safe (no
-- ADD COLUMN IF NOT EXISTS - INFORMATION_SCHEMA-guarded PREPARE/EXECUTE);
-- idempotent; behaviour-neutral until the code that reads these columns ships.

-- players.starter_grant_at - the once-ever guard.
-- On the account rather than on participation, because participation is reset
-- on rejoin (resetSeasonParticipationForFreshStart) and a per-season flag would
-- be cleared along with it, making the grant farmable by leaving and rejoining.
SET @ddl := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE players ADD COLUMN starter_grant_at DATETIME DEFAULT NULL',
    'SELECT 1')
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'players'
      AND COLUMN_NAME = 'starter_grant_at');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- season_participation.starter_grant_stars - the value to net out at lock-in.
SET @ddl := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE season_participation ADD COLUMN starter_grant_stars INT NOT NULL DEFAULT 0',
    'SELECT 1')
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'season_participation'
      AND COLUMN_NAME = 'starter_grant_stars');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
