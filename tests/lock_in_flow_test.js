/**
 * Tests for early lock-in confirmation flow (T6 warning order) and
 * ISO UTC datetime formatting.
 * Run with: node tests/lock_in_flow_test.js
 */

'use strict';

const assert = require('assert');

// ---------------------------------------------------------------------------
// Minimal stub of TMC.confirmLockIn() logic under test.
// Mirrors the logic in public/js/app.js without DOM or real confirm().
// ---------------------------------------------------------------------------

/**
 * Simulate confirmLockIn() with controllable confirm responses.
 *
 * @param {number}   t6Count          - Number of T6 sigils the player owns.
 * @param {boolean[]} confirmResponses - Responses to successive confirm() calls (FIFO).
 * @returns {{ confirmCalls: string[], proceeded: boolean }}
 */
function simulateConfirmLockIn(t6Count, confirmResponses) {
    const responses = [...confirmResponses];
    const confirmCalls = [];

    function fakeConfirm(message) {
        confirmCalls.push(message);
        return responses.shift() !== false; // treat undefined as true
    }

    function formatNumber(n) { return String(n); }

    // --- replicate confirmLockIn logic ---
    const sigils = [0, 0, 0, 0, 0, t6Count];
    const stars = 500;

    if (t6Count > 0) {
        const proceed = fakeConfirm(
            `⚠️ T6 Sigil Destruction Warning\n\n` +
            `You own ${t6Count} Tier 6 Sigil(s). ` +
            `T6 Sigils will be DESTROYED with NO refund upon Lock-In.\n\n` +
            `Do you wish to continue?`
        );
        if (!proceed) return { confirmCalls, proceeded: false };
    }

    const proceed2 = fakeConfirm(
        `Are you sure you want to Lock-In?\n\n` +
        `This will:\n` +
        `- Refund T1–T5 Sigils back to Seasonal Stars\n` +
        `- Convert all Seasonal Stars to Global Stars at 65% (rounded down)\n` +
        `- Destroy ALL your Coins, T6 Sigils, and Boosts\n` +
        `- Remove you from this season\n\n` +
        `Current Seasonal Stars: ${formatNumber(stars)} ` +
        `(final Global Stars payout will be floor(total × 0.65))\n\n` +
        `This action is IRREVERSIBLE.`
    );
    if (!proceed2) return { confirmCalls, proceeded: false };

    return { confirmCalls, proceeded: true };
}

// ---------------------------------------------------------------------------
// T6 confirmation order / presence tests
// ---------------------------------------------------------------------------

// No T6 sigils → only one confirm (standard lock-in), no T6 warning
{
    const r = simulateConfirmLockIn(0, [true]);
    assert.strictEqual(r.confirmCalls.length, 1,
        'no T6: should show exactly 1 confirm dialog');
    assert.ok(!r.confirmCalls[0].includes('T6 Sigil Destruction'),
        'no T6: the single dialog must NOT be the T6 warning');
    assert.ok(r.confirmCalls[0].includes('Lock-In'),
        'no T6: the single dialog must be the lock-in confirmation');
    assert.ok(r.proceeded, 'no T6: should proceed when player confirms');
}

// Has T6 sigils → T6 warning appears FIRST, then lock-in confirmation
{
    const r = simulateConfirmLockIn(2, [true, true]);
    assert.strictEqual(r.confirmCalls.length, 2,
        'has T6: should show exactly 2 confirm dialogs');
    assert.ok(r.confirmCalls[0].includes('T6 Sigil Destruction'),
        'has T6: first dialog must be T6 destruction warning');
    assert.ok(r.confirmCalls[1].includes('Lock-In'),
        'has T6: second dialog must be lock-in confirmation');
    assert.ok(r.proceeded, 'has T6: should proceed when player confirms both');
}

// Has T6 sigils → player cancels T6 warning → lock-in confirmation NOT shown
{
    const r = simulateConfirmLockIn(1, [false]);
    assert.strictEqual(r.confirmCalls.length, 1,
        'T6 cancel: should stop after T6 warning');
    assert.ok(r.confirmCalls[0].includes('T6 Sigil Destruction'),
        'T6 cancel: dialog shown must be T6 warning');
    assert.ok(!r.proceeded, 'T6 cancel: should NOT proceed after T6 warning cancelled');
}

// Has T6 sigils → passes T6 warning, cancels lock-in confirmation
{
    const r = simulateConfirmLockIn(3, [true, false]);
    assert.strictEqual(r.confirmCalls.length, 2,
        'T6 ok then cancel: should show both dialogs');
    assert.ok(!r.proceeded, 'T6 ok then cancel: should NOT proceed after lock-in confirmation cancelled');
}

// No T6 sigils → player cancels lock-in confirmation → should NOT proceed
{
    const r = simulateConfirmLockIn(0, [false]);
    assert.strictEqual(r.confirmCalls.length, 1,
        'no T6 cancel: should show exactly 1 dialog');
    assert.ok(!r.proceeded, 'no T6 cancel: should NOT proceed after cancellation');
}

// T6 warning mentions exact count
{
    const r = simulateConfirmLockIn(5, [false]);
    assert.ok(r.confirmCalls[0].includes('5 Tier 6 Sigil'),
        'T6 warning should mention the specific T6 sigil count');
}

// ---------------------------------------------------------------------------
// ISO UTC datetime formatting tests (mirrors iso_utc_datetime() PHP helper)
// ---------------------------------------------------------------------------

function isoUtcDatetime(dt) {
    if (dt === null || dt === '') return null;
    return dt.replace(' ', 'T') + '+00:00';
}

// Normal MySQL DATETIME → ISO 8601 UTC
assert.strictEqual(
    isoUtcDatetime('2026-03-29 12:34:56'),
    '2026-03-29T12:34:56+00:00',
    'MySQL DATETIME should be converted to ISO 8601 UTC'
);

// Midnight edge case
assert.strictEqual(
    isoUtcDatetime('2026-01-01 00:00:00'),
    '2026-01-01T00:00:00+00:00',
    'Midnight DATETIME should be converted correctly'
);

// null → null
assert.strictEqual(
    isoUtcDatetime(null),
    null,
    'null input should return null'
);

// empty string → null
assert.strictEqual(
    isoUtcDatetime(''),
    null,
    'empty string input should return null'
);

// Verify JS Date parses result as UTC (not local time)
{
    const isoStr = isoUtcDatetime('2026-03-29 12:00:00');
    const d = new Date(isoStr);
    assert.ok(!Number.isNaN(d.getTime()), 'ISO string should parse to valid Date');
    // UTC hour must be 12 regardless of runtime timezone
    assert.strictEqual(d.getUTCHours(), 12, 'ISO string with +00:00 must parse as UTC hour 12');
    assert.strictEqual(d.getUTCMinutes(), 0, 'ISO string must parse UTC minutes correctly');
}

console.log('All lock-in flow and datetime tests passed.');
