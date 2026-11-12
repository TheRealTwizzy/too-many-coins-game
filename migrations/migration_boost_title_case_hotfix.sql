-- One-time hotfix: remove lowercase prefix words from boost titles.
-- Safe to run multiple times (idempotent).
-- Example fixed: "trickle Trickle" -> "Trickle".

START TRANSACTION;

UPDATE boost_catalog
SET name = TRIM(REGEXP_REPLACE(name, '(^|[[:space:]])[a-z][a-z0-9_\-]*[[:space:]]+', '\\1'))
WHERE name REGEXP '(^|[[:space:]])[a-z][a-z0-9_\-]*[[:space:]]+[A-Z]';

COMMIT;
