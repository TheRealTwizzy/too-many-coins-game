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

UPDATE sigil_family
SET enabled = 1
WHERE code IN ('yield', 'time', 'ward', 'larceny', 'market', 'sight', 'wild');

SELECT
    COUNT(*) AS enabled_families,
    'sigil_families_enabled' AS status
FROM sigil_family
WHERE enabled = 1;
