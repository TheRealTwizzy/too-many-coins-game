-- Email verification: token action type, and grandfathering.
--
-- Joining a season now requires a confirmed email address. That guard is only
-- as safe as the flow that clears it, so this migration does two things.
--
-- 1. Adds EMAIL_VERIFY to account_verification_tokens.action_type. The table,
--    its hashing, expiry and single-use consumption already exist for account
--    deletion; only the enum lacked a value for this use.
--
-- 2. Marks every account that already exists as confirmed.
--
-- The second is the consequential one, so it is stated plainly rather than
-- buried: without it, deploying this locks every current player out of joining
-- a season until they complete a flow that did not exist when they signed up,
-- using mail that may not be configured yet. Grandfathering keeps the new rule
-- pointed at new signups, which is who it is for. Existing accounts have not
-- proved control of their address and this does not pretend otherwise - it
-- records that nobody ever asked them to.
--
-- To apply the rule to existing accounts instead, clear them afterwards:
--   UPDATE players SET email_verified_at = NULL WHERE created_at < '<cutoff>';
-- and be certain outbound mail works first (php tools/mail_selftest.php).
--
-- MySQL 5.7+ compatible: INFORMATION_SCHEMA guards, idempotent, works under
-- `mysql < file.sql`.

SET @_tmc_enum_ok = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'account_verification_tokens'
    AND COLUMN_NAME = 'action_type' AND COLUMN_TYPE LIKE '%EMAIL_VERIFY%');
SET @_tmc_sql = IF(@_tmc_enum_ok > 0, 'SELECT 1',
    "ALTER TABLE account_verification_tokens MODIFY action_type
     ENUM('SELF_DELETE','STAFF_DELETE','EMAIL_CHANGE','ADMIN_GLOBAL_RESET','EMAIL_VERIFY') NOT NULL");
PREPARE _tmc_stmt FROM @_tmc_sql;
EXECUTE _tmc_stmt;
DEALLOCATE PREPARE _tmc_stmt;

-- Grandfather existing accounts. Guarded on the marker row below so a re-run
-- cannot sweep up accounts created after the first application.
SET @_tmc_done = (SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '_tmc_email_verify_grandfathered');
SET @_tmc_sql = IF(@_tmc_done > 0, 'SELECT 1',
    'UPDATE players SET email_verified_at = created_at WHERE email_verified_at IS NULL');
PREPARE _tmc_stmt FROM @_tmc_sql;
EXECUTE _tmc_stmt;
DEALLOCATE PREPARE _tmc_stmt;

CREATE TABLE IF NOT EXISTS _tmc_email_verify_grandfathered (
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
