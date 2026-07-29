/**
 * core/render.js — surgical DOM reconciler
 *
 * The problem this exists to solve: the legacy client rebuilds screens with
 * `innerHTML = ...` on a 3s poll. Every rebuild discards the DOM nodes and
 * takes four things with them —
 *
 *   1. focus, so a half-typed chat message or trade amount loses its field
 *   2. selection, so the caret jumps to the end mid-word
 *   3. scroll position inside any rebuilt container
 *   4. running CSS animations, which restart from frame zero
 *
 * The workarounds already in app.js (updateSeasonDetailLive, and the
 * hand-written reconcileSigilForge) exist for exactly this reason: they are
 * partial updates written by hand so that one particular screen would stop
 * eating input. This module generalises that so it does not have to be
 * rewritten per screen.
 *
 * The approach is a small keyed virtual DOM. Nodes are mutated in place
 * whenever tag and key match, which preserves all four things above for free,
 * because the DOM node is never discarded. Everything else here is either
 * diffing detail or a guard for a case where in-place mutation still visibly
 * disturbs the user.
 */

const SVG_NS = 'http://www.w3.org/2000/svg';
const LISTENERS = Symbol('listeners');
const VNODE = Symbol('vnode');

/* ------------------------------------------------------------------ *
 * vnodes
 * ------------------------------------------------------------------ */

function textNode(value) {
    return { tag: null, key: null, props: null, children: null, text: String(value) };
}

function normalizeChildren(children, out = []) {
    for (const child of children) {
        if (child === null || child === undefined || child === false || child === true) continue;
        if (Array.isArray(child)) {
            normalizeChildren(child, out);
            continue;
        }
        if (typeof child === 'object' && ('tag' in child)) {
            out.push(child);
            continue;
        }
        out.push(textNode(child));
    }
    return out;
}

/**
 * Create a vnode.
 *
 *   h('div', {class: 'card'}, h('span', null, coins))
 *
 * `props.key` identifies a node across renders. Key your lists: without a key
 * a list is matched positionally, so inserting at the top shifts every node
 * down by one and each one is then mutated into its neighbour's content —
 * correct output, but every animation restarts and focus lands on the wrong
 * row. With a key, the new row is inserted and nothing else is touched.
 */
export function h(tag, props, ...children) {
    const key = props && props.key !== undefined ? String(props.key) : null;
    return { tag, key, props: props || null, children: normalizeChildren(children), text: null };
}

/* ------------------------------------------------------------------ *
 * focus and selection
 * ------------------------------------------------------------------ */

const TEXT_ENTRY = new Set(['text', 'search', 'url', 'tel', 'password', 'email', 'number']);

function isTextEntry(el) {
    if (!el) return false;
    if (el.tagName === 'TEXTAREA') return true;
    if (el.tagName !== 'INPUT') return false;
    return TEXT_ENTRY.has((el.type || 'text').toLowerCase());
}

function captureFocus(doc) {
    const el = doc && doc.activeElement;
    if (!el || el === doc.body) return null;
    const snapshot = { el, start: null, end: null };
    if (isTextEntry(el)) {
        try {
            snapshot.start = el.selectionStart;
            snapshot.end = el.selectionEnd;
        } catch {
            // Some input types throw on selection access. Focus alone is still
            // worth restoring.
        }
    }
    return snapshot;
}

function restoreFocus(doc, snapshot) {
    if (!snapshot || !snapshot.el) return;
    const { el, start, end } = snapshot;
    if (doc.activeElement === el) return;
    // Only restore if the node survived. If it was genuinely removed from the
    // document, forcing focus back would be worse than letting it go.
    if (!el.isConnected) return;

    try {
        el.focus({ preventScroll: true });
        if (start !== null && end !== null && isTextEntry(el)) {
            el.setSelectionRange(start, end);
        }
    } catch {
        // Non-focusable or detached mid-flight; nothing useful to do.
    }
}

/* ------------------------------------------------------------------ *
 * props
 * ------------------------------------------------------------------ */

function setClass(el, next) {
    const nextTokens = new Set(
        String(next == null ? '' : next).split(/\s+/).filter(Boolean)
    );
    const current = el.classList;

    // Diff token by token rather than assigning className. Assigning it
    // wholesale removes every class and adds them back, which restarts any CSS
    // animation or transition keyed to a class that was present both before
    // and after — the coin-gain pulse would retrigger on every poll.
    for (let i = current.length - 1; i >= 0; i--) {
        const token = current[i];
        if (!nextTokens.has(token)) current.remove(token);
    }
    for (const token of nextTokens) {
        if (!current.contains(token)) current.add(token);
    }
}

function setStyle(el, prev, next) {
    const prevStyle = prev && typeof prev === 'object' ? prev : {};
    const nextStyle = next && typeof next === 'object' ? next : {};

    for (const name of Object.keys(prevStyle)) {
        if (!(name in nextStyle)) el.style.setProperty(cssName(name), '');
    }
    for (const name of Object.keys(nextStyle)) {
        if (prevStyle[name] === nextStyle[name]) continue;
        el.style.setProperty(cssName(name), nextStyle[name]);
    }
}

function cssName(name) {
    // Custom properties pass through untouched; camelCase becomes kebab.
    return name.startsWith('--') ? name : name.replace(/[A-Z]/g, m => '-' + m.toLowerCase());
}

function setListener(el, event, next) {
    if (!el[LISTENERS]) el[LISTENERS] = {};
    const bag = el[LISTENERS];
    const prev = bag[event];
    if (prev === next) return;
    if (prev) el.removeEventListener(event, prev);
    if (next) el.addEventListener(event, next);
    bag[event] = next || undefined;
}

function setValue(el, next, doc) {
    const value = next == null ? '' : String(next);

    // Never overwrite what someone is actively typing. A poll landing between
    // two keystrokes would otherwise replace the field with the server's stale
    // copy and drop the characters in between. The field resyncs the moment it
    // loses focus.
    if (doc && doc.activeElement === el && isTextEntry(el)) return;

    if (el.value !== value) el.value = value;
}

function applyProps(el, prevProps, nextProps, doc, isSvg) {
    const prev = prevProps || {};
    const next = nextProps || {};

    for (const name of Object.keys(prev)) {
        if (name === 'key' || name in next) continue;
        if (name.startsWith('on')) {
            setListener(el, name.slice(2).toLowerCase(), null);
        } else if (name === 'class' || name === 'className') {
            setClass(el, '');
        } else if (name === 'style') {
            setStyle(el, prev.style, null);
        } else {
            el.removeAttribute(name);
        }
    }

    for (const name of Object.keys(next)) {
        if (name === 'key') continue;
        const value = next[name];
        if (prev[name] === value && name !== 'value' && name !== 'checked') continue;

        if (name.startsWith('on')) {
            setListener(el, name.slice(2).toLowerCase(), value);
        } else if (name === 'class' || name === 'className') {
            setClass(el, value);
        } else if (name === 'style') {
            setStyle(el, prev.style, value);
        } else if (name === 'value' && !isSvg) {
            setValue(el, value, doc);
        } else if (name === 'checked' && !isSvg) {
            const checked = Boolean(value);
            if (el.checked !== checked) el.checked = checked;
        } else if (value === false || value === null || value === undefined) {
            el.removeAttribute(name);
        } else if (value === true) {
            if (el.getAttribute(name) !== '') el.setAttribute(name, '');
        } else {
            const str = String(value);
            // Read before write: setAttribute with an identical value still
            // invalidates style in some engines, and on an attribute an
            // animation selects on, that is a visible restart.
            if (el.getAttribute(name) !== str) el.setAttribute(name, str);
        }
    }
}

/* ------------------------------------------------------------------ *
 * create / patch
 * ------------------------------------------------------------------ */

function createNode(vnode, doc, isSvg) {
    if (vnode.tag === null) return doc.createTextNode(vnode.text);

    const svg = isSvg || vnode.tag === 'svg';
    const el = svg ? doc.createElementNS(SVG_NS, vnode.tag) : doc.createElement(vnode.tag);

    applyProps(el, null, vnode.props, doc, svg);

    for (const child of vnode.children) {
        el.appendChild(createNode(child, doc, svg));
    }

    el[VNODE] = vnode;
    return el;
}

function sameType(a, b) {
    return a.tag === b.tag && a.key === b.key;
}

function patchNode(el, prevVnode, nextVnode, doc, isSvg) {
    // Text: touch the data only when it differs. Writing identical text still
    // dirties the node and can drop a text selection inside it.
    if (nextVnode.tag === null) {
        if (el.data !== nextVnode.text) el.data = nextVnode.text;
        el[VNODE] = nextVnode;
        return el;
    }

    const svg = isSvg || nextVnode.tag === 'svg';
    applyProps(el, prevVnode ? prevVnode.props : null, nextVnode.props, doc, svg);
    patchChildren(el, prevVnode ? prevVnode.children : [], nextVnode.children, doc, svg);
    el[VNODE] = nextVnode;
    return el;
}

/**
 * Reconcile a child list.
 *
 * Keyed nodes are matched by key wherever they moved to; unkeyed nodes are
 * matched positionally. The two mix freely, which matters because most lists
 * here are a keyed set of rows with a fixed header or empty-state sibling.
 */
function patchChildren(parent, prevChildren, nextChildren, doc, isSvg) {
    const prev = prevChildren || [];
    const next = nextChildren || [];

    // Index the old keyed nodes so a moved row is found rather than rebuilt.
    const keyed = new Map();
    for (let i = 0; i < prev.length; i++) {
        const k = prev[i].key;
        if (k !== null) keyed.set(k, i);
    }

    const domNodes = Array.from(parent.childNodes);
    const claimed = new Set();
    let cursor = 0; // next unclaimed position for unkeyed matching

    const finalNodes = [];

    for (const nextChild of next) {
        let matchIndex = -1;

        if (nextChild.key !== null) {
            const found = keyed.get(nextChild.key);
            if (found !== undefined && !claimed.has(found) && sameType(prev[found], nextChild)) {
                matchIndex = found;
            }
        } else {
            // Scan forward for the next unclaimed, unkeyed, same-type node.
            //
            // The scan position is local and only committed to `cursor` on a
            // hit. Advancing the shared cursor while searching looks harmless
            // and is not: a child that finds no match consumes every candidate
            // it walked past, so the *next* child starts beyond nodes it could
            // have reused and is rebuilt from scratch instead.
            //
            // Concretely, this is what happens when a screen swaps a loading
            // placeholder for real content — <div class=pending> becomes <ul>.
            // Searching for the <ul> would step over the <form> that follows,
            // and the form, its input, the text being typed in it and the
            // focus would all be destroyed by a render that needed to touch
            // neither.
            let scan = cursor;
            while (scan < prev.length) {
                if (!claimed.has(scan) && prev[scan].key === null && sameType(prev[scan], nextChild)) {
                    matchIndex = scan;
                    cursor = scan + 1;
                    break;
                }
                scan++;
            }
        }

        if (matchIndex >= 0 && domNodes[matchIndex]) {
            claimed.add(matchIndex);
            finalNodes.push(patchNode(domNodes[matchIndex], prev[matchIndex], nextChild, doc, isSvg));
        } else {
            finalNodes.push(createNode(nextChild, doc, isSvg));
        }
    }

    for (let i = 0; i < domNodes.length; i++) {
        if (!claimed.has(i) && domNodes[i].parentNode === parent) {
            parent.removeChild(domNodes[i]);
        }
    }

    // Place nodes in order, moving only those actually out of position.
    // insertBefore on a node already in the right place is not free: it can
    // interrupt a running transition on that subtree.
    for (let i = 0; i < finalNodes.length; i++) {
        const node = finalNodes[i];
        const current = parent.childNodes[i];
        if (current !== node) parent.insertBefore(node, current || null);
    }
}

/* ------------------------------------------------------------------ *
 * public entry
 * ------------------------------------------------------------------ */

/**
 * Render `vnode` into `container`, reusing whatever is already there.
 *
 * Call it as often as you like — on every poll if that is simplest. If nothing
 * in the tree changed, nothing in the DOM is touched, so a redundant render is
 * cheap and, more importantly, invisible: no focus loss, no animation restart.
 */
export function render(vnode, container, options = {}) {
    const doc = options.document || container.ownerDocument || globalThis.document;
    const focus = captureFocus(doc);

    const prevVnode = container[VNODE] || null;
    const isSvg = container.namespaceURI === SVG_NS;

    if (vnode === null || vnode === undefined) {
        while (container.firstChild) container.removeChild(container.firstChild);
        container[VNODE] = null;
    } else {
        patchChildren(container, prevVnode ? [prevVnode] : [], [vnode], doc, isSvg);
        container[VNODE] = vnode;
    }

    restoreFocus(doc, focus);
    return container;
}

/** Discard a mounted tree and the vnode bookkeeping that goes with it. */
export function unmount(container) {
    while (container.firstChild) container.removeChild(container.firstChild);
    container[VNODE] = null;
}

export const __internals__ = { normalizeChildren, setClass, patchChildren, isTextEntry, cssName };
