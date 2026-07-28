<?php
/**
 * Too Many Coins - Resource Integrity Self-Check
 *
 * Static guard against the double-spend bug class: a balance or sigil column
 * decremented by an UPDATE whose result is never verified.
 *
 * Every mutating action reads state, validates in PHP, then writes. When the read
 * happens outside the transaction and the write is relative ("coins = coins - ?")
 * with no guard, N concurrent requests all pass validation and all commit. The
 * columns are signed, so balances go negative rather than erroring.
 *
 * Two acceptable shapes for a decrementing UPDATE:
 *   1. Guarded + checked - the statement carries "AND <col> >= ?" and the caller
 *      asserts rowCount() === 1.
 *   2. Lock-protected  - the row was already taken with SELECT ... FOR UPDATE
 *      inside the same transaction.
 *
 * Usage:  php tools/integrity_selfcheck.php [--verbose]
 * Exit:   0 = all decrements protected, 1 = at least one unprotected
 */

$verbose = in_array('--verbose', $argv, true);
$root = dirname(__DIR__);

// Columns whose decrement can create value if it double-applies.
$sensitive = [
    'coins',
    'seasonal_stars',
    'global_stars',
    'count',                 // season_sigil_holdings mirror
    '{$sigilCol}',
    '{$fromCol}',
    '{$col}',
    '{$toCol}',
];

$targets = [
    'includes/actions.php',
    'includes/family_actions.php',
    'includes/sigil_families.php',
    'includes/tick_engine.php',
    'api/index.php',
];

/** Extract each $db->query(...) call with its byte offset. */
function extractQueryCalls(string $src): array {
    $calls = [];
    $len = strlen($src);
    $needle = '->query(';
    $pos = 0;
    while (($pos = strpos($src, $needle, $pos)) !== false) {
        $open = $pos + strlen($needle) - 1;   // at '('
        $depth = 0;
        $end = $open;
        $inStr = false;
        $quote = '';
        for ($i = $open; $i < $len; $i++) {
            $ch = $src[$i];
            if ($inStr) {
                if ($ch === '\\') { $i++; continue; }
                if ($ch === $quote) { $inStr = false; }
                continue;
            }
            if ($ch === '"' || $ch === "'") { $inStr = true; $quote = $ch; continue; }
            if ($ch === '(') { $depth++; }
            elseif ($ch === ')') { $depth--; if ($depth === 0) { $end = $i; break; } }
        }
        $calls[] = ['offset' => $pos, 'text' => substr($src, $pos, $end - $pos + 1)];
        $pos = $end > $pos ? $end : $pos + 1;
    }
    return $calls;
}

function lineOf(string $src, int $offset): int {
    return substr_count(substr($src, 0, $offset), "\n") + 1;
}

/**
 * True when this decrement sits inside a transaction that already took the row
 * with SELECT ... FOR UPDATE. Scans back to the enclosing beginTransaction().
 */
function isLockProtected(string $src, int $offset): bool {
    $begin = strrpos(substr($src, 0, $offset), 'beginTransaction');
    if ($begin === false) {
        return false;
    }
    return stripos(substr($src, $begin, $offset - $begin), 'FOR UPDATE') !== false;
}

$findings = [];
$checkedCount = 0;

foreach ($targets as $rel) {
    $path = $root . DIRECTORY_SEPARATOR . $rel;
    if (!is_file($path)) {
        continue;
    }
    $src = file_get_contents($path);

    foreach (extractQueryCalls($src) as $call) {
        $text = $call['text'];

        if (stripos($text, 'UPDATE') === false) {
            continue;
        }

        // Which sensitive column, if any, does this statement decrement?
        $decremented = null;
        foreach ($sensitive as $col) {
            $q = preg_quote($col, '/');
            // "col = col - " with optional whitespace/newlines.
            if (preg_match('/' . $q . '\s*=\s*' . $q . '\s*-\s*/', $text)) {
                $decremented = $col;
                break;
            }
        }
        if ($decremented === null) {
            continue;
        }

        $checkedCount++;
        $line = lineOf($src, $call['offset']);

        // Shape 2: already row-locked.
        if (isLockProtected($src, $call['offset'])) {
            if ($verbose) {
                echo "  ok (FOR UPDATE)   {$rel}:{$line}  {$decremented}\n";
            }
            continue;
        }

        // Shape 1a: the statement must carry a "AND <col> >= ..." guard.
        $q = preg_quote($decremented, '/');
        $hasGuard = preg_match('/' . $q . '\s*>=\s*/', $text) === 1;

        // Shape 1b: the caller must consume the result via rowCount().
        // The assignment precedes the call on the same statement, so look at the
        // line the call starts on plus a short window after the closing paren.
        $lineStart = strrpos(substr($src, 0, $call['offset']), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $window = substr($src, $lineStart, ($call['offset'] - $lineStart) + strlen($text) + 200);
        $hasRowCount = stripos($window, 'rowCount()') !== false;

        if ($hasGuard && $hasRowCount) {
            if ($verbose) {
                echo "  ok (guard+check)  {$rel}:{$line}  {$decremented}\n";
            }
            continue;
        }

        $missing = [];
        if (!$hasGuard)    { $missing[] = 'no "AND ' . $decremented . ' >= ?" guard'; }
        if (!$hasRowCount) { $missing[] = 'result not checked via rowCount()'; }

        $findings[] = [
            'file'   => $rel,
            'line'   => $line,
            'column' => $decremented,
            'why'    => implode('; ', $missing),
        ];
    }
}

echo "Resource integrity self-check\n";
echo str_repeat('-', 60) . "\n";
echo "Decrementing statements inspected: {$checkedCount}\n";

if (empty($findings)) {
    echo "Result: PASS - every decrement is guarded+checked or row-locked.\n";
    exit(0);
}

echo "Result: FAIL - " . count($findings) . " unprotected decrement(s):\n\n";
foreach ($findings as $f) {
    echo "  {$f['file']}:{$f['line']}\n";
    echo "    column: {$f['column']}\n";
    echo "    issue:  {$f['why']}\n\n";
}
echo "Fix: add \"AND <col> >= ?\" to the UPDATE and assert rowCount() === 1,\n";
echo "     or take the row with SELECT ... FOR UPDATE inside the transaction.\n";
exit(1);
