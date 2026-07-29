/**
 * core/store.js — observable state with change-path notification
 *
 * The legacy client polls game_state every 3s, assigns the whole response to
 * TMC.state, and then re-renders whatever screen is showing. Since almost
 * every poll returns a payload that is structurally identical but not
 * reference-identical, "something changed" was indistinguishable from
 * "everything changed", and the only available response was to rebuild the
 * DOM. That is what destroys focus, selection and running animations.
 *
 * This store answers a narrower question: which paths actually hold a
 * different value now than they did before? A poll where only the coin count
 * moved reports exactly one changed path, and only the subscribers standing
 * on that path are woken.
 */

/** Values that arrive from the API are JSON, so structural equality is enough. */
function equal(a, b) {
    if (a === b) return true;

    // NaN === NaN is false, but for change detection they are the same value.
    if (typeof a === 'number' && typeof b === 'number') {
        return Number.isNaN(a) && Number.isNaN(b);
    }

    if (a === null || b === null) return false;
    if (typeof a !== 'object' || typeof b !== 'object') return false;

    const aIsArray = Array.isArray(a);
    if (aIsArray !== Array.isArray(b)) return false;

    if (aIsArray) {
        if (a.length !== b.length) return false;
        for (let i = 0; i < a.length; i++) {
            if (!equal(a[i], b[i])) return false;
        }
        return true;
    }

    const aKeys = Object.keys(a);
    const bKeys = Object.keys(b);
    if (aKeys.length !== bKeys.length) return false;
    for (const key of aKeys) {
        if (!Object.prototype.hasOwnProperty.call(b, key)) return false;
        if (!equal(a[key], b[key])) return false;
    }
    return true;
}

function isPlainObject(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

/**
 * Walk two trees and record every path whose value differs.
 *
 * Both the container path and the differing paths beneath it are recorded.
 * That is what lets a subscriber choose its own granularity: watching
 * `player` wakes on any change within the player, watching `player.coins`
 * wakes only when the coin count itself moves — even though a poll replaced
 * the entire player object either way.
 */
function collectChanges(prev, next, prefix, out) {
    if (equal(prev, next)) return;

    if (prefix) out.add(prefix);

    const prevHasShape = prev !== null && typeof prev === 'object';
    const nextHasShape = next !== null && typeof next === 'object';

    // Two differing leaves. The path is recorded; there is nothing beneath it.
    if (!prevHasShape && !nextHasShape) return;

    // Recurse whenever *either* side has shape, pairing the missing side with
    // undefined. This matters for the transitions that bracket a session:
    // `player` going null -> object on login, or object -> null on logout,
    // must still report `player.coins` so a watcher on the coin count wakes.
    // Object.keys covers arrays too, yielding index keys, so lists and objects
    // take the same path. Lists that reorder report broadly here; the render
    // layer keys them, so a noisy signal costs a reconcile and never a rebuild.
    const prevNode = prevHasShape ? prev : {};
    const nextNode = nextHasShape ? next : {};
    const keys = new Set([...Object.keys(prevNode), ...Object.keys(nextNode)]);
    for (const key of keys) {
        collectChanges(prevNode[key], nextNode[key], prefix ? `${prefix}.${key}` : key, out);
    }
}

function readPath(root, path) {
    if (!path) return root;
    let node = root;
    for (const segment of path.split('.')) {
        if (node === null || node === undefined) return undefined;
        node = node[segment];
    }
    return node;
}

/**
 * Immutably write `value` at `path`, cloning only the spine down to it.
 *
 * Untouched branches keep their identity, so a consumer holding a reference to
 * an unrelated subtree can still trust `===` to mean "unchanged".
 */
function writePath(root, path, value) {
    if (!path) return value;

    const segments = path.split('.');
    const head = segments[0];
    const rest = segments.slice(1).join('.');

    const base = isPlainObject(root) || Array.isArray(root) ? root : {};
    const nextChild = rest ? writePath(base[head], rest, value) : value;

    if (Array.isArray(base)) {
        const copy = base.slice();
        copy[Number(head)] = nextChild;
        return copy;
    }
    return { ...base, [head]: nextChild };
}

/** Does a change at `changed` concern a subscriber watching `watched`? */
function pathMatches(watched, changed) {
    if (watched === '*') return true;
    if (watched === changed) return true;
    // A subscriber on `player` cares about `player.coins`.
    if (changed.startsWith(watched + '.')) return true;

    // Deliberately no descendant rule. It is tempting to also wake a watcher
    // on `player.coins` whenever `player` appears in the changed set, but
    // `player` is in that set on *every* poll that moves any field, so the
    // rule would wake every leaf watcher for every change and undo the whole
    // point of diffing. collectChanges already records the granular paths,
    // including across null/object transitions, so an exact or ancestor match
    // is sufficient and precise.
    return false;
}

export function createStore(initialState = {}) {
    let state = initialState;
    const subscribers = new Map(); // path -> Set<fn>

    let pendingPaths = null;
    let pendingPrev = null;

    function flush() {
        const paths = pendingPaths;
        const prev = pendingPrev;
        pendingPaths = null;
        pendingPrev = null;

        if (!paths || paths.size === 0) return;

        // Snapshot before dispatching: a subscriber is allowed to unsubscribe,
        // or to write to the store, without corrupting this pass.
        const calls = [];
        for (const [watched, fns] of subscribers) {
            let hit = false;
            for (const changed of paths) {
                if (pathMatches(watched, changed)) { hit = true; break; }
            }
            if (!hit) continue;
            for (const fn of fns) calls.push([fn, watched]);
        }

        for (const [fn, watched] of calls) {
            const nextValue = watched === '*' ? state : readPath(state, watched);
            const prevValue = watched === '*' ? prev : readPath(prev, watched);
            try {
                fn(nextValue, prevValue, paths);
            } catch (err) {
                // One broken subscriber must not stop the rest of the UI
                // updating — a render bug in the chat panel should not freeze
                // the coin counter.
                console.error(`[store] subscriber for "${watched}" threw:`, err);
            }
        }
    }

    /**
     * Notifications are batched to a microtask. A single poll writes player,
     * seasons and notifications in sequence; without batching each write would
     * drive its own render pass and the UI would be reconciled three times for
     * one payload.
     */
    function schedule(prev, paths) {
        if (paths.size === 0) return;

        if (pendingPaths) {
            for (const p of paths) pendingPaths.add(p);
            return;
        }

        pendingPaths = paths;
        pendingPrev = prev;
        queueMicrotask(flush);
    }

    return {
        /** Read the whole state, or one path. Returned values are not cloned; treat them as read-only. */
        get(path) {
            return readPath(state, path);
        },

        /** Write one path. No-ops entirely if the value is structurally unchanged. */
        set(path, value) {
            const prev = state;
            const changes = new Set();
            collectChanges(readPath(prev, path), value, path, changes);
            if (changes.size === 0) return false;

            state = writePath(prev, path, value);
            schedule(prev, changes);
            return true;
        },

        /**
         * Shallow-merge a partial at the top level, diffing each key.
         *
         * This is the shape a poll response arrives in: `{player, seasons, ...}`
         * where most keys are byte-identical to last time. Keys that did not
         * move produce no paths and wake nobody.
         */
        patch(partial) {
            if (!isPlainObject(partial)) {
                throw new TypeError('store.patch expects a plain object');
            }

            const prev = state;
            const changes = new Set();
            let next = prev;

            for (const key of Object.keys(partial)) {
                const before = readPath(prev, key);
                const after = partial[key];
                const keyChanges = new Set();
                collectChanges(before, after, key, keyChanges);
                if (keyChanges.size === 0) continue;

                next = writePath(next, key, after);
                for (const p of keyChanges) changes.add(p);
            }

            if (changes.size === 0) return false;
            state = next;
            schedule(prev, changes);
            return true;
        },

        /**
         * Wake `fn` when `path` changes. Use '*' for any change.
         * Returns an unsubscribe function.
         */
        subscribe(path, fn) {
            if (typeof fn !== 'function') {
                throw new TypeError('store.subscribe expects a function');
            }
            let fns = subscribers.get(path);
            if (!fns) {
                fns = new Set();
                subscribers.set(path, fns);
            }
            fns.add(fn);

            return function unsubscribe() {
                const current = subscribers.get(path);
                if (!current) return;
                current.delete(fn);
                if (current.size === 0) subscribers.delete(path);
            };
        },

        /**
         * Run pending notifications now instead of at the end of the microtask
         * queue. Only needed by tests and by teardown paths that must observe
         * their own write before the frame ends.
         */
        flushNow() {
            if (pendingPaths) flush();
        },
    };
}

export const __test__ = { equal, collectChanges, readPath, writePath, pathMatches };
