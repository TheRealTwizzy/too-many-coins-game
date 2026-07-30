#!/usr/bin/env node
/**
 * Too Many Coins - Client Security Self-Check
 *
 * Guards the security properties of the rebuilt client (public/js/main.js,
 * core/*, screens/*).
 *
 * This check used to extract escapeHtml() out of the legacy app.js and run it
 * against attribute-breakout payloads. That client is gone, and the rebuilt
 * one does not have an escaping function — deliberately. It never builds
 * markup from strings at all: core/render.js creates elements and sets text
 * through createTextNode/`data`, so player-controlled values reach the DOM as
 * text nodes and attribute values, never as parsed HTML.
 *
 * That is a stronger guarantee than escaping, but only while it holds. So the
 * checks below assert the ABSENCE of the sinks rather than the correctness of
 * an escaper: one innerHTML in a screen would reintroduce the entire class of
 * bug that escapeHtml existed to contain.
 *
 * Usage:  node tools/client_security_selfcheck.js
 * Exit:   0 = pass, 1 = fail
 */

const fs = require('fs');
const path = require('path');
const assert = require('assert');

const ROOT = path.join(__dirname, '..', 'public', 'js');

/** Every client source file, with its repo-relative name. */
function clientSources(dir = ROOT, out = []) {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) clientSources(full, out);
        else if (entry.name.endsWith('.js')) {
            out.push({ file: path.relative(ROOT, full), src: fs.readFileSync(full, 'utf8') });
        }
    }
    return out;
}

const sources = clientSources();
const all = sources.map(s => s.src).join('\n');

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

/** Files where a pattern appears, ignoring comment lines. */
function hits(re) {
    const found = [];
    for (const { file, src } of sources) {
        src.split('\n').forEach((line, i) => {
            const code = line.replace(/^\s*(\/\/|\*|\/\*).*$/, '');
            if (re.test(code)) found.push(`${file}:${i + 1}`);
        });
    }
    return found;
}

console.log('Client security self-check');
console.log('-'.repeat(60));
console.log(`  (${sources.length} client source files)`);

let checks = 0;
const guard = (name, fn) => { checks++; check(name, fn); };

// ---- no HTML sinks -------------------------------------------------------
// The reconciler is the only thing that touches the DOM, and it writes text,
// not markup. Every sink below parses its input as HTML, so any one of them
// turns a chat message or a handle back into an injection vector.

guard('no innerHTML / outerHTML assignment anywhere in the client', () => {
    const found = hits(/\.(inner|outer)HTML\s*=/);
    assert.strictEqual(found.length, 0, 'HTML sink found at ' + found.join(', '));
});

guard('no insertAdjacentHTML / document.write', () => {
    const found = hits(/insertAdjacentHTML|document\.write/);
    assert.strictEqual(found.length, 0, 'HTML sink found at ' + found.join(', '));
});

guard('no eval or Function constructor', () => {
    const found = hits(/\beval\s*\(|new Function\s*\(/);
    assert.strictEqual(found.length, 0, 'dynamic code execution at ' + found.join(', '));
});

guard('the reconciler sets text as data, not markup', () => {
    const render = sources.find(s => s.file.endsWith('render.js'));
    assert.ok(render, 'core/render.js not found');
    assert.ok(
        /createTextNode/.test(render.src),
        'render.js no longer creates text nodes - it may be building markup instead'
    );
});

// ---- session handling ----------------------------------------------------
// The server sets an HttpOnly session cookie. Rewriting it from script
// replaces it with a script-readable one and silently undoes that protection.

guard('client never writes the session cookie', () => {
    const found = hits(/document\.cookie\s*=/);
    assert.strictEqual(found.length, 0, 'client-side cookie write at ' + found.join(', '));
});

guard('403 does not force a logout', () => {
    const api = sources.find(s => s.file.endsWith('api.js'));
    assert.ok(api, 'core/api.js not found');
    // 401 means the session is gone; 403 means this account may not do that
    // thing. Treating 403 as a dead session logs staff out of their own tools.
    assert.ok(/401/.test(api.src), 'no 401 handling found - update this check');
    const unauthorizedBlocks = api.src.match(/status\s*===\s*401[^\n]*/g) || [];
    assert.ok(
        !unauthorizedBlocks.some(line => /403/.test(line)),
        'a 403 is treated as an expired session'
    );
});

// ---- transport -----------------------------------------------------------

guard('no third-party origins are contacted from the client', () => {
    // Same rule the CSP enforces for images, applied to every request the
    // client could make: anything off-origin either fails closed or leaks.
    const found = hits(/(fetch|open)\s*\(\s*['"`]https?:\/\//);
    assert.strictEqual(found.length, 0, 'off-origin request at ' + found.join(', '));
});

guard('the stored token is only ever sent as a header', () => {
    // Putting it in a query string writes the credential into access logs,
    // Referer headers and browser history.
    const found = hits(/[?&](token|session)=\$\{/);
    assert.strictEqual(found.length, 0, 'token in a URL at ' + found.join(', '));
});

// ---- staff surfaces ------------------------------------------------------

guard('staff-only screens are gated on the server-published role', () => {
    const main = sources.find(s => s.file === 'main.js');
    assert.ok(/player\.role === 'Admin'|player\.role === 'Moderator'/.test(main.src),
        'staff gating no longer reads the server role');
});

console.log('-'.repeat(60));
if (failures.length === 0) {
    console.log(`Result: PASS (${checks} checks)`);
    process.exit(0);
}
console.log('Result: FAIL - ' + failures.length + ' check(s) failed');
process.exit(1);
