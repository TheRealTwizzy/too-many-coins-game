#!/usr/bin/env node
/**
 * client_core_selfcheck.mjs — regression harness for the ?ui=next client core.
 *
 *   node tools/client_core_selfcheck.mjs
 *
 * Read-only: touches nothing outside a temp directory, talks to no database,
 * makes no network calls.
 *
 * What it guards is narrow and load-bearing. The whole reason the rebuilt
 * client exists is that the legacy one rebuilds screens with innerHTML on a
 * 3s poll and thereby eats focus, carets, scroll position and running
 * animations. core/render.js is the thing that stops that happening, and
 * core/store.js is what tells it how little needs to change. A regression in
 * either is invisible in a screenshot and obvious to a player mid-sentence,
 * so the assertions below are mostly about what must *not* be touched.
 *
 * Node has no DOM and jsdom is not a dependency of this repo, so a shim
 * covering exactly the surface render.js uses is defined inline. It counts
 * writes, which is what lets the tests assert "did not touch" rather than
 * merely "ended up correct".
 */

import { mkdtemp, copyFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const CORE = join(HERE, '..', 'public', 'js', 'core');

/* ------------------------------------------------------------------ *
 * module loading
 *
 * The core modules are ES modules with a .js extension, and this repo has no
 * package.json declaring {"type":"module"} — deliberately, since adding one
 * risks changing how the deployment image is detected and built. So they are
 * copied to a temp dir as .mjs and imported from there. None of them import
 * each other, so a flat copy is sufficient.
 * ------------------------------------------------------------------ */

async function loadCore() {
    const dir = await mkdtemp(join(tmpdir(), 'tmc-core-'));
    const names = ['store', 'api', 'render', 'clock', 'motion'];
    const loaded = {};
    for (const name of names) {
        const dest = join(dir, `${name}.mjs`);
        await copyFile(join(CORE, `${name}.js`), dest);
        loaded[name] = await import(pathToFileURL(dest).href);
    }
    return { modules: loaded, cleanup: () => rm(dir, { recursive: true, force: true }) };
}

/* ------------------------------------------------------------------ *
 * assertions
 * ------------------------------------------------------------------ */

let pass = 0;
let fail = 0;
let group = '';

function section(name) { group = name; console.log(`\n${name}`); }

function ok(name, condition, detail) {
    if (condition) {
        pass++;
        console.log(`  ok    ${name}`);
    } else {
        fail++;
        console.log(`  FAIL  ${name}${detail !== undefined ? `\n          got: ${JSON.stringify(detail)}` : ''}`);
    }
}

const nextMicrotask = () => new Promise(resolve => setTimeout(resolve, 0));

/* ------------------------------------------------------------------ *
 * DOM shim
 * ------------------------------------------------------------------ */

const TEXT_ENTRY = new Set(['text', 'search', 'url', 'tel', 'password', 'email', 'number']);

class ClassList {
    constructor(el) { this.el = el; this.tokens = []; }
    get length() { return this.tokens.length; }
    contains(t) { return this.tokens.includes(t); }
    add(t) { if (!this.contains(t)) { this.tokens.push(t); this.el.classOps.push('+' + t); } }
    remove(t) {
        const i = this.tokens.indexOf(t);
        if (i >= 0) { this.tokens.splice(i, 1); this.el.classOps.push('-' + t); }
    }
}

const indexable = (list) => new Proxy(list, {
    get(target, prop) {
        if (typeof prop === 'string' && /^\d+$/.test(prop)) return target.tokens[Number(prop)];
        const value = target[prop];
        return typeof value === 'function' ? value.bind(target) : value;
    },
});

class Style {
    constructor() { this.props = {}; }
    setProperty(name, value) {
        if (value === '') delete this.props[name];
        else this.props[name] = value;
    }
}

let nodeId = 0;

class ShimNode {
    constructor(doc) { this.ownerDocument = doc; this.parentNode = null; this.childNodes = []; this.id = ++nodeId; }
    get firstChild() { return this.childNodes[0] || null; }
    get isConnected() {
        let node = this;
        while (node) { if (node === this.ownerDocument.body) return true; node = node.parentNode; }
        return false;
    }
    appendChild(child) { return this.insertBefore(child, null); }
    insertBefore(child, ref) {
        if (child.parentNode) child.parentNode.removeChild(child);
        const at = ref ? this.childNodes.indexOf(ref) : -1;
        if (at >= 0) this.childNodes.splice(at, 0, child);
        else this.childNodes.push(child);
        child.parentNode = this;
        return child;
    }
    removeChild(child) {
        const at = this.childNodes.indexOf(child);
        if (at >= 0) { this.childNodes.splice(at, 1); child.parentNode = null; }
        return child;
    }
}

class ShimText extends ShimNode {
    constructor(doc, data) { super(doc); this.writes = 0; this.data = String(data); }
    set data(v) { this.raw = v; this.writes++; }
    get data() { return this.raw; }
}

class ShimElement extends ShimNode {
    constructor(doc, tag, ns) {
        super(doc);
        this.tagName = tag.toUpperCase();
        this.namespaceURI = ns || 'http://www.w3.org/1999/xhtml';
        this.attributes = {};
        this.attrWrites = 0;
        this.valueWrites = 0;
        this.classOps = [];
        this.classList = indexable(new ClassList(this));
        this.style = new Style();
        this.listeners = {};
        this.storedValue = '';
        this.checked = false;
        this.selectionStart = 0;
        this.selectionEnd = 0;
    }
    get value() { return this.storedValue; }
    set value(v) { this.storedValue = String(v); this.valueWrites++; }
    get type() { return this.attributes.type || 'text'; }
    setAttribute(n, v) { this.attributes[n] = String(v); this.attrWrites++; }
    getAttribute(n) { return n in this.attributes ? this.attributes[n] : null; }
    removeAttribute(n) { delete this.attributes[n]; }
    addEventListener(e, fn) { (this.listeners[e] = this.listeners[e] || []).push(fn); }
    removeEventListener(e, fn) {
        const bag = this.listeners[e];
        if (!bag) return;
        const at = bag.indexOf(fn);
        if (at >= 0) bag.splice(at, 1);
    }
    focus() { this.ownerDocument.activeElement = this; }
    setSelectionRange(s, e) { this.selectionStart = s; this.selectionEnd = e; }
}

function makeDocument() {
    const doc = {
        createElement: (tag) => new ShimElement(doc, tag),
        createElementNS: (ns, tag) => new ShimElement(doc, tag, ns),
        createTextNode: (t) => new ShimText(doc, t),
    };
    doc.body = new ShimElement(doc, 'body');
    doc.activeElement = doc.body;
    return doc;
}

/* ------------------------------------------------------------------ *
 * suites
 * ------------------------------------------------------------------ */

async function checkStore({ createStore }) {
    section('core/store.js — change detection');

    {
        const store = createStore({ player: { coins: 10, handle: 'a' }, seasons: [] });
        let woke = 0;
        store.subscribe('*', () => woke++);
        const changed = store.patch({ player: { coins: 10, handle: 'a' }, seasons: [] });
        await nextMicrotask();
        ok('a poll returning identical state wakes nobody', changed === false && woke === 0, { changed, woke });
    }

    {
        const store = createStore({ player: { coins: 10, handle: 'a' } });
        const woke = [];
        store.subscribe('player.coins', () => woke.push('coins'));
        store.subscribe('player.handle', () => woke.push('handle'));
        store.subscribe('player', () => woke.push('player'));
        store.patch({ player: { coins: 11, handle: 'a' } });
        await nextMicrotask();
        ok('only the field that moved wakes, plus its ancestor',
            woke.includes('coins') && woke.includes('player') && !woke.includes('handle'), woke);
    }

    {
        const store = createStore({ player: { coins: 10 } });
        let seen = null;
        store.subscribe('player.coins', (next, prev) => { seen = [next, prev]; });
        store.patch({ player: { coins: 42 } });
        await nextMicrotask();
        ok('a subscriber receives both new and previous values', seen && seen[0] === 42 && seen[1] === 10, seen);
    }

    {
        const store = createStore({ a: 1, b: 1, c: 1 });
        let wakes = 0;
        store.subscribe('*', () => wakes++);
        store.set('a', 2); store.set('b', 2); store.set('c', 2);
        await nextMicrotask();
        ok('writes within one turn batch into a single notification', wakes === 1, { wakes });
    }

    {
        const store = createStore({ player: { coins: 1 }, seasons: [{ id: 1 }] });
        const before = store.get('seasons');
        store.set('player.coins', 2);
        await nextMicrotask();
        ok('an untouched branch keeps reference identity', store.get('seasons') === before);
    }

    {
        const store = createStore({ list: [1, 2, 3] });
        const woke = [];
        store.subscribe('list', () => woke.push('list'));
        store.subscribe('list.1', () => woke.push('list.1'));
        store.set('list', [1, 9, 3]);
        await nextMicrotask();
        ok('an array element change wakes its index watcher', woke.includes('list.1'), woke);
    }

    {
        const store = createStore({ list: [1, 2, 3] });
        let woke = 0;
        store.subscribe('list', () => woke++);
        store.set('list', [1, 2]);
        await nextMicrotask();
        ok('an array length change wakes', woke === 1, { woke });
    }

    // Login and logout are the transitions most likely to be missed, because
    // the whole player object is replaced rather than edited.
    {
        const store = createStore({ player: null });
        let woke = 0;
        store.subscribe('player.coins', () => woke++);
        store.patch({ player: { coins: 5 } });
        await nextMicrotask();
        ok('null -> object wakes deep watchers (login)', woke === 1, { woke });
    }

    {
        const store = createStore({ player: { coins: 5 } });
        let woke = 0;
        store.subscribe('player.coins', () => woke++);
        store.patch({ player: null });
        await nextMicrotask();
        ok('object -> null wakes deep watchers (logout)', woke === 1, { woke });
    }

    {
        const store = createStore({ x: 1 });
        let reached = false;
        const realError = console.error;
        console.error = () => {};
        store.subscribe('x', () => { throw new Error('boom'); });
        store.subscribe('x', () => { reached = true; });
        store.set('x', 2);
        await nextMicrotask();
        console.error = realError;
        ok('one throwing subscriber does not block the others', reached);
    }

    {
        const store = createStore({ x: 1 });
        let woke = 0;
        const off = store.subscribe('x', () => woke++);
        off();
        store.set('x', 2);
        await nextMicrotask();
        ok('unsubscribe detaches', woke === 0, { woke });
    }
}

async function checkRender({ h, render }) {
    section('core/render.js — what must not be touched');

    const mount = () => {
        const doc = makeDocument();
        const root = doc.createElement('div');
        doc.body.appendChild(root);
        return { doc, root, opts: { document: doc } };
    };

    {
        const { doc, root, opts } = mount();
        const tree = () => h('div', { class: 'card' }, h('span', { class: 'v' }, '100'));
        render(tree(), root, opts);
        const el = root.childNodes[0];
        const text = el.childNodes[0].childNodes[0];
        const writes = text.writes;
        const attrs = el.attrWrites;
        const classOps = el.classOps.length;

        render(tree(), root, opts);

        ok('an identical re-render keeps the same element', root.childNodes[0] === el);
        ok('an identical re-render does not rewrite text', text.writes === writes, { before: writes, after: text.writes });
        ok('an identical re-render does not rewrite attributes', el.attrWrites === attrs);
        ok('an identical re-render does not touch classList', el.classOps.length === classOps, el.classOps);
        void doc;
    }

    {
        const { root, opts } = mount();
        render(h('div', { class: 'coin pulse' }), root, opts);
        const el = root.childNodes[0];
        el.classOps.length = 0;
        render(h('div', { class: 'coin pulse gold' }), root, opts);
        ok('adding a class leaves the running-animation classes alone',
            el.classOps.length === 1 && el.classOps[0] === '+gold', el.classOps);
    }

    {
        const { doc, root, opts } = mount();
        render(h('input', { type: 'text', value: 'ser' }), root, opts);
        const input = root.childNodes[0];
        input.focus();
        input.storedValue = 'serv';       // the player typed a character
        input.setSelectionRange(4, 4);
        const writes = input.valueWrites;

        render(h('input', { type: 'text', value: 'ser' }), root, opts); // a stale poll lands

        ok('a stale poll does not overwrite a focused field',
            input.value === 'serv' && input.valueWrites === writes, { value: input.value });
        ok('the caret does not jump', input.selectionStart === 4 && input.selectionEnd === 4);
        void doc;
    }

    {
        const { root, opts } = mount();
        render(h('input', { type: 'text', value: 'a' }), root, opts);
        const input = root.childNodes[0];
        render(h('input', { type: 'text', value: 'b' }), root, opts);
        ok('an unfocused field does accept the server value', input.value === 'b', input.value);
    }

    {
        const { root, opts } = mount();
        const list = (ids) => h('ul', null, ids.map(id => h('li', { key: id }, 'row' + id)));
        render(list([1, 2, 3]), root, opts);
        const ul = root.childNodes[0];
        const [a, b, c] = ul.childNodes;
        render(list([0, 1, 2, 3]), root, opts);
        ok('a keyed insert leaves existing rows untouched',
            ul.childNodes[1] === a && ul.childNodes[2] === b && ul.childNodes[3] === c,
            ul.childNodes.map(n => n.id));
    }

    {
        const { root, opts } = mount();
        const list = (ids) => h('ul', null, ids.map(id => h('li', { key: id }, 'row' + id)));
        render(list([1, 2, 3]), root, opts);
        const ul = root.childNodes[0];
        const original = ul.childNodes.slice();
        render(list([3, 1, 2]), root, opts);
        ok('a keyed reorder moves rows rather than rebuilding them',
            ul.childNodes[0] === original[2] && ul.childNodes[1] === original[0] && ul.childNodes[2] === original[1],
            ul.childNodes.map(n => n.id));
    }

    // The scenario this whole module exists for: a message arrives while the
    // player is halfway through typing one.
    {
        const { doc, root, opts } = mount();
        const view = (ids) => h('div', null,
            h('input', { key: 'chat', type: 'text', value: '' }),
            h('ul', null, ids.map(id => h('li', { key: id }, 'm' + id))),
        );
        render(view([1, 2]), root, opts);
        const input = root.childNodes[0].childNodes[0];
        input.focus();
        input.storedValue = 'half typed';
        input.setSelectionRange(4, 4);

        render(view([1, 2, 3]), root, opts);

        ok('focus survives a sibling list growing', doc.activeElement === input);
        ok('the draft survives a sibling list growing', input.value === 'half typed', input.value);
        ok('the caret survives a sibling list growing', input.selectionStart === 4);
    }

    {
        const { root, opts } = mount();
        render(h('div', null, 'x'), root, opts);
        const before = root.childNodes[0];
        render(h('section', null, 'x'), root, opts);
        ok('a genuine tag change does replace the node',
            root.childNodes[0] !== before && root.childNodes[0].tagName === 'SECTION');
    }

    {
        const { root, opts } = mount();
        render(h('div', { 'data-v': '1', title: 't' }), root, opts);
        const el = root.childNodes[0];
        const writes = el.attrWrites;
        render(h('div', { 'data-v': '1', title: 't' }), root, opts);
        ok('an unchanged attribute is not rewritten', el.attrWrites === writes);
        render(h('div', { 'data-v': '2', title: 't' }), root, opts);
        ok('a changed attribute is written exactly once',
            el.attrWrites === writes + 1 && el.getAttribute('data-v') === '2');
        render(h('div', { title: 't' }), root, opts);
        ok('a dropped attribute is removed', el.getAttribute('data-v') === null);
    }

    {
        const { root, opts } = mount();
        render(h('div', { style: { marginTop: '4px', '--glow': '1' } }), root, opts);
        const el = root.childNodes[0];
        ok('camelCase style names become kebab-case', el.style.props['margin-top'] === '4px', el.style.props);
        ok('custom properties pass through verbatim', el.style.props['--glow'] === '1', el.style.props);
        render(h('div', { style: { marginTop: '4px' } }), root, opts);
        ok('a dropped style property is cleared', !('--glow' in el.style.props));
    }

    {
        const { root, opts } = mount();
        const fn = () => {};
        render(h('button', { onClick: fn }), root, opts);
        const el = root.childNodes[0];
        render(h('button', { onClick: fn }), root, opts);
        ok('an unchanged listener is not rebound', el.listeners.click.length === 1, el.listeners.click.length);
        render(h('button', {}), root, opts);
        ok('a dropped listener is removed', el.listeners.click.length === 0);
    }

    {
        const { root, opts } = mount();
        render(h('div', null, false, null, undefined, 'kept', 0), root, opts);
        const el = root.childNodes[0];
        ok('falsy children are skipped but zero is kept',
            el.childNodes.length === 2 && el.childNodes[0].data === 'kept' && el.childNodes[1].data === '0',
            el.childNodes.map(c => c.data));
    }

    {
        const { root, opts } = mount();
        const view = (ids) => h('div', null, h('h2', null, 'Header'), ...ids.map(id => h('p', { key: id }, 'p' + id)));
        render(view([1, 2]), root, opts);
        const el = root.childNodes[0];
        const header = el.childNodes[0];
        const first = el.childNodes[1];
        render(view([0, 1, 2]), root, opts);
        ok('an unkeyed header keeps identity beside keyed siblings', el.childNodes[0] === header);
        ok('keyed siblings keep identity across an insert', el.childNodes[2] === first, el.childNodes.map(n => n.id));
    }
}

function makeClockHarness(createClock, startAt = 1_000_000) {
    let time = startAt;
    let queue = [];
    const clock = createClock({
        now: () => time,
        raf: (cb) => { queue.push(cb); return queue.length; },
        cancelRaf: () => {},
        doc: null,
    });
    return {
        clock,
        at: () => time,
        advance(ms, step = 16) {
            const target = time + ms;
            while (time < target) {
                time = Math.min(target, time + step);
                const due = queue;
                queue = [];
                for (const cb of due) cb(time);
            }
        },
        jumpTo(v) { time = v; },
        drain() { const due = queue; queue = []; for (const cb of due) cb(time); },
    };
}

function checkClock({ createClock }) {
    section('core/clock.js — scheduling and tick phase');

    {
        const { clock, advance } = makeClockHarness(createClock);
        let n = 0;
        clock.every(1000, () => n++);
        advance(3500);
        ok('every() fires once per period', n === 3, { n });
    }

    {
        const { clock, advance } = makeClockHarness(createClock);
        const times = [];
        clock.every(1000, (t) => times.push(t));
        advance(10_000, 37); // deliberately ragged frame cadence
        const gaps = times.slice(1).map((v, i) => v - times[i]);
        ok('absolute deadlines mean drift does not accumulate',
            times.length === 10 && !gaps.some(g => Math.abs(g - 1000) > 40), { gaps });
    }

    {
        const { clock, advance, jumpTo, drain } = makeClockHarness(createClock);
        let n = 0;
        clock.every(1000, () => n++);
        advance(100);
        jumpTo(1_000_000 + 60_000); // the tab was hidden for a minute
        drain();
        ok('returning from a long pause replays one run, not a backlog', n === 1, { n });
    }

    // The honesty property: without last_tick_at the client knows the period
    // but not the phase, and must say so rather than count down to the wrong
    // moment.
    {
        const { clock } = makeClockHarness(createClock);
        ok('phase is unknown before it is published',
            clock.hasTickPhase() === false && clock.secondsToNextTick() === null && clock.tickProgress() === null);
    }

    {
        const { clock } = makeClockHarness(createClock);
        clock.setTickPhase({ periodSeconds: 10, lastTickAt: null });
        ok('knowing the period alone does not manufacture a countdown',
            clock.hasTickPhase() === false && clock.secondsToNextTick() === null);
    }

    {
        const { clock, at } = makeClockHarness(createClock);
        clock.setTickPhase({ periodSeconds: 10, lastTickAt: at() - 3000 });
        ok('with phase known the countdown is real',
            clock.hasTickPhase() === true
            && Math.abs(clock.secondsToNextTick() - 7) < 0.001
            && Math.abs(clock.tickProgress() - 0.3) < 0.001,
            { seconds: clock.secondsToNextTick(), progress: clock.tickProgress() });
    }

    {
        const { clock, advance, at } = makeClockHarness(createClock);
        let ticks = 0;
        clock.setTickPhase({ periodSeconds: 10, lastTickAt: at() });
        clock.onTick(() => ticks++);
        advance(25_000);
        ok('onTick fires once per boundary crossed', ticks === 2, { ticks });
    }

    {
        const { clock, advance } = makeClockHarness(createClock);
        let ticks = 0;
        clock.onTick(() => ticks++);
        advance(30_000);
        ok('onTick stays silent while phase is unknown', ticks === 0, { ticks });
    }

    {
        const { clock, advance } = makeClockHarness(createClock);
        let good = 0;
        const realError = console.error;
        console.error = () => {};
        clock.every(100, () => { throw new Error('boom'); });
        clock.every(100, () => good++);
        advance(350);
        console.error = realError;
        ok('a throwing interval does not stop the others', good === 3, { good });
    }
}

function checkMotion({ createMotion }) {
    section('core/motion.js — reduced motion removes travel, not outcome');

    const stubWindow = (reduced) => ({
        matchMedia: () => ({ matches: reduced, addEventListener() {}, addListener() {} }),
        performance: { now: () => 0 },
        requestAnimationFrame: (cb) => { cb(0); return 1; },
    });

    {
        const motion = createMotion({ win: stubWindow(false), doc: null });
        ok('durations fall back to the token defaults without a document',
            motion.durationMs('micro') === 150 && motion.durationMs('move') === 300
            && motion.durationMs('moment') === 600 && motion.durationMs('ceremony') === 2400,
            { micro: motion.durationMs('micro'), move: motion.durationMs('move') });
    }

    {
        const motion = createMotion({ win: stubWindow(true), doc: null });
        ok('reduced motion collapses every duration to zero',
            motion.reduced === true && motion.durationMs('ceremony') === 0);
        ok('reduced motion linearises easing', motion.easing('gain') === 'linear');
    }

    {
        const motion = createMotion({ win: stubWindow(true), doc: null });
        const seen = [];
        motion.countTo(0, 500, (v, done) => seen.push([v, done]));
        ok('under reduced motion a counter still reaches its value, immediately',
            seen.length === 1 && seen[0][0] === 500 && seen[0][1] === true, seen);
    }

    {
        const motion = createMotion({ win: stubWindow(false), doc: null });
        const seen = [];
        motion.countTo(10, 10, (v, done) => seen.push([v, done]));
        ok('counting to an unchanged value settles at once', seen.length === 1 && seen[0][0] === 10, seen);
    }
}

/* ------------------------------------------------------------------ *
 * run
 * ------------------------------------------------------------------ */

const { modules, cleanup } = await loadCore();
try {
    await checkStore(modules.store);
    await checkRender(modules.render);
    checkClock(modules.clock);
    checkMotion(modules.motion);
} finally {
    await cleanup();
}

console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
