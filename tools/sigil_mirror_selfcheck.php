<?php
/**
 * Too Many Coins - Sigil Mirror Self-Check
 *
 * Sigils are stored twice, on purpose:
 *
 *   season_participation.sigils_t1..t6   tier totals. Read by lock-in refunds,
 *                                        drop pressure, theft, freeze, melt.
 *   season_sigil_holdings(family, tier)  the family breakdown. Read by the
 *                                        family verbs - ward, market, transmute -
 *                                        through SigilFamilies::spendSpecific().
 *
 * Neither is derivable from the other: the tier columns do not know which family
 * a sigil belongs to, and the mirror only exists for sigils granted since
 * families were enabled. So every operation that moves sigils has to write BOTH,
 * and a site that writes only one silently desynchronises them.
 *
 * That is not a cosmetic problem. A spend that decrements the tier column and
 * leaves the mirror alone leaves the sigil spendable a second time through a
 * family verb - one sigil, two effects. freezePlayerUbi and selfMeltFreeze both
 * shipped that way, despite syncSpendTier's own docblock naming them as callers.
 *
 * This checks statically that every function touching a sigil tier column also
 * calls into SigilFamilies. It cannot prove the call is correct - only that the
 * author did not forget it entirely, which is the failure that actually happened.
 *
 *   php tools/sigil_mirror_selfcheck.php
 *
 * Exit: 0 = every sigil-moving function syncs the mirror, 1 = at least one does not.
 */

$root = dirname(__DIR__);
$files = ['includes/actions.php', 'includes/family_actions.php', 'includes/tick_engine.php'];

// Writes a sigil tier column: either a literal sigils_tN or an interpolated
// column name built from a tier ({$sigilCol}, {$col}, {$fromCol}, {$toCol}).
$WRITE = '/(?:sigils_t[1-6]|\{\$\w*(?:sigilCol|Col|col)\})\s*=/i';
// Any call into the mirror. Deliberately broad - which call is right depends on
// the operation, and this check is about omission, not about correctness.
$SYNC  = '/SigilFamilies::(syncSpendTier|syncTransferTier|spendSpecific|addHolding|clearSeasonHoldings)/';

/**
 * Find every call site of $name across the source and report which of them do
 * NOT have a mirror sync within a few lines.
 *
 * The window is small on purpose. "Somewhere later in the same function" is not
 * good enough to call a pairing deliberate, and widening it until the check
 * passes would defeat the point.
 *
 * @return array{total:int,unsynced:string[]}
 */
function findCallSites(string $root, string $name, array $wrappers = []): array {
    // A direct mirror call, or a call to a helper that makes one. The join path
    // pairs the tier reset with clearSeasonRunAuxiliaryState(), which clears the
    // mirror one level down - that is a real sync, not a missing one.
    $alt = 'SigilFamilies::(?:syncSpendTier|syncTransferTier|spendSpecific|addHolding|clearSeasonHoldings)';
    foreach ($wrappers as $w) { $alt .= '|(?:self::|static::|\\w+::|->)' . preg_quote($w, '/') . '\\s*\\('; }
    $SYNC_NEAR = '/' . $alt . '/';
    $total = 0;
    $unsynced = [];
    foreach (['includes', 'api'] as $dir) {
        $base = $root . DIRECTORY_SEPARATOR . $dir;
        if (!is_dir($base)) { continue; }
        foreach (glob($base . '/*.php') as $path) {
            $lines = file($path, FILE_IGNORE_NEW_LINES);
            foreach ($lines as $i => $line) {
                // The call, not the declaration.
                if (!preg_match('/(?:self::|static::|\w+::|->)' . preg_quote($name, '/') . '\s*\(/', $line)) {
                    continue;
                }
                if (preg_match('/function\s+' . preg_quote($name, '/') . '\s*\(/', $line)) {
                    continue;
                }
                $total++;
                $window = implode("\n", array_slice($lines, max(0, $i - 3), 9));
                if (!preg_match($SYNC_NEAR, $window)) {
                    $unsynced[] = basename($path) . ':' . ($i + 1);
                }
            }
        }
    }
    return ['total' => $total, 'unsynced' => $unsynced];
}

/**
 * Names of functions whose own body calls into the mirror. A call to one of
 * these counts as a sync at the call site.
 */
function findSyncWrappers(string $root): array {
    $SYNC = '/SigilFamilies::(syncSpendTier|syncTransferTier|spendSpecific|addHolding|clearSeasonHoldings)/';
    $out = [];
    foreach (glob($root . '/includes/*.php') as $path) {
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        $cur = null;
        foreach ($lines as $line) {
            if (preg_match('/^\s*(?:public|private|protected)?\s*(?:static\s+)?function\s+(\w+)/', $line, $m)) {
                $cur = $m[1];
            } elseif ($cur !== null && preg_match($SYNC, $line)) {
                $out[$cur] = true;
                $cur = null;   // one hit is enough
            }
        }
    }
    return array_keys($out);
}

$wrappers = findSyncWrappers($root);

$checked = 0;
$failures = [];
$passes = [];

foreach ($files as $rel) {
    $path = $root . DIRECTORY_SEPARATOR . $rel;
    if (!is_file($path)) {
        fwrite(STDERR, "Missing file: {$rel}\n");
        exit(1);
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES);

    // Split into functions. Anything before the first one belongs to no function
    // and is ignored - the writes we care about are all inside methods.
    $bounds = [];
    foreach ($lines as $i => $line) {
        if (preg_match('/^\s*(?:public|private|protected)?\s*(?:static\s+)?function\s+(\w+)/', $line, $m)) {
            $bounds[] = ['name' => $m[1], 'start' => $i];
        }
    }
    for ($b = 0; $b < count($bounds); $b++) {
        $bounds[$b]['end'] = ($b + 1 < count($bounds)) ? $bounds[$b + 1]['start'] - 1 : count($lines) - 1;
    }

    foreach ($bounds as $fn) {
        $body = array_slice($lines, $fn['start'], $fn['end'] - $fn['start'] + 1);
        $writeLines = [];
        foreach ($body as $off => $line) {
            // Skip comments so prose about sigils_t1 does not count as a write.
            $t = ltrim($line);
            if ($t === '' || str_starts_with($t, '//') || str_starts_with($t, '*') || str_starts_with($t, '/*')) {
                continue;
            }
            if (preg_match($WRITE, $line)) {
                $writeLines[] = $fn['start'] + $off + 1;
            }
        }
        if (!$writeLines) {
            continue;
        }
        $checked++;
        $hasSync = false;
        foreach ($body as $line) {
            if (preg_match($SYNC, $line)) { $hasSync = true; break; }
        }
        $label = $rel . '::' . $fn['name'] . '()';
        if ($hasSync) {
            $passes[] = sprintf('%-52s %d write(s), syncs', $label, count($writeLines));
            continue;
        }

        // A private helper can legitimately leave the sync to its caller -
        // resetSeasonParticipationForFreshStart() zeroes the tier columns and
        // the join path clears the mirror on the very next line. Flagging that
        // would be a false positive, and a checker that cries wolf gets ignored,
        // which is worse than not having one.
        //
        // So follow one level: if EVERY call site pairs the call with a sync,
        // the pair is the unit and it is correct. If even one does not, it is
        // still a failure - that is the case this tool exists to catch.
        $callers = findCallSites($root, $fn['name'], $wrappers);
        if ($callers['total'] > 0 && $callers['unsynced'] === []) {
            $passes[] = sprintf('%-52s %d write(s), synced by %d caller(s)',
                $label, count($writeLines), $callers['total']);
            continue;
        }

        $why = $callers['total'] === 0
            ? 'never calls SigilFamilies and has no call site that does'
            : 'never calls SigilFamilies, and ' . count($callers['unsynced'])
              . ' of ' . $callers['total'] . ' call site(s) do not either ('
              . implode(', ', $callers['unsynced']) . ')';
        $failures[] = sprintf(
            "%s writes a sigil tier column at line(s) %s but %s",
            $label, implode(', ', $writeLines), $why
        );
    }
}

echo "Sigil mirror self-check\n" . str_repeat('-', 66) . "\n";
foreach ($passes as $p) { echo "  ok   {$p}\n"; }
foreach ($failures as $f) { echo "  BAD  {$f}\n"; }
echo str_repeat('-', 66) . "\n";
echo "Functions moving sigils: {$checked}\n";

if ($failures) {
    echo "Result: FAIL - " . count($failures) . " function(s) desynchronise the mirror.\n\n";
    echo "A tier-column spend that skips the mirror leaves the sigil spendable a\n";
    echo "second time through a family verb. Add the matching SigilFamilies call:\n";
    echo "  spend  -> syncSpendTier() or spendSpecific()\n";
    echo "  grant  -> addHolding()\n";
    echo "  wipe   -> clearSeasonHoldings()\n";
    exit(1);
}
echo "Result: PASS - every function that moves sigils also syncs the mirror.\n";
exit(0);
