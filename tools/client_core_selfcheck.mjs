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

import { mkdtemp, copyFile, rm, writeFile, mkdir } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const CORE = join(HERE, '..', 'public', 'js', 'core');

/* ------------------------------------------------------------------ *
 * module loading
 *
 * The client modules are ES modules with a .js extension, and this repo has no
 * package.json declaring {"type":"module"} — deliberately, since adding one
 * risks changing how the deployment image is detected and built.
 *
 * So the tree is copied to a temp directory that has its own package.json
 * saying exactly that. Filenames are preserved rather than renamed to .mjs,
 * because screens import each other by relative specifier ('./ui.js') and
 * renaming would break every one of them.
 * ------------------------------------------------------------------ */

const SCREENS = join(HERE, '..', 'public', 'js', 'screens');

async function loadCore() {
    const dir = await mkdtemp(join(tmpdir(), 'tmc-core-'));

    // A package.json declaring module type, written into the temp dir rather
    // than the repo. That keeps the .js filenames intact, which matters
    // because screens import each other by relative path ('./ui.js') and
    // renaming to .mjs would break those specifiers.
    await writeFile(join(dir, 'package.json'), JSON.stringify({ type: 'module' }));

    const coreNames = ['store', 'api', 'render', 'clock', 'motion', 'assets'];
    const core = {};
    for (const name of coreNames) {
        const dest = join(dir, `${name}.js`);
        await copyFile(join(CORE, `${name}.js`), dest);
        core[name] = await import(pathToFileURL(dest).href);
    }

    await mkdir(join(dir, 'screens'), { recursive: true });
    const screenNames = ['ui', 'home', 'seasons', 'season', 'ranks', 'chat', 'shop', 'auth', 'staff', 'index'];
    const screens = {};
    for (const name of screenNames) {
        await copyFile(join(SCREENS, `${name}.js`), join(dir, 'screens', `${name}.js`));
    }
    for (const name of screenNames) {
        screens[name] = await import(pathToFileURL(join(dir, 'screens', `${name}.js`)).href);
    }

    return { modules: core, screens, cleanup: () => rm(dir, { recursive: true, force: true }) };
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

    // Regression: a loading placeholder becoming real content must not destroy
    // its siblings. Found by a real-browser probe, not by reading the code —
    // the draft text survived (it is mirrored in the store) while the input
    // node itself was silently rebuilt and focus was lost.
    {
        const { doc, root, opts } = mount();
        const view = (loaded) => h('div', null,
            h('div', { class: 'tabs' }, 'tabs'),
            loaded ? h('ul', null, h('li', null, 'a')) : h('div', { class: 'pending' }, 'Loading…'),
            h('form', null, h('input', { type: 'text' })),
        );

        render(view(false), root, opts);
        const form = root.childNodes[0].childNodes[2];
        const input = form.childNodes[0];
        input.focus();
        input.storedValue = 'half typed';
        input.setSelectionRange(4, 4);

        render(view(true), root, opts);

        const formAfter = root.childNodes[0].childNodes[2];
        ok('a placeholder becoming content does not rebuild later siblings',
            formAfter === form && formAfter.childNodes[0] === input,
            { sameForm: formAfter === form });
        ok('...so the draft, caret and focus all survive it',
            input.value === 'half typed' && input.selectionStart === 4 && doc.activeElement === input,
            { value: input.value, caret: input.selectionStart, focused: doc.activeElement === input });
        ok('...and the placeholder itself is gone',
            root.childNodes[0].childNodes[1].tagName === 'UL',
            root.childNodes[0].childNodes[1].tagName);
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

function checkAssets({ createAssets }, { h }) {
    section('core/assets.js — placeholders now, art later, same call sites');

    const reducedMotion = { reduced: true };
    const fullMotion = { reduced: false };

    const slots = {
        'plain': { placeholder: '★', art: null },
        'tinted': { placeholder: '◈', tint: 'var(--family-ward)', art: null },
        'done': { placeholder: '★', art: { kind: 'image', src: '/a.png', w: 20, h: 20 } },
        'retina': { placeholder: '★', art: { kind: 'image', src: '/a.png', src2x: '/a@2x.png', w: 20, h: 20 } },
        'mask': { placeholder: '◈', tint: 'var(--family-ward)', art: { kind: 'image', src: '/w.png', w: 28, h: 28, tintable: true } },
        'burst': { placeholder: null, art: { kind: 'sprite', src: '/b.png', w: 96, h: 96, frames: 12, fps: 24 } },
    };

    const assets = createAssets({ h, motion: fullMotion, doc: null, slots });

    {
        const v = assets.icon('plain');
        ok('an unfilled slot renders its placeholder',
            v.children[0].text === '★' && v.props.class.includes('icon-placeholder'), v.props.class);
    }

    {
        const v = assets.icon('tinted');
        ok('a placeholder still carries its family tint',
            v.props.style.color === 'var(--family-ward)', v.props.style);
    }

    {
        ok('hasArt distinguishes filled from unfilled',
            assets.hasArt('plain') === false && assets.hasArt('done') === true);
    }

    // The property that makes the swap free: the call site is identical, so
    // only the shape of what comes back changes.
    {
        const before = assets.icon('plain');
        const after = assets.icon('done');
        ok('filling a slot changes the output, not the call',
            before.props.class.includes('icon-placeholder')
            && !after.props.class.includes('icon-placeholder')
            && after.props.style.backgroundImage === 'url("/a.png")',
            after.props.style);
    }

    {
        const v = assets.icon('retina');
        ok('a 2x source becomes an image-set',
            v.props.style.backgroundImage === 'image-set(url("/a.png") 1x, url("/a@2x.png") 2x)',
            v.props.style.backgroundImage);
    }

    // Tintable art must be masked, never drawn — otherwise the colour token
    // cannot reach it and the four themes lose the family palette.
    {
        const v = assets.icon('mask');
        ok('tintable art is masked and coloured, not drawn',
            v.props.style.maskImage === 'url("/w.png")'
            && v.props.style.backgroundColor === 'var(--family-ward)'
            && v.props.style.backgroundImage === undefined,
            v.props.style);
        ok('tintable art also sets the -webkit- mask for Safari',
            v.props.style.webkitMaskImage === 'url("/w.png")');
    }

    {
        const v = assets.icon('burst');
        ok('a sprite slot sizes the background to the whole strip',
            v.props.style.backgroundSize === '1152px 100%', v.props.style.backgroundSize);
    }

    {
        const bare = assets.icon('plain');
        const labelled = assets.icon('plain', { label: 'Coins' });
        ok('icons are aria-hidden by default and labelled on request',
            bare.props['aria-hidden'] === 'true'
            && labelled.props.role === 'img' && labelled.props['aria-label'] === 'Coins');
    }

    {
        const size = assets.icon('done', { size: 40 });
        ok('an explicit size scales both axes',
            size.props.style.width === '40px' && size.props.style.height === '40px', size.props.style);
    }

    {
        const realWarn = console.warn;
        let warned = false;
        console.warn = () => { warned = true; };
        const v = assets.icon('nope');
        console.warn = realWarn;
        ok('an unknown slot warns rather than throwing', warned && v.children.length === 0);
    }

    // playSprite has to be safe to call unconditionally, so screens written
    // now do not need rewriting when art lands.
    {
        const el = { style: { setProperty() {} }, classList: { add() {}, remove() {} }, addEventListener() {}, removeEventListener() {} };
        let resolved = false;
        assets.playSprite(el, 'plain').then(() => { resolved = true; });
        ok('playing a slot with no sprite is a safe no-op', resolved === false || resolved === true);
    }

    {
        const reducedAssets = createAssets({ h, motion: reducedMotion, doc: null, slots });
        let positionSet = null;
        const el = { style: { backgroundPosition: null, setProperty() {} }, classList: { add() {}, remove() {} }, addEventListener() {}, removeEventListener() {} };
        Object.defineProperty(el.style, 'backgroundPosition', {
            set(v) { positionSet = v; }, get() { return positionSet; },
        });
        reducedAssets.playSprite(el, 'burst');
        ok('reduced motion holds the rest frame instead of playing', positionSet === '0 0', positionSet);
    }
}

function checkScreens(screens, { h, render }) {
    section('screens — every screen renders in every state it can be in');

    // A screen's view() must be pure and total: it is called on every render
    // pass, including before its enter() has fetched anything and while the
    // player is logged out. Those are the states most likely to be forgotten,
    // and a throw there takes the whole deck down.
    const stubCtx = (overrides = {}) => {
        const state = {
            player: null,
            seasons: [],
            screens: {},
            ui: {},
            ...overrides,
        };
        return {
            h,
            navigate() {},
            joinSeason() {}, loadLeaderboard() {}, loadChat() {},
            switchChat() {}, sendChat() {}, loadShop() {},
            buyCosmetic() {}, equipCosmetic() {},
            // Mirrors main.js: null/undefined = server not gating.
            unlocked(key) {
                const u = state.unlocks;
                return u === null || u === undefined || u.includes(key);
            },
            store: {
                get(path) {
                    if (!path) return state;
                    let node = state;
                    for (const seg of path.split('.')) {
                        if (node === null || node === undefined) return undefined;
                        node = node[seg];
                    }
                    return node;
                },
                set() {},
            },
        };
    };

    const PLAYER = {
        player_id: 7, handle: 'tester', coins: 1234, seasonal_stars: 12,
        sigils: 3, global_stars: 99, ubi_rate: 4.5, joined_season_id: null, role: 'player',
    };

    const SEASON = {
        season_id: 1, name: 'Season 1', computed_status: 'Active',
        seconds_remaining: 90000, current_star_price: 213, participant_count: 12,
    };

    const cases = [
        ['logged out', {}],
        ['logged in, no season', { player: PLAYER }],
        ['logged in, in a season', { player: { ...PLAYER, joined_season_id: 1 }, seasons: [SEASON] }],
        ['data loaded', {
            player: PLAYER,
            seasons: [SEASON],
            screens: {
                ranks: { entries: [{ player_id: 7, handle: 'tester', global_stars_lifetime: 99, activity_state: 'Active', online_current: 1 }], page: 1 },
                chat: { messages: [{ message_id: 1, handle_snapshot: 'a', content: 'hi', created_at: '2026-07-29T10:00:00Z' }], channel: 'GLOBAL' },
                shop: { catalog: [{ cosmetic_id: 1, name: 'Frame', category: 'avatar_frame', price_global_stars: 50 }], owned: [], equipped: {} },
            },
        }],
        ['empty results', {
            player: PLAYER,
            screens: { ranks: { entries: [], page: 1 }, chat: { messages: [] }, shop: { catalog: [], owned: [], equipped: {} } },
        }],
    ];

    for (const name of ['home', 'seasons', 'season', 'ranks', 'chat', 'shop', 'auth', 'staff']) {
        const screen = screens[name].default;
        let failedAt = null;
        for (const [label, overrides] of cases) {
            try {
                const out = screen.view(stubCtx(overrides));
                if (out === undefined) { failedAt = `${label} (returned undefined)`; break; }
            } catch (err) {
                failedAt = `${label}: ${err.message}`;
                break;
            }
        }
        ok(`${name} renders in all ${cases.length} states`, failedAt === null, failedAt);
    }

    // Screens must actually mount into a DOM, not merely build vnodes.
    {
        const doc = makeDocument();
        const root = doc.createElement('div');
        doc.body.appendChild(root);
        let threw = null;
        try {
            render(screens.home.default.view(stubCtx({ player: PLAYER })), root, { document: doc });
        } catch (err) {
            threw = err.message;
        }
        ok('a screen mounts through the reconciler', threw === null && root.childNodes.length === 1, threw);
    }

    {
        const ids = ['home', 'seasons', 'ranks', 'chat', 'shop'];
        const missing = ids.filter(id => !screens.index.getScreen(id));
        ok('every rail id resolves to a registered screen', missing.length === 0, missing);
        ok('an unknown id resolves to null', screens.index.getScreen('nope') === null);
        ok('season is registered but deliberately off the rail',
            Boolean(screens.index.getScreen('season')) && !screens.index.RAIL_IDS.includes('season'));
        ok('season lights up Seasons on the rail', screens.index.RAIL_PARENT.season === 'seasons');
    }

    // The auth forms are uncontrolled on purpose: the DOM owns the values and
    // they are read on submit. The property worth locking in is that no input
    // carries a `value` prop — the moment one does, the field is mirrored into
    // application state, and for the password fields that means a password
    // sitting in the store where a debug dump or an error report can reach it.
    {
        const findInputs = (node, out = []) => {
            if (!node || typeof node !== 'object') return out;
            if (Array.isArray(node)) { node.forEach(n => findInputs(n, out)); return out; }
            if (node.tag === 'input') out.push(node);
            (node.children || []).forEach(c => findInputs(c, out));
            return out;
        };

        const auth = screens.auth.default;

        for (const tab of ['login', 'register']) {
            const tree = auth.view(stubCtx({ ui: { authTab: tab } }));
            const inputs = findInputs(tree);
            const withValue = inputs.filter(i => i.props && 'value' in i.props);
            ok(`${tab} form has inputs and none of them are value-bound`,
                inputs.length > 0 && withValue.length === 0,
                { inputs: inputs.length, valueBound: withValue.length });
            ok(`${tab} form keys every input so the poll cannot rebuild them`,
                inputs.every(i => i.key !== null), inputs.map(i => i.key));
        }

        const pwd = findInputs(auth.view(stubCtx({ ui: { authTab: 'register' } })))
            .find(i => i.props && i.props.type === 'password');
        ok('the password field exists and is typed as a password',
            Boolean(pwd) && pwd.props.autocomplete === 'new-password', pwd && pwd.props);

        // Signed in, the screen must not offer a login form again.
        const signedIn = auth.view(stubCtx({ player: PLAYER }));
        ok('auth shows a signed-in state rather than a form when logged in',
            findInputs(signedIn).length === 0);
    }

    // game_state carries the player and seasons but not the chat transcript, so
    // chat needs a poll of its own. Without it the room looks frozen at
    // whatever was there when you walked in — which is exactly how it shipped
    // the first time, and was only caught by driving a real browser.
    {
        let started = 0, stopped = 0, loads = 0;
        const ctx = {
            ...stubCtx({ player: PLAYER }),
            loadChat() { loads++; },
            clock: { every() { started++; return () => { stopped++; }; } },
        };
        const chat = screens.chat.default;

        chat.enter(ctx);
        ok('chat starts its own poll on entry', started === 1 && loads === 1, { started, loads });

        chat.leave(ctx);
        ok('chat stops polling when you leave it', stopped === 1, { stopped });

        // Leaving twice must not double-stop; activateScreen can call leave on
        // a screen that never entered.
        chat.leave(ctx);
        ok('leaving twice is harmless', stopped === 1, { stopped });
    }

    // Progression gates: an undiscovered feature is absent - not zeroed, not
    // teased. unlocks null means the server is not gating (flag off), and
    // everything renders exactly as before the gates existed.
    {
        const allText = (node, out = []) => {
            if (!node || typeof node !== 'object') return out;
            if (Array.isArray(node)) { node.forEach(n => allText(n, out)); return out; }
            if (node.text !== null && node.text !== undefined) out.push(String(node.text));
            (node.children || []).forEach(c => allText(c, out));
            return out;
        };
        const inSeason = { player: { ...PLAYER, joined_season_id: 1 }, seasons: [SEASON] };

        const ungated = allText(screens.home.default.view(stubCtx({ ...inSeason }))).join(' ');
        ok('home shows sigils when the server is not gating', ungated.includes('Sigils'));

        const gated = allText(screens.home.default.view(stubCtx({ ...inSeason, unlocks: [] }))).join(' ');
        ok('home hides sigils until they are discovered', !gated.includes('Sigils'));

        const discovered = allText(screens.home.default.view(stubCtx({ ...inSeason, unlocks: ['sigils.ui'] }))).join(' ');
        ok('home shows sigils once discovered', discovered.includes('Sigils'));
    }

    // Staff screen: locked for everyone below Moderator; the server-mode
    // panel is admin-only on top of that.
    {
        const allText = (node, out = []) => {
            if (!node || typeof node !== 'object') return out;
            if (Array.isArray(node)) { node.forEach(n => allText(n, out)); return out; }
            if (node.text !== null && node.text !== undefined) out.push(String(node.text));
            (node.children || []).forEach(c => allText(c, out));
            return out;
        };
        const staff = screens.staff.default;

        const asPlayer = allText(staff.view(stubCtx({ player: PLAYER }))).join(' ');
        ok('staff screen locks out non-staff', asPlayer.includes('Staff only'));

        const asMod = allText(staff.view(stubCtx({ player: { ...PLAYER, role: 'Moderator' } }))).join(' ');
        ok('moderators get the tools but not the server-mode panel',
            asMod.includes('Find player') && !asMod.includes('Server mode'));

        const asAdmin = allText(staff.view(stubCtx({ player: { ...PLAYER, role: 'Admin' } }))).join(' ');
        ok('admins get the server-mode panel', asAdmin.includes('Server mode'));
    }
}

/* ------------------------------------------------------------------ *
 * run
 * ------------------------------------------------------------------ */

const { modules, screens, cleanup } = await loadCore();
try {
    await checkStore(modules.store);
    await checkRender(modules.render);
    checkClock(modules.clock);
    checkMotion(modules.motion);
    checkAssets(modules.assets, modules.render);
    checkScreens(screens, modules.render);
} finally {
    await cleanup();
}

console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
