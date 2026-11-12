-- Rename the sigil families for flavour: Nephilim, archangels and demons.
--
-- Display names only. sigil_family.code is the API contract - pickAffinity and
-- transmuteSigils take codes from the client, and affinity_code is returned in
-- responses - so the codes are deliberately untouched. Renaming them would need
-- client and server to move in lockstep and would break any in-flight session.
--
-- Each name is matched to what its family does:
--
--   yield   -> Goliath   the Nephilim giant; Yield amplifies raw income
--   time    -> Anak      progenitor of the long-lived Anakim; Time extends duration
--   ward    -> Michael   the warrior archangel; Ward is the only defensive family
--   larceny -> Valefor   the demon patron of thieves; Larceny is theft
--   market  -> Mammon    wealth and commerce; Market discounts star purchases
--   sight   -> Azazel    the Watcher who taught forbidden knowledge; Sight reveals
--   wild    -> Legion    "for we are many"; Wildcard substitutes for any family
--
-- A separate file rather than an edit to migration_20260728_sigil_families_p1
-- because migrations are checksum-immutable here: editing an applied file logs
-- a checksum warning and never re-runs. House rules as documented there - new
-- file per change, MySQL 5.7+-safe, idempotent.
--
-- Matched on code rather than family_id so this is correct even if ids were
-- ever reassigned, and re-running it is a no-op.

SET @has_sigil_family := (
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'sigil_family'
);

SET @sql := IF(@has_sigil_family > 0,
    "UPDATE `sigil_family` SET `name` = CASE `code`
        WHEN 'yield'   THEN 'Goliath'
        WHEN 'time'    THEN 'Anak'
        WHEN 'ward'    THEN 'Michael'
        WHEN 'larceny' THEN 'Valefor'
        WHEN 'market'  THEN 'Mammon'
        WHEN 'sight'   THEN 'Azazel'
        WHEN 'wild'    THEN 'Legion'
        ELSE `name`
     END
     WHERE `code` IN ('yield','time','ward','larceny','market','sight','wild')",
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
