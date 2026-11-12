-- Remediate the seven migrations recorded as failed on 2026-07-28 14:33:51.
--
-- WHAT HAPPENED
-- A database initialised fresh from schema.sql ran the auto-migration pass on
-- first boot. Seven files use `ADD COLUMN IF NOT EXISTS` / `CREATE TABLE IF NOT
-- EXISTS` without a PREPARE + INFORMATION_SCHEMA guard - syntax only some
-- MySQL 8.x variants accept, and the README explicitly warns against. They
-- failed on syntax, were recorded status='failed', and are never retried:
--
--   migration_20260329_hoarding_sink_active_seasons_hotfix.sql
--   migration_20260329_sigil_drop_pacing_non_batch.sql
--   migration_20260330_econ_scale_tuning.sql
--   migration_20260330b_boost_tables_runtime_compat.sql
--   migration_20260401_player_vault_caps_boost_rebalance.sql
--   migration_20260413_phase_gate_participation_floor.sql
--   migration_20260413b_tick_runtime_compat.sql
--
-- WHY IT IS COSMETIC HERE
-- Every one of them exists to ADD structure to OLDER databases. On a database
-- built from current schema.sql that structure is already present, so they were
-- attempting work that was already done. Verified: runtime_readiness reports
-- missing_tick_tables = [] and all targeted columns are in schema.sql.
--
-- WHY IT STILL MATTERS
-- runtime_readiness sets status='blocked' whenever ANY row is status='failed'
-- (includes/runtime_readiness.php:166). So the readiness endpoint - the signal
-- you would rely on to spot a real outage - is pinned at blocked by stale
-- bookkeeping. A genuine failure currently looks identical to this.
--
-- WHAT THIS DOES *NOT* DO
-- It does not re-run their contents. Four of the seven carry tuning UPDATEs,
-- and re-applying them would be actively harmful: the 20260329 hoarding hotfix
-- sets hoarding_sink_enabled = 0 and the pre-rebalance rates, which would undo
-- migration_20260731. Their structural work is owned by schema.sql; their
-- tuning is superseded by later migrations that did apply.
--
-- It marks them 'superseded' ONLY IF the structure they were responsible for is
-- actually present. If it is not, they stay 'failed' and readiness keeps
-- alarming - which is the correct outcome, because then something really is
-- missing. This must not become a way to silence a real problem.

SET @structure_ok := (
    SELECT
        (SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'season_participation'
            AND COLUMN_NAME IN ('total_season_participation_ticks','hoarding_sink_total',
                                'pending_rng_sigil_drops','pending_pity_sigil_drops',
                                'sigil_next_delivery_tick')) = 5
    AND (SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'seasons'
            AND COLUMN_NAME IN ('hoarding_sink_enabled','hoarding_safe_hours',
                                'hoarding_safe_min_coins','hoarding_sink_cap_ratio_fp',
                                'hoarding_idle_multiplier_fp')) = 5
    AND (SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME IN ('boost_catalog','active_boosts','active_freezes',
                               'sigil_drop_log','sigil_drop_tracking')) = 5
);

SET @sql := IF(@structure_ok = 1,
    "UPDATE schema_migrations SET status = 'superseded'
      WHERE status = 'failed'
        AND migration_name IN (
            'migration_20260329_hoarding_sink_active_seasons_hotfix.sql',
            'migration_20260329_sigil_drop_pacing_non_batch.sql',
            'migration_20260330_econ_scale_tuning.sql',
            'migration_20260330b_boost_tables_runtime_compat.sql',
            'migration_20260401_player_vault_caps_boost_rebalance.sql',
            'migration_20260413_phase_gate_participation_floor.sql',
            'migration_20260413b_tick_runtime_compat.sql'
        )",
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 'superseded' is skipped by the runner exactly like 'applied' (it only checks
-- whether a row exists) and is ignored by readiness, which filters on 'failed'.
SELECT
    @structure_ok AS structure_verified,
    (SELECT COUNT(*) FROM schema_migrations WHERE status = 'failed') AS still_failed,
    'failed_migration_remediation_complete' AS status;
