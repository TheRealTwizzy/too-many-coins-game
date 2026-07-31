#!/usr/bin/env php
<?php
/**
 * Too Many Coins - Server Security Self-Check
 *
 *   php tools/server_security_selfcheck.php --base=http://127.0.0.1:8000
 *
 * Drives the REAL HTTP API the way an attacker would, rather than reading the
 * source and hoping. Every check here corresponds to a hole that was open:
 * each one is written so that reverting its fix makes it fail.
 *
 * The distinction that matters throughout: an endpoint returning [] because
 * the caller has no season is NOT the same as an endpoint refusing the
 * request. The first is a filter that can be bypassed by supplying the id
 * yourself; the second is a guard. These assert on refusals.
 *
 * Needs a running server. Registers its own throwaway accounts; clean them up
 * with tools/purge_test_accounts.php --handle=sec_* --apply.
 *
 * Exit: 0 = every check passed, 1 = at least one hole is open.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';

$opts = getopt('', ['base::', 'verbose']);
$base = rtrim($opts['base'] ?? 'http://127.0.0.1:8000', '/');
$api = $base . '/api/index.php';
$verbose = isset($opts['verbose']);

$pass = 0;
$fail = 0;

function check(string $name, bool $ok, $detail = null): void {
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  pass  {$name}\n";
    } else {
        $fail++;
        echo "  FAIL  {$name}";
        if ($detail !== null) echo "\n          " . json_encode($detail);
        echo "\n";
    }
}

/**
 * One API call. $token null = a caller with no session at all, which is the
 * posture most of these checks are about.
 */
function req(string $action, array $body = [], ?string $token = null): array {
    global $api;
    $ch = curl_init($api . '?action=' . urlencode($action));
    $headers = ['Content-Type: application/json'];
    if ($token !== null) $headers[] = 'X-Session-Token: ' . $token;
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = json_decode((string)$raw, true);
    return ['status' => $status, 'body' => is_array($decoded) ? $decoded : [], 'raw' => (string)$raw];
}

/**
 * Register a throwaway account and return its bearer token.
 *
 * The address is confirmed directly afterwards. Registration alone no longer
 * grants access - an unconfirmed account is refused every action but logout,
 * confirm, resend and reading state - and every check in this file is about a
 * *different* boundary. Leaving accounts unconfirmed would make all of them
 * pass for the wrong reason, which is worse than failing. The confirmation gate
 * has its own harness: tools/email_verification_selfcheck.php.
 */
function newAccount(string $tag): array {
    $handle = 'sec_' . $tag . '_' . substr(bin2hex(random_bytes(3)), 0, 5);
    $res = req('register', [
        'handle' => $handle,
        'email' => $handle . '@example.test',
        'password' => 'securitycheck123',
    ]);
    $token = $res['body']['token'] ?? null;
    if (!$token) {
        $login = req('login', ['email' => $handle . '@example.test', 'password' => 'securitycheck123']);
        $token = $login['body']['token'] ?? null;
    }
    if (!$token) {
        fwrite(STDERR, "could not create test account {$handle}: " . $res['raw'] . "\n");
        exit(1);
    }

    Database::getInstance()->query(
        "UPDATE players SET email_verified_at = NOW() WHERE handle_lower = LOWER(?)",
        [$handle]
    );
    $gs = req('game_state', [], $token);
    return [
        'handle' => $handle,
        'token' => $token,
        'player_id' => (int)($gs['body']['player']['player_id'] ?? 0),
    ];
}

/** True when the response is a refusal rather than a payload. */
function refused(array $res): bool {
    return !empty($res['body']['error']);
}

echo "Server security self-check\n" . str_repeat('-', 62) . "\n";
echo "target: {$api}\n\n";

$ping = req('game_state');
if ($ping['status'] === 0) {
    fwrite(STDERR, "No server at {$api}. Start one:\n"
        . "  php -S 127.0.0.1:8000 router.php\n");
    exit(1);
}

// Two accounts in the same active season, so cross-account reads have
// something real to leak.
$alice = newAccount('a');
$bob = newAccount('b');

$seasons = $ping['body']['seasons'] ?? [];
$activeSeasonId = 0;
foreach ($seasons as $s) {
    if (($s['computed_status'] ?? '') === 'Active') { $activeSeasonId = (int)$s['season_id']; break; }
}
if ($activeSeasonId > 0) {
    req('season_join', ['season_id' => $activeSeasonId], $alice['token']);
    req('season_join', ['season_id' => $activeSeasonId], $bob['token']);
    req('chat_send', ['channel' => 'SEASON', 'content' => 'sec check season line'], $alice['token']);
}
req('chat_send', ['channel' => 'GLOBAL', 'content' => 'sec check global line'], $alice['token']);

echo "accounts: {$alice['handle']} (#{$alice['player_id']}), {$bob['handle']} (#{$bob['player_id']})\n";
echo "season:   " . ($activeSeasonId ?: 'none active') . "\n\n";

// ---------------------------------------------------------------------------
// Chat transcripts are not public
// ---------------------------------------------------------------------------

check('anonymous caller cannot read GLOBAL chat',
    refused(req('chat_messages', ['channel' => 'GLOBAL'])),
    req('chat_messages', ['channel' => 'GLOBAL'])['body']);

check('anonymous caller cannot read a SEASON transcript by id',
    refused(req('chat_messages', ['channel' => 'SEASON', 'season_id' => $activeSeasonId ?: 1])),
    req('chat_messages', ['channel' => 'SEASON', 'season_id' => $activeSeasonId ?: 1])['body']);

check('a signed-in player CAN read global chat', (function () use ($alice) {
    $res = req('chat_messages', ['channel' => 'GLOBAL'], $alice['token']);
    $rows = is_array($res['body']) && isset($res['body'][0]) ? $res['body'] : ($res['body']['messages'] ?? []);
    return !refused($res) && count($rows) > 0;
})());

// The subtle half of the same hole: a logged-in player supplying someone
// else's season id. The guard must ignore the parameter, not merely require
// a session.
if ($activeSeasonId > 0) {
    $otherSeasonId = 0;
    foreach ($seasons as $s) {
        if ((int)$s['season_id'] !== $activeSeasonId) { $otherSeasonId = (int)$s['season_id']; break; }
    }
    if ($otherSeasonId > 0) {
        $res = req('chat_messages', ['channel' => 'SEASON', 'season_id' => $otherSeasonId], $alice['token']);
        $rows = isset($res['body'][0]) ? $res['body'] : ($res['body']['messages'] ?? []);
        // Alice is in $activeSeasonId. Asking for another season must give her
        // her OWN season's transcript (the id is ignored), never the other's.
        $leaked = false;
        foreach ($rows as $r) {
            if ((int)($r['season_id'] ?? $activeSeasonId) === $otherSeasonId) { $leaked = true; break; }
        }
        check('a player cannot read another season by passing season_id', !$leaked, $rows);
    }
}

check('an unknown chat channel is refused by name',
    (function () use ($alice) {
        $res = req('chat_messages', ['channel' => 'STAFF'], $alice['token']);
        return ($res['body']['reason_code'] ?? '') === 'unknown_channel';
    })(),
    req('chat_messages', ['channel' => 'STAFF'], $alice['token'])['body']);

check('an unknown channel cannot be posted to',
    (function () use ($alice) {
        $res = req('chat_send', ['channel' => 'STAFF', 'content' => 'should not store'], $alice['token']);
        return refused($res);
    })());

// ---------------------------------------------------------------------------
// Notifications cannot be forged
// ---------------------------------------------------------------------------

check('notifications_create is gone', (function () use ($alice) {
    $res = req('notifications_create', [
        'category' => 'staff',
        'title' => 'Account credited',
        'body' => 'forged',
    ], $alice['token']);
    return ($res['body']['error'] ?? '') === 'Unknown action';
})());

check('a forged notification never reaches the feed', (function () use ($alice) {
    $gs = req('game_state', [], $alice['token']);
    foreach (($gs['body']['player']['notifications'] ?? []) as $n) {
        if (($n['body'] ?? '') === 'forged') return false;
    }
    return true;
})());

check('a normal player cannot broadcast a staff notification',
    refused(req('staff_notifications_send_all', ['title' => 'hello'], $alice['token'])),
    req('staff_notifications_send_all', ['title' => 'hello'], $alice['token'])['body']);

check('a normal player cannot send a staff notification to another player',
    refused(req('staff_notifications_send_player',
        ['target_player_id' => $bob['player_id'], 'title' => 'hello'], $alice['token'])));

// ---------------------------------------------------------------------------
// Staff surfaces are server-gated, not merely hidden in the client
// ---------------------------------------------------------------------------

foreach ([
    'staff_users_search' => ['query' => 'sec_'],
    'staff_user_get' => ['target_player_id' => 1],
    'staff_server_mode' => ['mode' => 'MAINTENANCE_LOCKDOWN'],
    'admin_role_update' => ['target_player_id' => 1, 'role' => 'Admin'],
    'staff_chat_mute_user' => ['target_player_id' => 1, 'scope' => 'ALL'],
] as $action => $body) {
    check("{$action} is refused for a normal player", refused(req($action, $body, $alice['token'])));
    check("{$action} is refused with no session", refused(req($action, $body)));
}

// ---------------------------------------------------------------------------
// Session handling
// ---------------------------------------------------------------------------

check('a forged bearer token is rejected',
    refused(req('game_state', [], 'not-a-real-token-000000000000')) ||
    empty(req('game_state', [], 'not-a-real-token-000000000000')['body']['player']),
    req('game_state', [], 'not-a-real-token-000000000000')['body']['player'] ?? null);

check('game_state without a session exposes no player',
    (req('game_state')['body']['player'] ?? null) === null);

// ---------------------------------------------------------------------------
// The rate limiter cannot be steered by the caller
// ---------------------------------------------------------------------------
//
// The limiter picks its bucket from the session token. It used to accept any
// 64 hex characters without checking the token existed, so a fresh random
// X-Session-Token per request minted a fresh high-tier bucket every time and
// the limiter was decorative - which is unmetered password guessing.
//
// Rather than send hundreds of requests here, assert the property directly:
// a made-up token must not be granted the authenticated tier. The diagnostics
// endpoint reports the tier when it is enabled; when it is not, fall back to
// asserting that a forged token is not treated as a live session anywhere.

check('a forged 64-hex token is not accepted as a session', (function () {
    $forged = str_repeat('a1', 32); // 64 hex chars, no such account
    $res = req('game_state', [], $forged);
    return ($res['body']['player'] ?? null) === null;
})());

// The burst check that proves the limiter cannot be steered lives at the very
// END of this file, because passing it necessarily exhausts the anonymous
// bucket for this IP and would 429 every anonymous check that ran after it.

// ---------------------------------------------------------------------------
// State-changing actions cannot be driven by a link (CSRF)
// ---------------------------------------------------------------------------
//
// Every action used to run over a plain GET with query-string parameters, and
// the session cookie is SameSite=Lax - which withholds it from cross-site
// subresources but SENDS it on top-level navigations. A link on any page was
// therefore enough to act as whoever clicked it; against an Admin that reaches
// admin_role_update.

/** GET the API with query parameters, the way a hostile link would. */
function reqGet(string $action, array $params = [], ?string $token = null): array {
    global $api;
    $url = $api . '?action=' . urlencode($action) . '&' . http_build_query($params);
    $ch = curl_init($url);
    $headers = [];
    if ($token !== null) $headers[] = 'X-Session-Token: ' . $token;
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = json_decode((string)$raw, true);
    return ['status' => $status, 'body' => is_array($decoded) ? $decoded : []];
}

$csrfMarker = 'csrf_probe_' . bin2hex(random_bytes(4));
$csrfAttempt = reqGet('chat_send', ['channel' => 'GLOBAL', 'content' => $csrfMarker], $alice['token']);
check('a state-changing action is refused over GET',
    $csrfAttempt['status'] === 405, $csrfAttempt);

check('...and the GET attempt changed nothing', (function () use ($csrfMarker, $alice) {
    $res = req('chat_messages', ['channel' => 'GLOBAL'], $alice['token']);
    $rows = isset($res['body'][0]) ? $res['body'] : ($res['body']['messages'] ?? []);
    foreach ($rows as $r) {
        if (strpos((string)($r['content'] ?? ''), $csrfMarker) !== false) return false;
    }
    return true;
})());

check('privilege escalation over GET is refused',
    reqGet('admin_role_update', ['target_player_id' => 1, 'role' => 'Admin'], $alice['token'])['status'] === 405);

check('the same action still works over POST', (function () use ($alice) {
    return !refused(req('chat_send', ['channel' => 'GLOBAL', 'content' => 'post path intact'], $alice['token']));
})());

check('read-only actions remain reachable over GET',
    reqGet('game_state')['status'] === 200);

// ---------------------------------------------------------------------------
// Audit snapshots never carry credentials
// ---------------------------------------------------------------------------
//
// Several callers hand Audit::record a whole `SELECT * FROM players` row as
// the before-state, so the row arrives carrying the target's password hash and
// their live session token - and staff_user_get served those rows back, which
// let any Moderator lift a working token for anyone they had edited.
//
// Exercised through reflection rather than over HTTP because reproducing it
// live needs an Admin account, and the property worth locking down is the
// redaction itself: it must strip secrets at any depth and leave everything
// else untouched.
require_once __DIR__ . '/../includes/audit.php';

check('audit redaction strips credentials at any depth', (function () {
    $method = new ReflectionMethod('Audit', 'redact');
    $method->setAccessible(true);
    $dirty = [
        'player_id' => 7,
        'handle' => 'victim',
        'password_hash' => '$2y$10$abcdefghijklmnopqrstuv',
        'session_token' => str_repeat('d', 64),
        'nested' => ['session_token' => str_repeat('e', 64), 'bio' => 'keep me'],
    ];
    $clean = $method->invoke(null, $dirty);
    $blob = json_encode($clean);
    return strpos($blob, '$2y$') === false
        && strpos($blob, str_repeat('d', 64)) === false
        && strpos($blob, str_repeat('e', 64)) === false;
})());

check('audit redaction preserves non-secret fields', (function () {
    $method = new ReflectionMethod('Audit', 'redact');
    $method->setAccessible(true);
    $clean = $method->invoke(null, [
        'player_id' => 7,
        'handle' => 'victim',
        'nested' => ['bio' => 'keep me', 'role' => 'Player'],
    ]);
    return $clean['player_id'] === 7
        && $clean['handle'] === 'victim'
        && $clean['nested']['bio'] === 'keep me'
        && $clean['nested']['role'] === 'Player';
})());

check('audit redaction marks the field rather than dropping it', (function () {
    $method = new ReflectionMethod('Audit', 'redact');
    $method->setAccessible(true);
    $clean = $method->invoke(null, ['password_hash' => 'secret']);
    return array_key_exists('password_hash', $clean) && $clean['password_hash'] === '[redacted]';
})());

// ---------------------------------------------------------------------------
// Season standings are not an anonymous census
// ---------------------------------------------------------------------------
//
// The leaderboard returned every participant's coin balance, income rate,
// freeze state, boost percentage and online flag - to a caller with no
// session. getProfile was hardened against exactly that; this was the way
// around it.

check('the season leaderboard requires a session',
    refused(req('leaderboard', ['season_id' => $activeSeasonId ?: 1])),
    req('leaderboard', ['season_id' => $activeSeasonId ?: 1])['body']);

check('the global leaderboard requires a session',
    refused(req('global_leaderboard')));

check('another player\'s private state is stripped from the standings', (function () use ($alice, $activeSeasonId) {
    $res = req('leaderboard', ['season_id' => $activeSeasonId ?: 1], $alice['token']);
    $rows = isset($res['body'][0]) ? $res['body'] : ($res['body']['leaderboard'] ?? []);
    $mine = (int)$alice['player_id'];
    foreach ($rows as $row) {
        if ((int)($row['player_id'] ?? 0) === $mine) continue; // your own row is yours
        foreach (['coins', 'rate_per_tick', 'boost_pct', 'is_frozen'] as $secret) {
            if (array_key_exists($secret, $row)) return false;
        }
    }
    return count($rows) > 0;
})(), 'a rival row still carried combat state');

check('your own standings row keeps your own figures', (function () use ($alice, $activeSeasonId) {
    $res = req('leaderboard', ['season_id' => $activeSeasonId ?: 1], $alice['token']);
    $rows = isset($res['body'][0]) ? $res['body'] : ($res['body']['leaderboard'] ?? []);
    foreach ($rows as $row) {
        if ((int)($row['player_id'] ?? 0) === (int)$alice['player_id']) {
            return array_key_exists('coins', $row);
        }
    }
    return true; // not in this season; nothing to assert
})());

check('standings are bounded', (function () use ($alice, $activeSeasonId) {
    // LEADERBOARD_MAX_LIMIT is defined inside api/index.php rather than
    // config.php, so it is not visible from a CLI harness. Mirrored here; the
    // point of the check is that SOME cap applies, not its exact value.
    $cap = 200;
    $res = req('season_detail', ['season_id' => $activeSeasonId ?: 1], $alice['token']);
    $rows = $res['body']['leaderboard'] ?? [];
    return count($rows) <= $cap;
})());

// ---------------------------------------------------------------------------
// The theft preview is not a free inventory oracle
// ---------------------------------------------------------------------------
//
// It used to answer "Requested sigils exceed target inventory", which is a
// yes/no on "does this player hold at least N of tier T" - free, instant,
// unlimited, and silent to the target. Walking N per tier read their exact
// inventory.

check('the theft preview does not confirm a target\'s holdings', (function () use ($alice, $bob) {
    $res = req('sigil_theft_preview', [
        'target_player_id' => $bob['player_id'],
        'spent_sigils' => [1, 0, 0, 0, 0, 0],
        // Deliberately absurd: nobody holds 999 of a tier.
        'requested_sigils' => [999, 0, 0, 0, 0, 0],
    ], $alice['token']);
    return stripos((string)($res['body']['error'] ?? ''), 'target inventory') === false;
})(), 'the preview still reports whether the target holds the requested sigils');

// ---------------------------------------------------------------------------
// Per-action throttles
// ---------------------------------------------------------------------------
//
// The global allowance is sized for a client that polls every 3 seconds, which
// is far too loose for actions where one request is expensive or carries
// real-world weight (mail, bcrypt, notifications aimed at another player).

check('chat flooding is throttled well below the global allowance', (function () use ($alice) {
    // Mirrors TMC_ACTION_LIMITS in api/index.php, which is not visible from a
    // CLI harness. The assertion is that a throttle bites at all, not where.
    $limit = 20;
    $refusedCount = 0;
    for ($i = 0; $i < $limit + 6; $i++) {
        $res = req('chat_send', ['channel' => 'GLOBAL', 'content' => 'flood probe ' . $i], $alice['token']);
        if (($res['body']['reason_code'] ?? '') === 'action_rate_limited') $refusedCount++;
    }
    return $refusedCount > 0;
})(), 'chat_send was never throttled');

// ---------------------------------------------------------------------------
// LAST: the rate limiter cannot be steered by the caller
// ---------------------------------------------------------------------------
//
// Deliberately final. The limiter used to key on a session token it never
// validated, so a fresh random X-Session-Token per request minted a fresh
// high-tier bucket and the limiter was decorative - unmetered password
// guessing. Proving it is closed means bursting past the anonymous allowance,
// which exhausts this IP's bucket; anything after it would be refused for the
// wrong reason.

check('a forged token cannot raise the caller above the anonymous tier', (function () {
    $anonLimit = (int)TMC_RATE_LIMIT_ANON_PER_WINDOW;
    $shots = $anonLimit + 30;
    $refused = 0;
    for ($i = 0; $i < $shots; $i++) {
        $token = str_pad(dechex(0xF0000 + $i), 64, '0', STR_PAD_LEFT);
        if (req('game_state', [], $token)['status'] === 429) $refused++;
    }
    return $refused > 0;
})(), 'a rotating forged token was never rate limited');

echo "\n" . str_repeat('-', 62) . "\n";
echo "{$pass} passed, {$fail} failed\n";
echo $fail === 0
    ? "Result: PASS - the audited holes are closed.\n"
    : "Result: FAIL - at least one hole is open.\n";

exit($fail === 0 ? 0 : 1);
