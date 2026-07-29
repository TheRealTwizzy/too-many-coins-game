-- Enable the Sigil Families subsystem.
--
-- migration_20260728_sigil_families_p1_schema.sql seeded every sigil_family row
-- with enabled = 0, and TMC_SIGIL_FAMILIES_ENABLED defaults to '0', so the
-- feature was double-gated off: SigilFamilies::active() requires BOTH the env
-- flag AND at least one enabled material family. This flips the data half.
--
-- The env half is set separately (docker-compose.yml / deploy environment):
--   TMC_SIGIL_FAMILIES_ENABLED=true
--
-- The P1 migration cannot be edited in place - applied migrations are checksum
-- immutable - so this is a new file, per the repo's stated fix protocol.
--
-- Sight is enabled alongside the material families: it is a real player-facing
-- verb, and active() deliberately does not count it toward the gate because a
-- Sight-only roster would have nothing to reveal about.

-- Guarded on the table existing.
--
-- This file was originally named ..._20260728b_..., which sorts BEFORE
-- ..._20260728_sigil_families_p1_schema.sql under the runner's
-- SORT_NATURAL|SORT_FLAG_CASE ordering ('b' collates before '_'). On a fresh
-- database it would therefore have run before the table it updates existed,
-- failed, been recorded status='failed', and never retried - leaving families
-- permanently off with no error surfaced anywhere. Renamed to 20260730 so the
-- order is unambiguous, and guarded here so filename ordering is not the only
-- thing keeping it correct.
SET @has_family_table := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sigil_family'
);
SET @sql := IF(@has_family_table > 0,
    "UPDATE sigil_family SET enabled = 1
     WHERE code IN ('yield','time','ward','larceny','market','sight','wild')",
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- No trailing status SELECT against sigil_family: it would be unguarded and
-- would defeat the check above on a database where the table is absent.
SELECT 'sigil_families_enable_complete' AS status;
