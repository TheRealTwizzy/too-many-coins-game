<?php
/**
 * Too Many Coins - API Router
 * All game API endpoints
 */

// Security headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Session-Token, X-Tick-Secret, X-Init-Secret');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../includes/config.php';

function tmc_is_valid_ip(?string $value): bool {
    if (!is_string($value) || $value === '') {
        return false;
    }
    return filter_var($value, FILTER_VALIDATE_IP) !== false;
}

function tmc_is_private_or_reserved_ip(?string $value): bool {
    if (!tmc_is_valid_ip($value)) {
        return false;
    }
    return filter_var(
        $value,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) === false;
}

function tmc_extract_first_valid_ip(?string $headerValue): ?string {
    if (!is_string($headerValue) || trim($headerValue) === '') {
        return null;
    }
    $parts = explode(',', $headerValue);
    foreach ($parts as $part) {
        $candidate = trim($part);
        if (tmc_is_valid_ip($candidate)) {
            return $candidate;
        }
    }
    return null;
}

function tmc_proxy_is_trusted(?string $remoteAddr): bool {
    if (TMC_TRUST_PROXY_HEADERS) {
        return true;
    }

    $trusted = array_filter(array_map('trim', explode(',', (string)TMC_TRUSTED_PROXIES)));
    if (!empty($trusted) && is_string($remoteAddr) && $remoteAddr !== '') {
        foreach ($trusted as $trustedIp) {
            if ($trustedIp === $remoteAddr) {
                return true;
            }
        }
    }

    // A private REMOTE_ADDR is NOT evidence that the peer is a trusted proxy.
    //
    // This used to return true for any private/reserved address, which is the
    // address a containerised deploy always sees - so on the documented
    // Dokploy/Traefik setup every caller was trusted to declare their own IP
    // via CF-Connecting-IP, and could mint a fresh anonymous rate-limit bucket
    // per request simply by changing the header.
    //
    // Trust now has to be configured (TMC_TRUST_PROXY_HEADERS, or the peer
    // listed in TMC_TRUSTED_PROXIES). Behind an unconfigured proxy the
    // anonymous tier collapses to one shared bucket keyed on the proxy's
    // address, which is the safe direction to fail: the only traffic in that
    // tier is unauthenticated (login, register), which warrants a tight global
    // ceiling anyway, while every signed-in player already has their own
    // validated session bucket.
    if (tmc_is_private_or_reserved_ip($remoteAddr)) {
        tmc_warn_untrusted_proxy_once($remoteAddr);
    }
    return false;
}

/**
 * Say once, loudly, that proxy headers are being ignored.
 *
 * Silently sharing one bucket is safe but confusing to debug - it looks like
 * the limiter is too aggressive rather than like missing configuration.
 */
function tmc_warn_untrusted_proxy_once(?string $remoteAddr): void {
    static $warned = false;
    if ($warned) return;
    $warned = true;
    if (!isset($_SERVER['HTTP_X_FORWARDED_FOR'])
        && !isset($_SERVER['HTTP_X_REAL_IP'])
        && !isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return; // No proxy headers present; nothing is being ignored.
    }
    error_log(
        '[rate_limit] proxy headers present from ' . (string)$remoteAddr
        . ' but that peer is not a configured trusted proxy, so they are ignored '
        . 'and the anonymous tier is keyed on the peer address. Set '
        . 'TMC_TRUSTED_PROXIES to this address (or TMC_TRUST_PROXY_HEADERS=1) '
        . 'if it really is your reverse proxy.'
    );
}

function tmc_resolve_client_ip(): string {
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!tmc_proxy_is_trusted($remoteAddr)) {
        return tmc_is_valid_ip($remoteAddr) ? $remoteAddr : 'unknown';
    }

    $cfIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null;
    if (tmc_is_valid_ip($cfIp)) {
        return $cfIp;
    }

    $realIp = $_SERVER['HTTP_X_REAL_IP'] ?? null;
    if (tmc_is_valid_ip($realIp)) {
        return $realIp;
    }

    $forwardedFor = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
    $xffIp = tmc_extract_first_valid_ip($forwardedFor);
    if ($xffIp !== null) {
        return $xffIp;
    }

    return tmc_is_valid_ip($remoteAddr) ? $remoteAddr : 'unknown';
}

function tmc_rate_limit_log(string $phase, string $tier, string $identity, int $count, int $limit, int $window, string $action): void {
    if (!TMC_RATE_LIMIT_TRACE) {
        return;
    }

    $samplePct = (int)TMC_RATE_LIMIT_TRACE_SAMPLE_PCT;
    if ($samplePct < 100 && random_int(1, 100) > max(0, $samplePct)) {
        return;
    }

    $payload = [
        'event' => 'rate_limit',
        'phase' => $phase,
        'tier' => $tier,
        'identity_hash' => hash('sha256', $identity),
        'count' => $count,
        'limit' => $limit,
        'window_seconds' => $window,
        'action' => $action,
        'status' => ($phase === 'blocked' ? 429 : 200),
    ];

    error_log('[rate_limit] ' . json_encode($payload));
}

function tmc_rate_limit_diagnostics_authorized(array $input): bool {
    $diagEnabled = (bool)TMC_RATE_LIMIT_DIAGNOSTICS;
    $initSecret = trim((string)(getenv('TMC_INIT_SECRET') ?: ''));

    if (!$diagEnabled || $initSecret === '') {
        return false;
    }

    $provided = $input['secret'] ?? ($_SERVER['HTTP_X_INIT_SECRET'] ?? '');
    $provided = trim((string)$provided);
    if ($provided === '') {
        return false;
    }

    return hash_equals($initSecret, $provided);
}

// The limiter validates the caller's session token below, so the database and
// Auth have to be available before it runs rather than only at dispatch time.
// Both are require_once, so the dispatch block further down is unaffected.
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';

// Rate limiting (file-based) keyed by session identity when available.
$rateLimitDir = sys_get_temp_dir() . '/tmc_ratelimit';
if (!is_dir($rateLimitDir)) {
    @mkdir($rateLimitDir, 0755, true);
}

/**
 * Sweep expired buckets.
 *
 * One file is created per identity and nothing ever removed them, so the
 * directory grew without bound - and since an identity could be minted per
 * request, an attacker could fill the filesystem's inodes with a long enough
 * loop. Buckets are worthless once their window has passed, so anything older
 * than a few windows is deleted.
 *
 * Sampled rather than run every request: this is opportunistic housekeeping,
 * not something worth a directory scan on a hot path. The cap keeps the worst
 * case bounded when a sweep does happen.
 */
function tmc_rate_limit_sweep(string $dir, int $windowSeconds): void {
    // ~1 request in 500 pays for the sweep.
    if (random_int(1, 500) !== 1) return;

    $staleBefore = time() - max(60, $windowSeconds * 5);
    $handle = @opendir($dir);
    if ($handle === false) return;

    $examined = 0;
    while (($entry = readdir($handle)) !== false) {
        if ($entry === '.' || $entry === '..') continue;
        if (++$examined > 5000) break;
        if (substr($entry, -5) !== '.json') continue;
        $path = $dir . '/' . $entry;
        $mtime = @filemtime($path);
        if ($mtime !== false && $mtime < $staleBefore) {
            @unlink($path);
        }
    }
    closedir($handle);
}
tmc_rate_limit_sweep($rateLimitDir, (int)TMC_RATE_LIMIT_WINDOW_SECONDS);

$sessionToken = $_COOKIE['tmc_session'] ?? ($_SERVER['HTTP_X_SESSION_TOKEN'] ?? '');
$sessionToken = is_string($sessionToken) ? trim($sessionToken) : '';

// The session bucket requires a token that actually EXISTS, not one that
// merely looks like a token.
//
// This used to accept any 64 hex characters, so the identity the limiter keyed
// on was chosen by the caller: sending a fresh random X-Session-Token per
// request minted a fresh 300/minute bucket every time and the limiter was a
// no-op. Measured against this endpoint before the fix - 350 login attempts
// with one token: 300 served, 50 rejected. The same 350 with a rotating token:
// 350 served, 0 rejected. That is unmetered password guessing, and it also
// spawned one bucket file per made-up token.
//
// Looking the token up costs one indexed query on requests that present one,
// and an unknown token now falls through to the anonymous IP bucket - the
// same place a caller with no token lands - so a forged header can only ever
// give an attacker the smaller allowance, never a bigger one.
$hasSessionIdentity = false;
if (preg_match('/^[a-f0-9]{64}$/i', $sessionToken) === 1) {
    $hasSessionIdentity = Auth::sessionTokenExists($sessionToken);
}

if ($hasSessionIdentity) {
    $rateIdentity = 'session:' . hash('sha256', $sessionToken);
    $rateTier = 'auth';
    $rateLimit = (int)TMC_RATE_LIMIT_AUTH_PER_WINDOW;
} else {
    $clientIp = tmc_resolve_client_ip();
    $rateIdentity = 'ip:' . $clientIp;
    $rateTier = 'anon';
    $rateLimit = (int)TMC_RATE_LIMIT_ANON_PER_WINDOW;
}

$rateLimitFile = $rateLimitDir . '/' . md5($rateIdentity) . '.json';
$rateWindow = (int)TMC_RATE_LIMIT_WINDOW_SECONDS;
$now = time();
$rateAction = $_GET['action'] ?? ($_POST['action'] ?? 'unknown');
// Atomic read-modify-write under an exclusive lock.
//
// The previous unlocked version had two failure modes that both let the limiter
// fail open under exactly the burst traffic it exists to stop:
//   1. Lost updates - N concurrent requests all read count=k and all wrote k+1.
//   2. Torn reads - file_put_contents is not atomic, so a concurrent reader could
//      decode a partial write as null and reset the window to count=0.
$rateData = ['window_start' => $now, 'count' => 1];
$rateHandle = @fopen($rateLimitFile, 'c+');
if ($rateHandle !== false) {
    if (flock($rateHandle, LOCK_EX)) {
        $stored = json_decode((string)stream_get_contents($rateHandle), true);
        if (is_array($stored) && isset($stored['window_start'])
            && ($now - (int)$stored['window_start']) < $rateWindow) {
            $rateData = [
                'window_start' => (int)$stored['window_start'],
                'count'        => (int)($stored['count'] ?? 0) + 1,
            ];
        }
        rewind($rateHandle);
        ftruncate($rateHandle, 0);
        fwrite($rateHandle, json_encode($rateData));
        fflush($rateHandle);
        flock($rateHandle, LOCK_UN);
    }
    fclose($rateHandle);
}
// NOTE: this counter is per-container (sys_get_temp_dir). Horizontally scaling the
// web service multiplies the effective limit by the replica count; a shared store
// (Redis INCR / MySQL counter table) is the follow-up for multi-replica deploys.

header('X-RateLimit-Tier: ' . $rateTier);
header('X-RateLimit-Limit: ' . (int)$rateLimit);
header('X-RateLimit-Remaining: ' . max(0, (int)$rateLimit - (int)$rateData['count']));
header('X-RateLimit-Window: ' . (int)$rateWindow);

if ($rateData['count'] > $rateLimit) {
    tmc_rate_limit_log('blocked', $rateTier, $rateIdentity, (int)$rateData['count'], (int)$rateLimit, (int)$rateWindow, (string)$rateAction);
    http_response_code(429);
    echo json_encode(['error' => 'Rate limit exceeded. Please slow down.']);
    exit;
}

tmc_rate_limit_log('pass', $rateTier, $rateIdentity, (int)$rateData['count'], (int)$rateLimit, (int)$rateWindow, (string)$rateAction);

/**
 * Per-action ceilings, on top of the global allowance.
 *
 * The global limit is sized for ordinary play - a few hundred requests a
 * minute is normal when the client polls every 3 seconds - which makes it far
 * too loose for the handful of actions where a single request is expensive or
 * carries real-world weight:
 *
 *   chat_send                a few hundred posts a minute scrolls the readable
 *                            transcript away for everyone, and CHAT_MAX_ROWS
 *                            means older messages are simply gone
 *   register                 burns a bcrypt hash per call and permanently
 *                            squats a handle in a global namespace
 *   login                    the guessing surface; deliberately tighter than
 *                            the global tier even for a valid session
 *   account_delete_request   sends mail, synchronously, to an address supplied
 *                            by the caller
 *   friend_request_send      notification spam aimed at another player
 *   sight_reveal / freeze_player_ubi / sigil_theft_attempt
 *                            hostile verbs that notify the target
 *
 * Same file-and-lock mechanism as the global limiter, keyed per identity AND
 * action so one noisy action cannot exhaust another's allowance.
 */
const TMC_ACTION_LIMITS = [
    'login'                  => ['limit' => 10, 'window' => 300],
    'register'               => ['limit' => 5,  'window' => 3600],
    'account_delete_request' => ['limit' => 3,  'window' => 3600],
    'account_change_password' => ['limit' => 10, 'window' => 3600],
    'chat_send'              => ['limit' => 20, 'window' => 60],
    'friend_request_send'    => ['limit' => 20, 'window' => 300],
    'sigil_theft_attempt'    => ['limit' => 30, 'window' => 300],
    'freeze_player_ubi'      => ['limit' => 30, 'window' => 300],
    'sight_reveal'           => ['limit' => 30, 'window' => 300],
];

if (isset(TMC_ACTION_LIMITS[$rateAction])) {
    $actionRule = TMC_ACTION_LIMITS[$rateAction];
    $actionWindow = (int)$actionRule['window'];
    $actionLimitMax = (int)$actionRule['limit'];
    $actionFile = $rateLimitDir . '/a_' . md5($rateIdentity . '|' . $rateAction) . '.json';

    $actionData = ['window_start' => $now, 'count' => 1];
    $actionHandle = @fopen($actionFile, 'c+');
    if ($actionHandle !== false) {
        if (flock($actionHandle, LOCK_EX)) {
            $existing = stream_get_contents($actionHandle);
            $decoded = json_decode((string)$existing, true);
            if (is_array($decoded)
                && isset($decoded['window_start'], $decoded['count'])
                && ($now - (int)$decoded['window_start']) < $actionWindow) {
                $actionData = [
                    'window_start' => (int)$decoded['window_start'],
                    'count' => (int)$decoded['count'] + 1,
                ];
            }
            ftruncate($actionHandle, 0);
            rewind($actionHandle);
            fwrite($actionHandle, json_encode($actionData));
            fflush($actionHandle);
            flock($actionHandle, LOCK_UN);
        }
        fclose($actionHandle);
    }

    if ((int)$actionData['count'] > $actionLimitMax) {
        $retryAfter = max(1, $actionWindow - ($now - (int)$actionData['window_start']));
        tmc_rate_limit_log('blocked', $rateTier . ':' . $rateAction, $rateIdentity,
            (int)$actionData['count'], $actionLimitMax, $actionWindow, (string)$rateAction);
        http_response_code(429);
        header('Retry-After: ' . $retryAfter);
        echo json_encode([
            'error' => 'You are doing that too often. Try again shortly.',
            'reason_code' => 'action_rate_limited',
            'action' => $rateAction,
            'retry_after_seconds' => $retryAfter,
        ]);
        exit;
    }
}

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/game_time.php';
require_once __DIR__ . '/../includes/boost_catalog.php';
require_once __DIR__ . '/../includes/economy.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/actions.php';
require_once __DIR__ . '/../includes/tick_engine.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/account.php';
require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../includes/social.php';
require_once __DIR__ . '/../includes/moderation.php';
require_once __DIR__ . '/../includes/staff_chat.php';
require_once __DIR__ . '/../includes/sigil_drops_api.php';
require_once __DIR__ . '/../includes/sigil_families.php';
require_once __DIR__ . '/../includes/family_actions.php';
require_once __DIR__ . '/../includes/runtime_readiness.php';

if (!defined('LEADERBOARD_MAX_LIMIT')) {
    define('LEADERBOARD_MAX_LIMIT', 200);
}

// Database initialization endpoint (must be before server_state check)
$earlyAction = $_GET['action'] ?? '';
if ($earlyAction === 'init_db') {
    require_once __DIR__ . '/../init_db.php';
    exit;
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Must run before server_state check
if ($path === '/api/init_db') {
 require __DIR__ . '/../init_db.php';
 exit;
}

// Parse request early so tick routing can happen before default tick behavior.
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$input = array_merge($_GET, $_POST, $input);

/**
 * Anything that changes state must arrive as POST.
 *
 * Every action was reachable by plain GET, with parameters taken from the
 * query string - verified: a bare
 *   GET /api/?action=chat_send&channel=GLOBAL&content=...
 * stored a message. The session cookie is SameSite=Lax, which withholds it
 * from cross-site subresources but SENDS it on top-level navigations, so a
 * link or a redirect on any page was enough to act as whoever clicked it.
 * Against an Admin that reaches admin_role_update.
 *
 * Requiring POST is what closes it, and it closes it completely here rather
 * than only partly: Lax already withholds the cookie from cross-site POSTs,
 * so an attacker page can neither navigate (wrong method) nor submit a form
 * (no cookie). No token to mint, rotate or leak.
 *
 * The list below is the read-only surface, and it is an allowlist on purpose:
 * an action added later is POST-only until someone deliberately decides it is
 * safe to fetch, which is the correct default to fail to. The rebuilt client
 * POSTs everything already, so nothing in the app changes.
 */
const TMC_GET_SAFE_ACTIONS = [
    'game_state', 'season_detail', 'leaderboard', 'global_leaderboard',
    'boost_catalog', 'active_boosts', 'sigil_drops', 'cosmetic_catalog',
    'my_cosmetics', 'chat_messages', 'notifications_list', 'profile',
    'my_badges', 'season_history', 'friends_list', 'friend_requests_list',
    'blocks_list', 'family_state', 'season_events', 'account_get',
    'star_purchase_preview', 'boost_activate_preview', 'sigil_theft_preview',
    'rate_limit_diagnostics',
];

$requestMethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($action !== '' && $requestMethod !== 'POST' && $requestMethod !== 'OPTIONS'
    && !in_array($action, TMC_GET_SAFE_ACTIONS, true)) {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode([
        'error' => 'This action requires POST',
        'reason_code' => 'method_not_allowed',
    ]);
    exit;
}

// Initialize server state if needed
$db = Database::getInstance();
$serverState = $db->fetch("SELECT * FROM server_state WHERE id = 1");
if (!$serverState) {
    $yearSeed = random_bytes(32);
    $db->query(
        "INSERT INTO server_state (id, server_mode, lifecycle_phase, current_year_seq, global_tick_index)
         VALUES (1, 'NORMAL', 'Release', 1, ?)",
        [GameTime::now()]
    );
    $db->query(
        "INSERT INTO yearly_state (year_seq, year_seed, started_at) VALUES (1, ?, ?)",
        [$yearSeed, GameTime::now()]
    );
}

// Dedicated scheduler endpoint: invoke with action=tick and a valid tick secret.
if ($action === 'tick') {
    $providedTickSecret = $input['tick_secret'] ?? ($_SERVER['HTTP_X_TICK_SECRET'] ?? '');

    if (TMC_TICK_SECRET === '') {
        http_response_code(503);
        echo json_encode(['error' => 'Tick endpoint is not configured']);
        exit;
    }

    if (!hash_equals(TMC_TICK_SECRET, (string)$providedTickSecret)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }

    try {
        $summary = TickEngine::processTicks();
        echo json_encode([
            'ok' => true,
            'server_now' => GameTime::now(),
            'tick_summary' => $summary
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Tick processing failed']);
        error_log("Tick endpoint error: " . $e->getMessage());
    }
    exit;
}

// Optional fallback: process ticks on normal API requests.
if (TMC_TICK_ON_REQUEST) {
    try {
        TickEngine::processTicks();
    } catch (Exception $e) {
        // Don't fail API calls due to tick processing errors
        error_log("Tick error: " . $e->getMessage());
    }
}

// Maintenance lockdown gate. Sits AFTER the tick paths - the game world keeps
// running under maintenance, only play is blocked - and BEFORE the switch so
// it covers every action without touching the cases. Staff pass through
// (non-exiting isStaff check); everyone else gets read-only + auth actions so
// the construction page can render and staff can sign in to bypass. The
// break-glass toggle is plain SQL: UPDATE server_state SET server_mode =
// 'MAINTENANCE_LOCKDOWN'|'NORMAL' WHERE id = 1 (or the staff_server_mode
// action below).
$serverMode = (string)($serverState['server_mode'] ?? 'NORMAL');
if ($serverMode === 'MAINTENANCE_LOCKDOWN') {
    $maintenanceAllowed = [
        'login', 'logout', 'register',
        'game_state', 'season_detail', 'leaderboard', 'global_leaderboard',
        'boost_catalog', 'cosmetic_catalog', 'profile',
        'rate_limit_diagnostics', 'runtime_readiness',
        'staff_server_mode',
    ];
    if (!in_array($action, $maintenanceAllowed, true)) {
        $gatePlayer = Auth::getCurrentPlayer();
        if (!$gatePlayer || !Permissions::isStaff($gatePlayer)) {
            http_response_code(503);
            echo json_encode([
                'error' => 'The game is under maintenance.',
                'reason_code' => 'maintenance_lockdown',
                'server_mode' => 'MAINTENANCE_LOCKDOWN',
            ]);
            exit;
        }
    }
}

/**
 * Convert a MySQL DATETIME string ("YYYY-MM-DD HH:MM:SS") to an ISO 8601
 * UTC string ("YYYY-MM-DDTHH:MM:SS+00:00") for unambiguous JS Date parsing.
 * Returns null for null/empty input.
 */
function iso_utc_datetime(?string $dt): ?string {
    if ($dt === null || $dt === '') return null;
    return str_replace(' ', 'T', $dt) . '+00:00';
}

try {
    switch ($action) {
        // ==================== AUTH ====================
        case 'register':
            echo json_encode(Auth::register(
                $input['handle'] ?? '',
                $input['email'] ?? '',
                $input['password'] ?? ''
            ));
            break;
            
        case 'login':
            echo json_encode(Auth::login(
                $input['email'] ?? '',
                $input['password'] ?? ''
            ));
            break;
            
        case 'logout':
            echo json_encode(Auth::logout());
            break;

        case 'rate_limit_diagnostics':
            if (!tmc_rate_limit_diagnostics_authorized($input)) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden']);
                break;
            }

            echo json_encode([
                'ok' => true,
                'server_now' => GameTime::now(),
                'action' => $rateAction,
                'rate_limit' => [
                    'tier' => $rateTier,
                    'window_seconds' => (int)$rateWindow,
                    'limit' => (int)$rateLimit,
                    'count' => (int)$rateData['count'],
                    'remaining' => max(0, (int)$rateLimit - (int)$rateData['count']),
                    'identity_hash' => hash('sha256', $rateIdentity),
                    'identity_kind' => $hasSessionIdentity ? 'session' : 'ip',
                ],
                'client' => [
                    'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null,
                    'resolved_ip' => tmc_resolve_client_ip(),
                    'xff_present' => isset($_SERVER['HTTP_X_FORWARDED_FOR']),
                    'xri_present' => isset($_SERVER['HTTP_X_REAL_IP']),
                    'cfci_present' => isset($_SERVER['HTTP_CF_CONNECTING_IP']),
                    'proxy_trusted' => tmc_proxy_is_trusted($_SERVER['REMOTE_ADDR'] ?? ''),
                ],
                'config' => [
                    'trace_enabled' => (bool)TMC_RATE_LIMIT_TRACE,
                    'trace_sample_pct' => (int)TMC_RATE_LIMIT_TRACE_SAMPLE_PCT,
                    'diagnostics_enabled' => (bool)TMC_RATE_LIMIT_DIAGNOSTICS,
                    'trust_proxy_headers' => (bool)TMC_TRUST_PROXY_HEADERS,
                    'trusted_proxies' => TMC_TRUSTED_PROXIES,
                    'anon_limit_per_window' => (int)TMC_RATE_LIMIT_ANON_PER_WINDOW,
                    'auth_limit_per_window' => (int)TMC_RATE_LIMIT_AUTH_PER_WINDOW,
                ],
            ]);
            break;

        case 'runtime_readiness':
            if (!tmc_rate_limit_diagnostics_authorized($input)) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden']);
                break;
            }

            $observeSeconds = isset($input['observe_seconds']) ? max(0, min(30, (int)$input['observe_seconds'])) : 0;
            echo json_encode([
                'ok' => true,
                'report' => tmc_runtime_readiness_report($db, ['observe_seconds' => $observeSeconds]),
            ]);
            break;
            
        // ==================== GAME STATE ====================
        case 'game_state':
            $player = Auth::getCurrentPlayer();
            echo json_encode(getGameState($player));
            break;
            
        case 'season_detail':
            $player = Auth::getCurrentPlayer();
            $seasonId = (int)($input['season_id'] ?? 0);
            echo json_encode(getSeasonDetail($player, $seasonId));
            break;
            
        case 'leaderboard':
            // Season standings are combat intelligence, not a public scoreboard.
            //
            // Anonymously this returned every participant's exact coin balance,
            // income rate, freeze state, boost percentage and online flag - the
            // whole season in one unauthenticated request. getProfile was
            // hardened against precisely that ("so it cannot be scraped
            // anonymously into a census of the whole season") and this was the
            // way around it.
            //
            // Requiring a session also lets the payload be trimmed by viewer,
            // which is what getLeaderboard now does.
            $player = Auth::requireAuth();
            $seasonId = (int)($input['season_id'] ?? 0);
            $limit = isset($input['limit']) ? (int)$input['limit'] : 0;
            echo json_encode(getLeaderboard($seasonId, $limit, $player));
            break;

        case 'global_leaderboard':
            // Ranked global stars only, but it still enumerates every account
            // with presence flags, and the Ranks screen behind it is
            // login-only anyway.
            Auth::requireAuth();
            echo json_encode(getGlobalLeaderboard());
            break;
            
        // ==================== SEASON ACTIONS ====================
        case 'season_join':
            $player = Auth::requireAuth();
            $seasonId = (int)($input['season_id'] ?? 0);
            echo json_encode(Actions::seasonJoin($player['player_id'], $seasonId));
            break;
            
        case 'star_purchase_preview':
            $player = Auth::requireAuth();
            echo json_encode(previewStarPurchase($player, (int)($input['stars_requested'] ?? 0)));
            break;

        case 'purchase_stars':
            $player = Auth::requireAuth();
            $starsRequested = (int)($input['stars_requested'] ?? 0);
            $confirmed = !empty($input['confirm_economic_impact']);
            $result = gatedStarPurchase($player, $starsRequested, $confirmed);
            echo json_encode($result);
            break;

        case 'combine_sigil':
            $player = Auth::requireAuth();
            $fromTier = (int)($input['from_tier'] ?? 0);
            $familyCode = isset($input['family']) ? (string)$input['family'] : null;
            // Easter egg, deliberately undocumented: explicitly combining the
            // Legion (wild) family routes to the critical-mass awakening
            // instead of an ascend. Auto-selected combines (no family sent)
            // keep their behavior, including wild auto-substitution.
            if ($familyCode !== null && strtolower(trim($familyCode)) === 'wild') {
                echo json_encode(FamilyActions::legionAwaken($player['player_id'], $fromTier));
                break;
            }
            echo json_encode(Actions::combineSigils($player['player_id'], $fromTier, $familyCode));
            break;

        case 'combine_all_sigils':
            $player = Auth::requireAuth();
            echo json_encode(Actions::combineAllSigils($player['player_id']));
            break;

        // ==================== SIGIL FAMILIES ====================
        case 'family_state':
            $player = Auth::requireAuth();
            echo json_encode(FamilyActions::familyState($player['player_id']));
            break;

        case 'affinity_pick':
            $player = Auth::requireAuth();
            echo json_encode(FamilyActions::pickAffinity($player['player_id'], (string)($input['family'] ?? '')));
            break;

        case 'ward_activate':
            $player = Auth::requireAuth();
            echo json_encode(FamilyActions::activateWard($player['player_id'], (int)($input['tier'] ?? 0)));
            break;

        case 'market_prime':
            $player = Auth::requireAuth();
            echo json_encode(FamilyActions::primeMarket($player['player_id'], (int)($input['tier'] ?? 0)));
            break;

        case 'sight_reveal':
            $player = Auth::requireAuth();
            echo json_encode(FamilyActions::sightReveal(
                $player['player_id'],
                (string)($input['kind'] ?? ''),
                (int)($input['target_player_id'] ?? 0)
            ));
            break;

        case 'transmute_sigils':
            $player = Auth::requireAuth();
            echo json_encode(FamilyActions::transmuteSigils(
                $player['player_id'],
                (int)($input['tier'] ?? 0),
                is_array($input['families'] ?? null) ? $input['families'] : []
            ));
            break;

        case 'distil_sigils':
            $player = Auth::requireAuth();
            echo json_encode(FamilyActions::distilSigils(
                $player['player_id'],
                (int)($input['tier'] ?? 0),
                (string)($input['target_family'] ?? '')
            ));
            break;

        case 'season_events':
            $player = Auth::requireAuth();
            echo json_encode(FamilyActions::seasonEvents($player['player_id'], (int)($input['limit'] ?? 25)));
            break;

        case 'freeze_player_ubi':
            $player = Auth::requireAuth();
            echo json_encode(Actions::freezePlayerUbi(
                $player['player_id'],
                (int)($input['target_player_id'] ?? 0),
                // target_handle is no longer accepted. Combined with the
                // leaderboard handing out every handle, it let a twenty-line
                // script freeze-lock a named rival without ever opening the UI.
                // Targeting is by player_id from the profile surface only.
                null,
                isset($input['requested_tier']) ? (int)$input['requested_tier'] : null
            ));
            break;

        case 'self_melt_freeze':
            $player = Auth::requireAuth();
            echo json_encode(Actions::selfMeltFreeze(
                $player['player_id'],
                isset($input['requested_tier']) ? (int)$input['requested_tier'] : null
            ));
            break;

        case 'sigil_theft_preview':
            $player = Auth::requireAuth();
            echo json_encode(previewSigilTheft(
                $player,
                (int)($input['target_player_id'] ?? 0),
                normalizeSigilCounts($input['spent_sigils'] ?? [0,0,0,0,0,0]),
                normalizeSigilCounts($input['requested_sigils'] ?? [0,0,0,0,0,0])
            ));
            break;

        case 'sigil_theft_attempt':
            $player = Auth::requireAuth();
            $confirmed = !empty($input['confirm_economic_impact']);
            $result = gatedSigilTheftAttempt(
                $player,
                (int)($input['target_player_id'] ?? 0),
                normalizeSigilCounts($input['spent_sigils'] ?? [0,0,0,0,0,0]),
                normalizeSigilCounts($input['requested_sigils'] ?? [0,0,0,0,0,0]),
                $confirmed
            );
            echo json_encode($result);
            break;
            
        case 'lock_in':
            $player = Auth::requireAuth();
            echo json_encode(Actions::lockIn($player['player_id']));
            break;
            
        case 'idle_ack':
            $player = Auth::requireAuth();
            echo json_encode(Actions::idleAck($player['player_id']));
            break;
            
        // ==================== BOOSTS ====================
        case 'boost_catalog':
            echo json_encode(getBoostCatalog());
            break;
            
        case 'boost_activate_preview':
            $player = Auth::requireAuth();
            $boostId = (int)($input['boost_id'] ?? 0);
            $sigilTier = resolveBoostSigilTierFromInput($input, $boostId);
            echo json_encode(previewBoostActivate(
                $player,
                $sigilTier,
                (string)($input['purchase_kind'] ?? 'power'),
                $boostId
            ));
            break;

        case 'purchase_boost':
            $player = Auth::requireAuth();
            $boostId = (int)($input['boost_id'] ?? 0);
            $sigilTier = resolveBoostSigilTierFromInput($input, $boostId);
            $purchaseKind = (string)($input['purchase_kind'] ?? 'power');
            $confirmed = !empty($input['confirm_economic_impact']);
            $result = gatedBoostActivate($player, $sigilTier, $purchaseKind, $confirmed, $boostId);
            echo json_encode($result);
            break;
            
        case 'active_boosts':
            $player = Auth::requireAuth();
            echo json_encode(getActiveBoosts($player));
            break;
            
        case 'sigil_drops':
            $player = Auth::requireAuth();
            echo json_encode(getRecentSigilDrops($player));
            break;

        // ==================== ACCOUNT ====================
        case 'account_get':
            $player = Auth::requireAuth();
            echo json_encode(['success' => true, 'account' => AccountService::getAccount($player)]);
            break;

        case 'account_update':
            $player = Auth::requireAuth();
            echo json_encode(AccountService::updateAccount($player, $input));
            break;

        case 'account_change_password':
            $player = Auth::requireAuth();
            echo json_encode(AccountService::changePassword($player, $input));
            break;

        case 'account_delete_request':
            $player = Auth::requireAuth();
            echo json_encode(AccountService::requestSelfDeletion($player, trim((string)($input['reason'] ?? 'Self-requested deletion'))));
            break;

        case 'account_delete_confirm':
            echo json_encode(AccountService::confirmSelfDeletion((string)($input['token'] ?? '')));
            break;

        case 'staff_account_delete_request':
            $actor = Permissions::requireStaff();
            echo json_encode(AccountService::requestStaffDeletion(
                $actor,
                (int)($input['target_player_id'] ?? 0),
                trim((string)($input['reason'] ?? 'Staff-requested deletion'))
            ));
            break;

        case 'staff_account_delete_confirm':
            $actor = Permissions::requireStaff();
            echo json_encode(AccountService::confirmStaffDeletion($actor, (string)($input['token'] ?? '')));
            break;

        // ==================== NOTIFICATIONS ====================
        case 'notifications_list':
            $player = Auth::requireAuth();
            $limit = (int)($input['limit'] ?? 50);
            echo json_encode([
                'success' => true,
                'notifications' => Notifications::listForPlayer($player['player_id'], $limit),
                'unread_count' => Notifications::unreadCount($player['player_id'])
            ]);
            break;

        case 'notifications_mark_read':
            $player = Auth::requireAuth();
            $ids = getNotificationIdsFromInput($input);
            $updated = Notifications::markRead($player['player_id'], $ids);
            echo json_encode([
                'success' => true,
                'updated' => $updated,
                'unread_count' => Notifications::unreadCount($player['player_id'])
            ]);
            break;

        case 'notifications_mark_all_read':
            $player = Auth::requireAuth();
            $updated = Notifications::markAllRead($player['player_id']);
            echo json_encode([
                'success' => true,
                'updated' => $updated,
                'unread_count' => 0
            ]);
            break;

        case 'notifications_remove':
            $player = Auth::requireAuth();
            $ids = getNotificationIdsFromInput($input);
            $removed = Notifications::remove($player['player_id'], $ids);
            echo json_encode([
                'success' => true,
                'removed' => $removed,
                'unread_count' => Notifications::unreadCount($player['player_id'])
            ]);
            break;

        // notifications_create is deliberately gone.
        //
        // It let any authenticated player write an arbitrary notification into
        // their own feed with a category, title and body of their choosing -
        // so a player could manufacture a notice indistinguishable from one
        // the server sends, in the same feed that carries real moderation
        // warnings, theft alerts and staff broadcasts. Nothing legitimate ever
        // called it: notifications are written by the tick engine and the game
        // actions, and staff have staff_notifications_send_player /
        // staff_notifications_send_all, which are permission-gated and audited.
        //
        // Removing it rather than staff-gating it, because a staff-gated
        // duplicate of two actions that already exist is just a third way in.

        // ==================== SOCIAL ====================
        case 'friends_list':
            $player = Auth::requireAuth();
            echo json_encode(['success' => true, 'friends' => SocialService::friendsList((int)$player['player_id'])]);
            break;

        case 'friend_requests_list':
            $player = Auth::requireAuth();
            echo json_encode(['success' => true, 'requests' => SocialService::requestsList((int)$player['player_id'])]);
            break;

        case 'friend_request_send':
            $player = Auth::requireAuth();
            echo json_encode(SocialService::sendRequest((int)$player['player_id'], (int)($input['target_player_id'] ?? 0)));
            break;

        case 'friend_request_respond':
            $player = Auth::requireAuth();
            echo json_encode(SocialService::respondRequest((int)$player['player_id'], (int)($input['request_id'] ?? 0), (string)($input['decision'] ?? '')));
            break;

        case 'friend_remove':
            $player = Auth::requireAuth();
            echo json_encode(SocialService::removeFriend((int)$player['player_id'], (int)($input['target_player_id'] ?? 0)));
            break;

        case 'blocks_list':
            $player = Auth::requireAuth();
            echo json_encode(['success' => true, 'blocks' => SocialService::blocksList((int)$player['player_id'])]);
            break;

        case 'block_add':
            $player = Auth::requireAuth();
            echo json_encode(SocialService::blockAdd((int)$player['player_id'], (int)($input['target_player_id'] ?? 0)));
            break;

        case 'block_remove':
            $player = Auth::requireAuth();
            echo json_encode(SocialService::blockRemove((int)$player['player_id'], (int)($input['target_player_id'] ?? 0)));
            break;

        // ==================== STAFF ====================
        case 'staff_users_search':
            $actor = Permissions::requireStaff();
            echo json_encode(['success' => true, 'users' => ModerationService::searchUsers($actor, (string)($input['query'] ?? ''))]);
            break;

        case 'staff_user_get':
            $actor = Permissions::requireStaff();
            echo json_encode(ModerationService::getUser($actor, (int)($input['target_player_id'] ?? 0)));
            break;

        case 'staff_user_update':
            $actor = Permissions::requireStaff();
            echo json_encode(ModerationService::updateUser($actor, (int)($input['target_player_id'] ?? 0), $input));
            break;

        case 'staff_server_mode':
            // Admin-gated maintenance toggle. Only the two modes the gate
            // understands are accepted; the other server_mode ENUM values are
            // reserved and rejected until something reads them.
            $actor = Permissions::requireAdmin();
            $requestedMode = strtoupper(trim((string)($input['mode'] ?? '')));
            if (!in_array($requestedMode, ['NORMAL', 'MAINTENANCE_LOCKDOWN'], true)) {
                echo json_encode(['error' => 'mode must be NORMAL or MAINTENANCE_LOCKDOWN']);
                break;
            }
            $db->query("UPDATE server_state SET server_mode = ? WHERE id = 1", [$requestedMode]);
            Audit::record(
                (int)$actor['player_id'], null, 'staff_server_mode',
                trim((string)($input['reason'] ?? 'Server mode change')),
                ['server_mode' => (string)($serverState['server_mode'] ?? 'NORMAL')],
                ['server_mode' => $requestedMode]
            );
            echo json_encode(['success' => true, 'server_mode' => $requestedMode]);
            break;

        case 'admin_role_update':
            $actor = Permissions::requireAdmin();
            echo json_encode(AdminService::updateRole(
                $actor,
                (int)($input['target_player_id'] ?? 0),
                (string)($input['role'] ?? ''),
                trim((string)($input['reason'] ?? 'Admin role update'))
            ));
            break;

        case 'admin_economy_reset_global':
            $actor = Permissions::requireAdmin();
            echo json_encode(AdminService::globalEconomyReset(
                $actor,
                (string)($input['confirmation'] ?? ''),
                trim((string)($input['reason'] ?? 'Admin global economy reset'))
            ));
            break;

        case 'admin_economy_reset_player':
            $actor = Permissions::requireAdmin();
            echo json_encode(AdminService::playerEconomyReset(
                $actor,
                (int)($input['target_player_id'] ?? 0),
                (string)($input['confirmation'] ?? ''),
                trim((string)($input['reason'] ?? 'Admin player economy reset'))
            ));
            break;

        case 'staff_chat_start':
            $actor = Permissions::requireStaff();
            echo json_encode(StaffChatService::startThread(
                $actor,
                (int)($input['target_player_id'] ?? 0),
                (string)($input['subject'] ?? ''),
                (string)($input['body'] ?? $input['message'] ?? '')
            ));
            break;

        case 'staff_chat_threads':
            $player = Auth::requireAuth();
            echo json_encode(['success' => true, 'threads' => StaffChatService::listThreads($player)]);
            break;

        case 'staff_chat_messages':
            $player = Auth::requireAuth();
            echo json_encode(StaffChatService::getMessages($player, (int)($input['thread_id'] ?? 0)));
            break;

        case 'staff_chat_send':
            $player = Auth::requireAuth();
            echo json_encode(StaffChatService::sendMessage(
                $player,
                (int)($input['thread_id'] ?? 0),
                (string)($input['body'] ?? $input['message'] ?? '')
            ));
            break;

        case 'staff_chat_remove_message':
            $actor = Permissions::requireStaff();
            echo json_encode(ModerationService::removeMessage($actor, (int)($input['message_id'] ?? 0), trim((string)($input['reason'] ?? 'Removed by staff'))));
            break;

        case 'staff_chat_mute_user':
            $actor = Permissions::requireStaff();
            echo json_encode(ModerationService::muteUser($actor, (int)($input['target_player_id'] ?? 0), (string)($input['scope'] ?? 'ALL'), isset($input['minutes']) ? (int)$input['minutes'] : null, trim((string)($input['reason'] ?? 'Muted by staff'))));
            break;

        case 'staff_chat_unmute_user':
            $actor = Permissions::requireStaff();
            echo json_encode(ModerationService::unmuteUser($actor, (int)($input['mute_id'] ?? 0), trim((string)($input['reason'] ?? 'Unmuted by staff'))));
            break;

        case 'staff_notifications_send_player':
            $actor = Permissions::requireStaff();
            $targetId = (int)($input['target_player_id'] ?? 0);
            $target = getActiveNotificationTarget($targetId);
            if (!$target) {
                echo json_encode(['error' => 'Target player not found']);
                break;
            }

            $notice = normalizeStaffNotificationInput($input);
            if (!empty($notice['error'])) {
                echo json_encode($notice);
                break;
            }

            $notificationId = Notifications::create(
                $targetId,
                $notice['category'],
                $notice['title'],
                $notice['body'],
                $notice['options']
            );
            Audit::record(
                (int)$actor['player_id'],
                $targetId,
                'staff_notification_send',
                $notice['title'],
                null,
                ['notification_id' => $notificationId, 'category' => $notice['category']]
            );
            echo json_encode(['success' => true, 'notification_id' => $notificationId]);
            break;

        case 'staff_notifications_send_all':
            $actor = Permissions::requireStaff();
            $notice = normalizeStaffNotificationInput($input);
            if (!empty($notice['error'])) {
                echo json_encode($notice);
                break;
            }

            $count = Notifications::createForAll(
                $notice['category'],
                $notice['title'],
                $notice['body'],
                $notice['options']
            );
            Audit::record(
                (int)$actor['player_id'],
                null,
                'staff_notification_broadcast',
                $notice['title'],
                null,
                ['count' => $count, 'category' => $notice['category']]
            );
            echo json_encode(['success' => true, 'count' => $count]);
            break;
            
        // ==================== COSMETICS ====================
        case 'cosmetic_catalog':
            echo json_encode(getCosmeticCatalog());
            break;
            
        case 'purchase_cosmetic':
            $player = Auth::requireAuth();
            echo json_encode(Actions::purchaseCosmetic(
                $player['player_id'],
                (int)($input['cosmetic_id'] ?? 0)
            ));
            break;
            
        case 'equip_cosmetic':
            $player = Auth::requireAuth();
            echo json_encode(Actions::equipCosmetic(
                $player['player_id'],
                (int)($input['cosmetic_id'] ?? 0),
                (bool)($input['equip'] ?? true)
            ));
            break;
            
        case 'my_cosmetics':
            $player = Auth::requireAuth();
            echo json_encode(getMyCosmetics($player));
            break;
            
        // ==================== CHAT ====================
        case 'chat_send':
            $player = Auth::requireAuth();
            echo json_encode(sendChat($player, $input));
            break;
            
        case 'chat_messages':
            // Reading chat requires a session. It did not, so the entire
            // global transcript - every handle, every message - was readable
            // by anyone who could reach the endpoint, and the block list
            // could not be applied because there was no viewer to apply it
            // for. The client already presents chat as players-only ("Chat is
            // for players / Log in to read and post"); this makes the server
            // agree rather than relying on the client to keep the secret.
            $player = Auth::requireAuth();
            echo json_encode(getChatMessages($player, $input));
            break;
            
        // ==================== PROFILE ====================
        case 'profile':
            $targetId = (int)($input['player_id'] ?? 0);
            $player = Auth::getCurrentPlayer();
            echo json_encode(getProfile($player, $targetId));
            break;
            
        case 'my_badges':
            $player = Auth::requireAuth();
            echo json_encode(getMyBadges($player));
            break;
            
        case 'season_history':
            $player = Auth::requireAuth();
            echo json_encode(getSeasonHistory($player));
            break;
            
        default:
            echo json_encode(['error' => 'Unknown action', 'available_actions' => [
                'register', 'login', 'logout', 'game_state', 'season_detail', 'leaderboard',
                'global_leaderboard', 'season_join', 'purchase_stars',
                'combine_sigil', 'combine_all_sigils', 'freeze_player_ubi', 'self_melt_freeze',
                'sigil_theft_preview', 'sigil_theft_attempt',
                'lock_in', 'idle_ack', 'boost_catalog', 'purchase_boost', 'active_boosts',
                'sigil_drops', 'cosmetic_catalog',
                'purchase_cosmetic', 'equip_cosmetic', 'my_cosmetics', 'chat_send',
                'account_get', 'account_update', 'account_change_password',
                'account_delete_request', 'account_delete_confirm',
                'staff_account_delete_request', 'staff_account_delete_confirm',
                'chat_messages', 'notifications_list', 'notifications_mark_read',
                'notifications_mark_all_read', 'notifications_remove',
                'friends_list', 'friend_requests_list', 'friend_request_send',
                'friend_request_respond', 'friend_remove', 'blocks_list',
                'block_add', 'block_remove',
                'staff_users_search', 'staff_user_get', 'staff_user_update',
                'admin_role_update', 'admin_economy_reset_global', 'admin_economy_reset_player',
                'staff_chat_start', 'staff_chat_threads', 'staff_chat_messages',
                'staff_chat_send', 'staff_chat_remove_message', 'staff_chat_mute_user',
                'staff_chat_unmute_user', 'staff_notifications_send_player',
                'staff_notifications_send_all',
                'profile', 'my_badges', 'season_history', 'tick',
                'star_purchase_preview', 'boost_activate_preview',
                'rate_limit_diagnostics'
            ]]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
    error_log('API error: ' . $e->__toString());
}

// ==================== HELPER FUNCTIONS ====================

function tableExists(Database $db, string $tableName): bool {
    static $cache = [];

    if (isset($cache[$tableName])) {
        return $cache[$tableName];
    }

    $row = $db->fetch(
        "SELECT COUNT(*) AS c
         FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?",
        [$tableName]
    );

    $cache[$tableName] = ((int)($row['c'] ?? 0)) > 0;
    return $cache[$tableName];
}

function getSigilDropRateMetadata($sigilPower = 0) {
    $sigilPower = max(0, (int)$sigilPower);
    $dropRate = Economy::sigilDropRateForPower($sigilPower);
    $basePercent = 100 / max(1, (int)$dropRate);
    $tierOdds = Economy::adjustedSigilTierOdds($sigilPower);
    $tiers = [];
    foreach ($tierOdds as $tier => $oddsFp) {
        $conditionalPercent = ((int)$oddsFp / 1000000) * 100;
        $effectivePercent = $basePercent * ((int)$oddsFp / 1000000);
        $tiers[] = [
            'tier' => (int)$tier,
            'odds_fp' => (int)$oddsFp,
            'chance_percent' => round($effectivePercent, 6),
            'conditional_percent' => round($conditionalPercent, 2)
        ];
    }

    return [
        'sigil_power' => $sigilPower,
        'base_one_in' => (int)$dropRate,
        'base_percent' => round($basePercent, 6),
        'tiers' => $tiers
    ];
}

/**
 * Build the sigil_drop_rates payload from a pre-computed per-player drop config.
 * Uses the full dynamic model (inventory-aware scaling + boost-pressure) rather
 * than the simpler sigil-power scalar used by getSigilDropRateMetadata().
 *
 * @param array $dropConfig Return value of Economy::computePerPlayerSigilDropConfig().
 * @return array
 */
function getSigilDropRateMetadataFromConfig(array $dropConfig) {
    $dropRate = max(1, (int)$dropConfig['drop_rate']);
    $tierOdds = $dropConfig['tier_odds'];
    $basePercent = 100.0 / $dropRate;
    $tiers = [];
    foreach ($tierOdds as $tier => $oddsFp) {
        $oddsFp = (int)$oddsFp;
        $conditionalPercent = ($oddsFp / 1000000) * 100;
        $effectivePercent = $basePercent * ($oddsFp / 1000000);
        $tiers[] = [
            'tier' => (int)$tier,
            'odds_fp' => $oddsFp,
            'chance_percent' => round($effectivePercent, 6),
            'conditional_percent' => round($conditionalPercent, 6),
        ];
    }
    return [
        'base_one_in' => $dropRate,
        'base_percent' => round($basePercent, 6),
        'tiers' => $tiers,
    ];
}

function calculatePlayerRatePerTick($season, $player, $participation, $activeBoosts) {
    if (!$season || !$participation) {
        return [
            'rate_per_tick' => 0,
            'gross_rate_per_tick' => 0,
            'hoarding_sink_per_tick' => 0,
            'net_rate_per_tick' => 0,
            'hoarding_sink_active' => false,
        ];
    }
    if (isPlayerFrozen((int)$player['player_id'], (int)$season['season_id'], $participation)) {
        return [
            'rate_per_tick' => 0,
            'gross_rate_per_tick' => 0,
            'hoarding_sink_per_tick' => 0,
            'net_rate_per_tick' => 0,
            'hoarding_sink_active' => false,
        ];
    }

    $totalModFp = (int)($activeBoosts['total_modifier_fp'] ?? 0);
    $gameTime = GameTime::now();
    $seasonForRates = $season;
    $seasonForRates['computed_status'] = $seasonForRates['computed_status'] ?? GameTime::getSeasonStatus($seasonForRates, $gameTime);
    $playerForRates = $player;
    $playerForRates['current_game_time'] = $gameTime;
    $rates = Economy::calculateRateBreakdown($seasonForRates, $playerForRates, $participation, $totalModFp, false);

    $grossRate = round(((int)$rates['gross_rate_fp']) / FP_SCALE, 2);
    // Report the sink at the same precision as gross and net. The real sink is
    // a fraction of a coin per tick at production cadence, so the whole-coin
    // view rounded it to 0 - which also meant hoarding_sink_active was always
    // false and the client could never show the sink as engaged.
    $sinkRateFp = max(0, (int)($rates['sink_rate_fp'] ?? 0));
    $sinkRate = round($sinkRateFp / FP_SCALE, 2);
    $netRate = round(((int)$rates['net_rate_fp']) / FP_SCALE, 2);

    return [
        // Preserve legacy key as player-facing gross rate.
        'rate_per_tick' => max(0, $grossRate),
        'gross_rate_per_tick' => max(0, $grossRate),
        'hoarding_sink_per_tick' => max(0, $sinkRate),
        'net_rate_per_tick' => max(0, $netRate),
        'hoarding_sink_active' => Economy::hoardingSinkEnabled($season) && $sinkRateFp > 0,
    ];
}

function calculateLeaderboardRowMetrics($season, array $row): array {
    $boostCapFp = (int)BoostCatalog::TOTAL_POWER_CAP_FP;
    $boostModFp = max(0, min((int)($row['boost_mod_fp'] ?? 0), $boostCapFp));
    $isFrozen = ((int)($row['is_frozen'] ?? 0) > 0);
    $gameTime = GameTime::now();
    $seasonForRates = $season;
    $seasonForRates['computed_status'] = $seasonForRates['computed_status'] ?? GameTime::getSeasonStatus($seasonForRates, $gameTime);

    $playerShim = [
        'participation_enabled' => 1,
        'activity_state' => $row['activity_state'] ?? 'Offline',
        'current_game_time' => $gameTime,
    ];
    $participationShim = [
        'coins' => (int)($row['coins'] ?? 0),
        'participation_time_total' => (int)($row['participation_time_total'] ?? 0),
    ];

    $breakdown = Economy::calculateRateBreakdown(
        $seasonForRates,
        $playerShim,
        $participationShim,
        $boostModFp,
        $isFrozen
    );

    return [
        'rate_per_tick' => round(((int)$breakdown['gross_rate_fp']) / FP_SCALE, 2),
        'boost_pct' => round($boostModFp / 10000, 1),
        'boost_mod_fp' => $boostModFp,
    ];
}

function getGameState($player) {
    $db = Database::getInstance();
    $gameTime = GameTime::now();
    
    $state = [
        'server_now' => $gameTime,
        'global_tick_index' => $gameTime,
        'server_mode' => 'NORMAL',
        'lifecycle_phase' => 'Release',
        'timing' => get_timing_diagnostics(),
        'seasons' => [],
        'player' => null
    ];

    // Publish when the last tick actually fired, not just how long a tick is.
    //
    // tick_real_seconds alone gives a client the period but not the phase, so a
    // countdown built from it hits zero at an arbitrary offset from when coins
    // land and teaches players a rhythm that is wrong. With this, the countdown
    // is real. Clients that cannot see it are expected to show the cadence as a
    // static fact rather than guess.
    //
    // The column is written by TickEngine on every processed tick and has
    // existed since the original schema, so this is a read, not a migration.
    // Null before the first tick of a fresh install, and after a reset that
    // clears server_state - both of which the client already handles.
    //
    // Two fields rather than one, deliberately:
    //
    //   last_tick_at     absolute epoch ms. Converted by MySQL via
    //                    UNIX_TIMESTAMP rather than by PHP's strtotime, which
    //                    would read the DATETIME in PHP's timezone while NOW()
    //                    wrote it in MySQL's. When those differ the epoch comes
    //                    out silently wrong by whole hours.
    //
    //   tick_age_seconds how long ago that was. This is what a countdown should
    //                    actually be built from: the client derives its own
    //                    local timestamp as (its now - age), so a device with a
    //                    misset clock still counts down correctly. Comparing an
    //                    absolute server epoch against a wrong Date.now() does
    //                    not, and phone clocks drift.
    $tickHeartbeat = $db->fetch(
        "SELECT UNIX_TIMESTAMP(last_tick_processed_at) AS last_tick_epoch,
                TIMESTAMPDIFF(SECOND, last_tick_processed_at, NOW()) AS tick_age_seconds,
                server_mode
         FROM server_state WHERE id = 1"
    );
    // Publish the REAL mode (this was a hardcoded 'NORMAL' literal for as
    // long as the column existed) so clients can render the maintenance gate.
    $state['server_mode'] = (string)($tickHeartbeat['server_mode'] ?? 'NORMAL');
    // Whether the sigil-family layer is live — the client gates the Family
    // rail entry on this rather than discovering it by a failed fetch.
    $state['families_enabled'] = SigilFamilies::active($db);
    $lastTickEpoch = $tickHeartbeat['last_tick_epoch'] ?? null;
    $state['timing']['last_tick_at'] = $lastTickEpoch !== null ? (int)$lastTickEpoch * 1000 : null;
    $state['timing']['tick_age_seconds'] = $tickHeartbeat['tick_age_seconds'] !== null
        ? (int)$tickHeartbeat['tick_age_seconds']
        : null;
    
    // Get visible seasons
    $seasons = GameTime::getVisibleSeasons();
    foreach ($seasons as &$s) {
        $s['computed_status'] = GameTime::getSeasonStatus($s);
        applySeasonCountdownFields($s, $gameTime);
        applyPublishedStarPrice($s);
        $endTime = (int)$s['end_time'];
        $blackoutTime = (int)$s['blackout_time'];
        $s['blackout_remaining'] = max(0, $blackoutTime - $gameTime);
        $s['is_blackout'] = ($gameTime >= $blackoutTime && $gameTime < $endTime);

        $s['sigil_drop_rates'] = getSigilDropRateMetadata();
        
        // Remove binary seed from response
        unset($s['season_seed']);
    }
    $state['seasons'] = $seasons;
    
    if ($player) {
        $participation = null;
        $joinedSeason = null;
        if ($player['joined_season_id']) {
            $joinedSeason = $db->fetch(
                "SELECT * FROM seasons WHERE season_id = ?",
                [$player['joined_season_id']]
            );
            $participation = $db->fetch(
                "SELECT * FROM season_participation WHERE player_id = ? AND season_id = ?",
                [$player['player_id'], $player['joined_season_id']]
            );
        }

        // Progression gates: null = ungated (flag off, old-client compatible).
        // When enabled, an empty unlock list for a player whose participation
        // proves prior sight gets a one-time lazy backfill, so flipping the
        // flag on can never take UI away from an established player.
        $playerUnlocks = null;
        if (Progression::enabled($db)) {
            $playerUnlocks = Progression::keysForPlayer($db, (int)$player['player_id']);
            if (empty($playerUnlocks) && $participation) {
                Progression::backfillFromParticipation($db, (int)$player['player_id'], $participation);
                $playerUnlocks = Progression::keysForPlayer($db, (int)$player['player_id']);
            }
        }

        $activeBoosts = getActiveBoosts($player, $participation);
        $rateMetrics = calculatePlayerRatePerTick($joinedSeason, $player, $participation, $activeBoosts);
        $joinedSeasonStatus = $joinedSeason ? GameTime::getSeasonStatus($joinedSeason, $gameTime) : null;
        $freezeStatus = getFreezeStatusForPlayer((int)$player['player_id'], (int)$player['joined_season_id'], $participation);
        $theftStatus = getTheftStatusForPlayer((int)$player['player_id'], (int)$player['joined_season_id'], $participation);
        
        $state['player'] = [
            'player_id' => $player['player_id'],
            'handle' => $player['handle'],
            'role' => $player['role'],
            'global_stars' => (int)$player['global_stars'],
            'global_stars_progress' => getGlobalStarsProgressPayload($player['global_stars_fractional_fp'] ?? 0),
            'joined_season_id' => $player['joined_season_id'],
            'participation_enabled' => (bool)$player['participation_enabled'],
            'idle_modal_active' => (bool)$player['idle_modal_active'],
            'activity_state' => $player['activity_state'],
            'participation' => $participation ? [
                'coins' => (int)$participation['coins'],
                'seasonal_stars' => (int)$participation['seasonal_stars'],
                'effective_seasonal_stars' => Economy::effectiveSeasonalStars($participation),
                'sigils' => [
                    (int)$participation['sigils_t1'],
                    (int)$participation['sigils_t2'],
                    (int)$participation['sigils_t3'],
                    (int)$participation['sigils_t4'],
                    (int)$participation['sigils_t5'],
                    (int)($participation['sigils_t6'] ?? 0)
                ],
                'sigils_total' => (int)$participation['sigils_t1'] + (int)$participation['sigils_t2'] + (int)$participation['sigils_t3'] + (int)$participation['sigils_t4'] + (int)$participation['sigils_t5'] + (int)($participation['sigils_t6'] ?? 0),
                'sigil_caps' => [
                    'total' => (int)SIGIL_INVENTORY_TOTAL_CAP,
                    'tiers' => [
                        1 => (int)Economy::getSigilTierCap(1),
                        2 => (int)Economy::getSigilTierCap(2),
                        3 => (int)Economy::getSigilTierCap(3),
                        4 => (int)Economy::getSigilTierCap(4),
                        5 => (int)Economy::getSigilTierCap(5),
                        6 => (int)Economy::getSigilTierCap(6),
                    ],
                ],
                'participation_time' => (int)$participation['participation_time_total'],
                'active_ticks' => (int)$participation['active_ticks_total'],
                'lock_in_stars' => $participation['lock_in_snapshot_seasonal_stars'],
                // The starter-hand offset deducted from the sigil refund at
                // lock-in — published so the lock-in panel can disclose it at
                // the moment it applies.
                'starter_grant_stars' => (int)($participation['starter_grant_stars'] ?? 0),
                'sigil_drops_total' => (int)($participation['sigil_drops_total'] ?? 0),
                'eligible_ticks_since_last_drop' => (int)($participation['eligible_ticks_since_last_drop'] ?? 0),
                'combine_recipes' => getCombineRecipesForParticipation($participation),
                'tier6_visible' => shouldRevealTier6($participation),
                'can_freeze' => in_array($joinedSeasonStatus, ['Active', 'Blackout'], true)
                    && playerHasSigilInTiers($participation, SIGIL_FREEZE_SPEND_TIERS),
                'can_melt' => in_array($joinedSeasonStatus, ['Active', 'Blackout'], true)
                    && ($freezeStatus['is_frozen'] ?? false)
                    && (((int)($participation['sigils_t5'] ?? 0) > 0) || ((int)($participation['sigils_t6'] ?? 0) > 0)),
                'can_steal' => in_array($joinedSeasonStatus, ['Active', 'Blackout'], true)
                    && !($theftStatus['is_on_cooldown'] ?? false)
                    && playerHasSigilInTiers($participation, SIGIL_THEFT_SPEND_TIERS),
                'freeze' => $freezeStatus,
                'theft' => $theftStatus,
                'rate_per_tick' => (float)$rateMetrics['rate_per_tick'],
                'gross_rate_per_tick' => (float)$rateMetrics['gross_rate_per_tick'],
                'hoarding_sink_per_tick' => (float)$rateMetrics['hoarding_sink_per_tick'],
                'net_rate_per_tick' => (float)$rateMetrics['net_rate_per_tick'],
                'hoarding_sink_active' => (bool)$rateMetrics['hoarding_sink_active'],
            ] : null,
            'unlocks' => $playerUnlocks,
            'season_event' => ($player['joined_season_id'] && SigilFamilies::active($db))
                ? SigilFamilies::modifierEventPayload(
                    SigilFamilies::activeModifierEvent($db, (int)$player['joined_season_id'], $gameTime)
                )
                : null,
            'active_boosts' => $activeBoosts,
            'equipped_cosmetics' => getEquippedCosmeticsForPlayerIds([(int)$player['player_id']])[(int)$player['player_id']] ?? getEmptyEquippedCosmeticsPayload(),
            'recent_drops' => ($player['joined_season_id']) ? getRecentSigilDrops($player, $participation) : [],
            'notifications' => Notifications::listForPlayer($player['player_id'], 50),
            'notifications_unread_count' => Notifications::unreadCount($player['player_id']),
            'can_lock_in' => canLockIn($player, $participation),
            'can_purchase_stars' => canPurchaseStars($player),
        ];
    }
    
    return $state;
}

function gameTicksToRealSeconds($gameTicks) {
    if ($gameTicks <= 0) return 0;
    $scale = max(1, (int)TIME_SCALE);
    return max(0, intdiv(((int)$gameTicks) * (int)TICK_REAL_SECONDS, $scale));
}

function applySeasonCountdownFields(&$season, $gameTime) {
    $status = $season['computed_status'] ?? GameTime::getSeasonStatus($season);
    $startTime = (int)$season['start_time'];
    $endTime = (int)$season['end_time'];

    if ($status === 'Scheduled') {
        $remaining = max(0, $startTime - $gameTime);
        $mode = 'scheduled';
        $label = 'Begins in';
    } elseif ($status === 'Active' || $status === 'Blackout') {
        $remaining = max(0, $endTime - $gameTime);
        $mode = 'running';
        $label = 'Time Left';
    } else {
        $remaining = 0;
        $mode = 'ended';
        $label = 'Ended';
    }

    $season['time_remaining'] = $remaining;
    $season['time_remaining_real_seconds'] = gameTicksToRealSeconds($remaining);
    $season['time_remaining_formatted'] = GameTime::formatTimeRemaining($remaining);
    $season['countdown_mode'] = $mode;
    $season['countdown_label'] = $label;
}

function applyPublishedStarPrice(&$season) {
    if (!$season || !is_array($season)) {
        return;
    }

    $status = $season['computed_status'] ?? $season['status'] ?? null;
    $season['published_star_price'] = Economy::publishedStarPrice($season, $status);
}

function seasonEffectiveScoreSql(string $alias = 'sp'): string {
    return "CASE
        WHEN {$alias}.lock_in_effect_tick IS NOT NULL THEN COALESCE({$alias}.lock_in_snapshot_seasonal_stars, 0)
        WHEN COALESCE({$alias}.end_membership, 0) = 1 THEN COALESCE({$alias}.final_seasonal_stars, {$alias}.seasonal_stars, 0)
        ELSE COALESCE({$alias}.seasonal_stars, 0)
    END";
}

function normalizeParticipationScorePayload(array $participation): array {
    $participation['effective_seasonal_stars'] = Economy::effectiveSeasonalStars($participation);
    $participation['payout_seasonal_stars'] = Economy::settlementPayoutSeasonalStars($participation);
    $participation['payout_bonus_stars'] = max(0, (int)($participation['participation_bonus'] ?? 0))
        + max(0, (int)($participation['placement_bonus'] ?? 0));
    $participation['payout_source_stars'] = Economy::settlementPayoutSourceStars($participation);
    return $participation;
}

function normalizeParticipationScorePayloads(array $rows): array {
    foreach ($rows as &$row) {
        if (is_array($row)) {
            $row = normalizeParticipationScorePayload($row);
        }
    }
    unset($row);

    return $rows;
}

function canLockIn($player, $participation) {
    if (!$player['participation_enabled'] || !$player['joined_season_id']) return false;
    if ($player['idle_modal_active']) return false;
    if (!$participation) return false;
    if ($participation['participation_ticks_since_join'] < MIN_PARTICIPATION_TICKS) return false;
    
    $db = Database::getInstance();
    $season = $db->fetch("SELECT * FROM seasons WHERE season_id = ?", [$player['joined_season_id']]);
    $status = GameTime::getSeasonStatus($season);
    return ($status === 'Active' || $status === 'Blackout');
}

function canPurchaseStars($player) {
    if (!$player['participation_enabled'] || !$player['joined_season_id']) return false;
    if ($player['idle_modal_active']) return false;

    $db = Database::getInstance();
    $season = $db->fetch("SELECT * FROM seasons WHERE season_id = ?", [$player['joined_season_id']]);
    $status = GameTime::getSeasonStatus($season);
    return ($status === 'Active' || $status === 'Blackout');
}

function getSeasonRunStartTick($playerId, $seasonId, $participation = null) {
    if (!$seasonId) {
        return 0;
    }

    if (is_array($participation)) {
        return max(0, (int)($participation['first_joined_at'] ?? 0));
    }

    $db = Database::getInstance();
    $row = $db->fetch(
        "SELECT first_joined_at
         FROM season_participation
         WHERE player_id = ? AND season_id = ?",
        [(int)$playerId, (int)$seasonId]
    );

    return max(0, (int)($row['first_joined_at'] ?? 0));
}

function getEmptyTheftStatus() {
    return [
        'is_on_cooldown' => false,
        'cooldown_expires_tick' => null,
        'cooldown_remaining_ticks' => 0,
        'cooldown_expires_at_real' => null,
        'cooldown_remaining_real_seconds' => 0,
        'is_protected' => false,
        'protection_expires_tick' => null,
        'protection_remaining_ticks' => 0,
        'protection_expires_at_real' => null,
        'protection_remaining_real_seconds' => 0,
    ];
}

function getTheftStatusForPlayer($playerId, $seasonId, $participation = null) {
    if (!$seasonId) {
        return getEmptyTheftStatus();
    }

    $db = Database::getInstance();
    if (!tableExists($db, 'sigil_theft_attempts')) {
        return getEmptyTheftStatus();
    }

    $gameTime = GameTime::now();
    $serverNowUnix = time();
    $runStartTick = getSeasonRunStartTick($playerId, $seasonId, $participation);
    $row = $db->fetch(
        "SELECT
            MAX(CASE WHEN attacker_player_id = ? THEN cooldown_expires_tick ELSE NULL END) AS cooldown_expires_tick,
            MAX(CASE WHEN target_player_id = ? THEN protection_expires_tick ELSE NULL END) AS protection_expires_tick
         FROM sigil_theft_attempts
         WHERE season_id = ? AND (attacker_player_id = ? OR target_player_id = ?) AND created_tick >= ?",
        [(int)$playerId, (int)$playerId, (int)$seasonId, (int)$playerId, (int)$playerId, (int)$runStartTick]
    );

    $cooldownTick = max(0, (int)($row['cooldown_expires_tick'] ?? 0));
    $protectionTick = max(0, (int)($row['protection_expires_tick'] ?? 0));
    // An active windowed Ward extends effective protection (families). The
    // one-shot deflect is excluded on purpose - it blocks silently at attempt
    // time and must not be advertised here (see windowedWardExpiresTick).
    $protectionTick = max($protectionTick, SigilFamilies::windowedWardExpiresTick($db, (int)$playerId, (int)$seasonId));
    $cooldownActive = $cooldownTick >= $gameTime;
    $protectionActive = $protectionTick >= $gameTime;
    $cooldownExpiresAtReal = $cooldownActive ? GameTime::tickStartRealUnix($cooldownTick + 1) : null;
    $protectionExpiresAtReal = $protectionActive ? GameTime::tickStartRealUnix($protectionTick + 1) : null;

    return [
        'is_on_cooldown' => $cooldownActive,
        'cooldown_expires_tick' => $cooldownActive ? $cooldownTick : null,
        'cooldown_remaining_ticks' => $cooldownActive ? max(0, $cooldownTick - $gameTime) : 0,
        'cooldown_expires_at_real' => $cooldownExpiresAtReal,
        'cooldown_remaining_real_seconds' => $cooldownActive && $cooldownExpiresAtReal !== null
            ? max(0, $cooldownExpiresAtReal - $serverNowUnix)
            : 0,
        'is_protected' => $protectionActive,
        'protection_expires_tick' => $protectionActive ? $protectionTick : null,
        'protection_remaining_ticks' => $protectionActive ? max(0, $protectionTick - $gameTime) : 0,
        'protection_expires_at_real' => $protectionExpiresAtReal,
        'protection_remaining_real_seconds' => $protectionActive && $protectionExpiresAtReal !== null
            ? max(0, $protectionExpiresAtReal - $serverNowUnix)
            : 0,
    ];
}

function getSeasonDetail($player, $seasonId) {
    $db = Database::getInstance();
    $season = $db->fetch("SELECT * FROM seasons WHERE season_id = ?", [$seasonId]);
    if (!$season) return ['error' => 'Season not found'];
    $effectiveScoreSql = seasonEffectiveScoreSql('sp');
    
    $gameTime = GameTime::now();
    $season['computed_status'] = GameTime::getSeasonStatus($season);
    applySeasonCountdownFields($season, $gameTime);
    applyPublishedStarPrice($season);

    $season['sigil_drop_rates'] = getSigilDropRateMetadata();

    if ($player && (int)$player['joined_season_id'] === (int)$seasonId && (int)$player['participation_enabled'] === 1) {
        $participation = $db->fetch(
            "SELECT * FROM season_participation WHERE player_id = ? AND season_id = ?",
            [(int)$player['player_id'], (int)$seasonId]
        );
        if ($participation) {
            $dropConfig = Economy::computePerPlayerSigilDropConfig($participation, 0);
            $season['sigil_drop_rates'] = getSigilDropRateMetadataFromConfig($dropConfig);
            $season['player_combine_recipes'] = getCombineRecipesForParticipation($participation);
            $season['player_tier6_visible'] = shouldRevealTier6($participation);
            $playerFreeze = getFreezeStatusForPlayer((int)$player['player_id'], (int)$seasonId, $participation);
            $playerTheft = getTheftStatusForPlayer((int)$player['player_id'], (int)$seasonId, $participation);
            $seasonStatus = (string)($season['computed_status'] ?? GameTime::getSeasonStatus($season, $gameTime));
            $season['player_can_freeze'] = in_array($seasonStatus, ['Active', 'Blackout'], true)
                && playerHasSigilInTiers($participation, SIGIL_FREEZE_SPEND_TIERS);
            $season['player_can_melt'] = in_array($seasonStatus, ['Active', 'Blackout'], true)
                && ($playerFreeze['is_frozen'] ?? false)
                && (((int)($participation['sigils_t5'] ?? 0) > 0) || ((int)($participation['sigils_t6'] ?? 0) > 0));
            $season['player_can_steal'] = in_array($seasonStatus, ['Active', 'Blackout'], true)
                && !($playerTheft['is_on_cooldown'] ?? false)
                && playerHasSigilInTiers($participation, SIGIL_THEFT_SPEND_TIERS);
            $season['player_freeze'] = $playerFreeze;
            $season['player_theft'] = $playerTheft;
        }
    }
    
    // Top players
    if ($season['computed_status'] === 'Active' || $season['computed_status'] === 'Blackout') {
        // Bounded like every other standings query. This branch had no LIMIT,
        // so one request against a busy season returned every participant -
        // the same unbounded enumeration LEADERBOARD_MAX_LIMIT was introduced
        // to stop, just on a different code path.
        $season['leaderboard'] = $db->fetchAll(
            "SELECT sp.player_id, p.handle,
                    {$effectiveScoreSql} AS seasonal_stars,
                    {$effectiveScoreSql} AS effective_seasonal_stars,
                    sp.lock_in_effect_tick
             FROM season_participation sp
             JOIN players p ON p.player_id = sp.player_id
             WHERE sp.season_id = ?
             ORDER BY {$effectiveScoreSql} DESC, sp.player_id ASC
             LIMIT ?",
            [$seasonId, (int)LEADERBOARD_MAX_LIMIT]
        );
    } else {
        $season['leaderboard'] = $db->fetchAll(
            "SELECT sp.player_id, p.handle, {$effectiveScoreSql} AS seasonal_stars, {$effectiveScoreSql} AS effective_seasonal_stars, sp.lock_in_effect_tick
             FROM season_participation sp
             JOIN players p ON p.player_id = sp.player_id
             WHERE sp.season_id = ?
             ORDER BY {$effectiveScoreSql} DESC, sp.player_id ASC
             LIMIT 50",
            [$seasonId]
        );
    }
    $season['leaderboard'] = normalizeParticipationScorePayloads($season['leaderboard']);
    
    // Player count
    $livePlayerCount = (int)$db->fetch(
        "SELECT COUNT(*) as cnt FROM players WHERE joined_season_id = ? AND participation_enabled = 1",
        [$seasonId]
    )['cnt'];
    if ($livePlayerCount > 0) {
        $season['player_count'] = $livePlayerCount;
    } else {
        $season['player_count'] = (int)$db->fetch(
            "SELECT COUNT(*) as cnt FROM season_participation WHERE season_id = ?",
            [$seasonId]
        )['cnt'];
    }
    
    unset($season['season_seed']);
    return $season;
}

/**
 * Strip another player's private state from a standings row.
 *
 * A leaderboard answers "who is ahead". It does not need to answer "how many
 * coins does that player have right now, what is their income rate, are they
 * frozen, and are they at the keyboard" - which is targeting information for
 * theft and freezes, and was being served for the whole season at once.
 *
 * Your own row keeps everything, because none of it is a secret from you.
 */
function redactLeaderboardRowForViewer(array $row, ?int $viewerId): array {
    if ($viewerId !== null && (int)($row['player_id'] ?? 0) === $viewerId) {
        return $row;
    }
    foreach (['coins', 'rate_per_tick', 'boost_pct', 'is_frozen', 'participation_time_total'] as $private) {
        unset($row[$private]);
    }
    return $row;
}

function getLeaderboard($seasonId, int $limit = 0, $viewer = null) {
    $db = Database::getInstance();
    $viewerId = is_array($viewer) && isset($viewer['player_id']) ? (int)$viewer['player_id'] : null;
    $season = $db->fetch("SELECT * FROM seasons WHERE season_id = ?", [$seasonId]);
    if (!$season) return [];
    $effectiveScoreSql = seasonEffectiveScoreSql('sp');
    // limit=0 (or an omitted limit) used to mean NO LIMIT clause at all, so a
    // single request could return every participant in the season. Zero now
    // means "the maximum", which is what callers passing 0 actually intend -
    // the expand-leaderboard toggle in the client passes limit: 0 for "show
    // everyone" and gets the capped set instead of an unbounded scan.
    $limit = (int)$limit;
    $limit = ($limit <= 0) ? (int)LEADERBOARD_MAX_LIMIT : min((int)LEADERBOARD_MAX_LIMIT, $limit);
    $limitClause = " LIMIT ?";

    $status = GameTime::getSeasonStatus($season);
    if ($status === 'Active' || $status === 'Blackout') {
        $gameTime = GameTime::now();
        $boostCapFp = (int)BoostCatalog::TOTAL_POWER_CAP_FP;
        $boostTimeCapTicks = BoostCatalog::getTimeCapTicks();
        $hasBoostTables = tableExists($db, 'active_boosts');
        $hasFreezeTable = tableExists($db, 'active_freezes');

        if ($hasBoostTables && $hasFreezeTable) {
            $rows = $db->fetchAll(
                "SELECT sp.player_id, p.handle,
                        {$effectiveScoreSql} AS seasonal_stars,
                    {$effectiveScoreSql} AS effective_seasonal_stars,
                        COALESCE(sp.coins, 0) AS coins,
                        sp.participation_time_total,
                        sp.final_rank,
                        sp.lock_in_effect_tick,
                        COALESCE(sp.end_membership, 0) AS end_membership,
                        sp.badge_awarded,
                        COALESCE(sp.global_stars_earned, 0) AS global_stars_earned,
                        COALESCE(sp.participation_bonus, 0) AS participation_bonus,
                        COALESCE(sp.placement_bonus, 0) AS placement_bonus,
                        p.activity_state, p.online_current,
                        COALESCE(frz.is_frozen, 0) AS is_frozen,
                           LEAST(COALESCE(self_b.self_fp, 0), ?) AS boost_mod_fp
                       FROM season_participation sp
                       JOIN players p ON p.player_id = sp.player_id
                 LEFT JOIN (
                     SELECT player_id, SUM(modifier_fp) AS self_fp
                     FROM active_boosts
                     WHERE season_id = ? AND is_active = 1 AND scope = 'SELF' AND expires_tick >= ?
                       AND (activated_tick <= 0 OR activated_tick + ? >= ?)
                     GROUP BY player_id
                 ) self_b ON self_b.player_id = p.player_id
                 LEFT JOIN (
                     SELECT target_player_id AS player_id, 1 AS is_frozen
                     FROM active_freezes
                     WHERE season_id = ? AND is_active = 1 AND expires_tick >= ?
                     GROUP BY target_player_id
                 ) frz ON frz.player_id = p.player_id
                 WHERE sp.season_id = ?
                 ORDER BY {$effectiveScoreSql} DESC, sp.player_id ASC{$limitClause}",
                [$boostCapFp, $seasonId, $gameTime, $boostTimeCapTicks, $gameTime, $seasonId, $gameTime, $seasonId, $limit]
            );
        } elseif ($hasFreezeTable) {
            $rows = $db->fetchAll(
                "SELECT sp.player_id, p.handle,
                        {$effectiveScoreSql} AS seasonal_stars,
                    {$effectiveScoreSql} AS effective_seasonal_stars,
                        COALESCE(sp.coins, 0) AS coins,
                        sp.participation_time_total,
                        sp.final_rank,
                        sp.lock_in_effect_tick,
                        COALESCE(sp.end_membership, 0) AS end_membership,
                        sp.badge_awarded,
                        COALESCE(sp.global_stars_earned, 0) AS global_stars_earned,
                        COALESCE(sp.participation_bonus, 0) AS participation_bonus,
                        COALESCE(sp.placement_bonus, 0) AS placement_bonus,
                        p.activity_state, p.online_current,
                        COALESCE(frz.is_frozen, 0) AS is_frozen,
                        0.0 AS boost_pct,
                        0 AS boost_mod_fp
                 FROM season_participation sp
                 JOIN players p ON p.player_id = sp.player_id
                 LEFT JOIN (
                     SELECT target_player_id AS player_id, 1 AS is_frozen
                     FROM active_freezes
                     WHERE season_id = ? AND is_active = 1 AND expires_tick >= ?
                     GROUP BY target_player_id
                 ) frz ON frz.player_id = p.player_id
                 WHERE sp.season_id = ?
                 ORDER BY {$effectiveScoreSql} DESC, sp.player_id ASC{$limitClause}",
                [$seasonId, $gameTime, $seasonId, $limit]
            );
        } else {
            $rows = $db->fetchAll(
                "SELECT sp.player_id, p.handle,
                        {$effectiveScoreSql} AS seasonal_stars,
                    {$effectiveScoreSql} AS effective_seasonal_stars,
                        COALESCE(sp.coins, 0) AS coins,
                        sp.participation_time_total,
                        sp.final_rank,
                        sp.lock_in_effect_tick,
                        COALESCE(sp.end_membership, 0) AS end_membership,
                        sp.badge_awarded,
                        COALESCE(sp.global_stars_earned, 0) AS global_stars_earned,
                        COALESCE(sp.participation_bonus, 0) AS participation_bonus,
                        COALESCE(sp.placement_bonus, 0) AS placement_bonus,
                        p.activity_state, p.online_current,
                        0 AS is_frozen,
                        0.0 AS boost_pct,
                        0 AS boost_mod_fp
                 FROM season_participation sp
                 JOIN players p ON p.player_id = sp.player_id
                 WHERE sp.season_id = ?
                 ORDER BY {$effectiveScoreSql} DESC, sp.player_id ASC{$limitClause}",
                [$seasonId, $limit]
            );
        }
        $rows = attachEquippedCosmeticsToRows($rows);
        $rows = normalizeParticipationScorePayloads($rows);
        foreach ($rows as &$row) {
            $metrics = calculateLeaderboardRowMetrics($season, $row);
            $row['rate_per_tick'] = (float)$metrics['rate_per_tick'];
            $row['boost_pct'] = (float)$metrics['boost_pct'];
            unset($row['boost_mod_fp']);
            $row = redactLeaderboardRowForViewer($row, $viewerId);
        }
        unset($row);
        return $rows;
    }

    $rows = $db->fetchAll(
        "SELECT sp.player_id, p.handle, {$effectiveScoreSql} AS seasonal_stars, {$effectiveScoreSql} AS effective_seasonal_stars, COALESCE(sp.coins, 0) AS coins, sp.final_rank,
                sp.lock_in_effect_tick, sp.end_membership, sp.badge_awarded,
                sp.global_stars_earned, sp.participation_bonus, sp.placement_bonus,
                p.activity_state, p.online_current,
                0 AS is_frozen,
                0.0 AS boost_pct
         FROM season_participation sp
         JOIN players p ON p.player_id = sp.player_id
         WHERE sp.season_id = ?
         ORDER BY {$effectiveScoreSql} DESC, sp.player_id ASC{$limitClause}",
        [$seasonId, $limit]
    );
    $rows = attachEquippedCosmeticsToRows($rows);
    $rows = normalizeParticipationScorePayloads($rows);
    foreach ($rows as &$row) {
        $row['rate_per_tick'] = 0;
        $row = redactLeaderboardRowForViewer($row, $viewerId);
    }
    unset($row);
    return $rows;
}

function normalizeSigilCounts($value): array {
    if (!is_array($value)) return [0, 0, 0, 0, 0, 0];
    $normalized = [0, 0, 0, 0, 0, 0];
    for ($i = 0; $i < 6; $i++) {
        $normalized[$i] = max(0, (int)($value[$i] ?? 0));
    }
    return $normalized;
}

function sumSigilCounts(array $counts, ?array $tiers = null): int {
    if ($tiers === null) {
        return array_sum($counts);
    }

    $total = 0;
    foreach ($tiers as $tier) {
        $index = (int)$tier - 1;
        if ($index >= 0 && $index < 6) {
            $total += max(0, (int)($counts[$index] ?? 0));
        }
    }

    return $total;
}

function calculateSigilCountsValue(array $counts, array $valueTable): int {
    $total = 0;
    for ($tier = 1; $tier <= 6; $tier++) {
        $total += max(0, (int)($counts[$tier - 1] ?? 0)) * max(0, (int)($valueTable[$tier] ?? 0));
    }
    return $total;
}

function playerHasSigilInTiers(array $participation, array $tiers): bool {
    foreach ($tiers as $tier) {
        $tier = (int)$tier;
        if ($tier >= 1 && $tier <= 6 && (int)($participation['sigils_t' . $tier] ?? 0) > 0) {
            return true;
        }
    }
    return false;
}

function formatSigilTierList(array $tiers): string {
    $labels = [];
    foreach ($tiers as $tier) {
        $tier = (int)$tier;
        if ($tier >= 1 && $tier <= 6) {
            $labels[] = 'Tier ' . $tier;
        }
    }
    $labels = array_values(array_unique($labels));
    $count = count($labels);
    if ($count === 0) return 'configured-tier';
    if ($count === 1) return $labels[0];
    if ($count === 2) return $labels[0] . ' or ' . $labels[1];
    $last = array_pop($labels);
    return implode(', ', $labels) . ', or ' . $last;
}

function getGlobalStarsProgressPayload($fractionalFp): array {
    $carryFp = max(0, (int)$fractionalFp);
    return [
        'fractional_fp' => $carryFp,
        'progress_percent' => round(($carryFp / FP_SCALE) * 100, 1),
    ];
}

function getEmptyEquippedCosmeticsPayload(): array {
    return [
        'avatar_frame' => null,
        'name_color' => null,
        'profile_bg' => null,
        'title' => null,
        'effect' => null,
    ];
}

function normalizeEquippedCosmeticRow(array $row): array {
    return [
        'cosmetic_id' => (int)$row['cosmetic_id'],
        'name' => (string)$row['name'],
        'category' => (string)$row['category'],
        'css_class' => (string)($row['css_class'] ?? ''),
        'description' => (string)($row['description'] ?? ''),
        'price_global_stars' => (int)($row['price_global_stars'] ?? 0),
    ];
}

function getEquippedCosmeticsForPlayerIds(array $playerIds): array {
    $ids = array_values(array_unique(array_map('intval', $playerIds)));
    if (empty($ids)) {
        return [];
    }

    $db = Database::getInstance();
    $placeholders = implode(', ', array_fill(0, count($ids), '?'));
    $rows = $db->fetchAll(
        "SELECT pc.player_id, c.cosmetic_id, c.name, c.description, c.category, c.price_global_stars, c.css_class
         FROM player_cosmetics pc
         JOIN cosmetic_catalog c ON c.cosmetic_id = pc.cosmetic_id
         WHERE pc.equipped = 1 AND pc.player_id IN ({$placeholders})",
        $ids
    );

    $payload = [];
    foreach ($ids as $playerId) {
        $payload[$playerId] = getEmptyEquippedCosmeticsPayload();
    }

    foreach ($rows as $row) {
        $playerId = (int)$row['player_id'];
        $category = (string)$row['category'];
        if (!isset($payload[$playerId])) {
            $payload[$playerId] = getEmptyEquippedCosmeticsPayload();
        }
        if (array_key_exists($category, $payload[$playerId])) {
            $payload[$playerId][$category] = normalizeEquippedCosmeticRow($row);
        }
    }

    return $payload;
}

function attachEquippedCosmeticsToRows(array $rows): array {
    $playerIds = [];
    foreach ($rows as $row) {
        $playerIds[] = (int)($row['player_id'] ?? 0);
    }

    $equippedByPlayer = getEquippedCosmeticsForPlayerIds($playerIds);
    foreach ($rows as &$row) {
        $playerId = (int)($row['player_id'] ?? 0);
        $row['equipped_cosmetics'] = $equippedByPlayer[$playerId] ?? getEmptyEquippedCosmeticsPayload();
    }
    unset($row);

    return $rows;
}

function getGlobalLeaderboard() {
    $db = Database::getInstance();
    // Ranked on LIFETIME EARNED, not the spendable balance.
    //
    // Ordering by global_stars meant buying a cosmetic - the only Global Star
    // sink - permanently lowered your rank, so the prestige shop cost the
    // prestige metric one-for-one. global_stars_lifetime is monotonic, so the
    // leaderboard now measures achievement and cosmetics are a real reward.
    // Falls back to the balance ordering when the lifetime column is not yet
    // present, so this code can deploy ahead of its migration without taking
    // the leaderboard down.
    if ($db->columnExists('players', 'global_stars_lifetime')) {
        $rows = $db->fetchAll(
            "SELECT player_id, handle, global_stars, global_stars_lifetime, activity_state, online_current
             FROM players
             WHERE global_stars_lifetime > 0 AND profile_deleted_at IS NULL
             ORDER BY global_stars_lifetime DESC, player_id ASC"
        );
    } else {
        $rows = $db->fetchAll(
            "SELECT player_id, handle, global_stars, global_stars AS global_stars_lifetime,
                    activity_state, online_current
             FROM players
             WHERE global_stars > 0 AND profile_deleted_at IS NULL
             ORDER BY global_stars DESC, player_id ASC"
        );
    }
    return attachEquippedCosmeticsToRows($rows);
}

function getCosmeticCatalog() {
    $db = Database::getInstance();
    return $db->fetchAll("SELECT * FROM cosmetic_catalog ORDER BY price_global_stars ASC, name ASC");
}

function getMyCosmetics($player) {
    $db = Database::getInstance();
    return $db->fetchAll(
        "SELECT c.*, pc.equipped, pc.purchased_at
         FROM player_cosmetics pc
         JOIN cosmetic_catalog c ON c.cosmetic_id = pc.cosmetic_id
         WHERE pc.player_id = ?
         ORDER BY pc.equipped DESC, pc.purchased_at DESC",
        [$player['player_id']]
    );
}

function sendChat($player, $input) {
    $db = Database::getInstance();
    $channelKind = strtoupper($input['channel'] ?? 'GLOBAL');
    $content = trim($input['content'] ?? '');
    $seasonId = $input['season_id'] ?? null;
    $recipientId = $input['recipient_id'] ?? null;
    
    if (empty($content)) return ['error' => 'Message cannot be empty'];
    if (strlen($content) > CHAT_MAX_LENGTH) return ['error' => 'Message too long'];

    // Channel validation
    //
    // The kind is checked against the set that has a read path BEFORE the
    // insert. Previously any string reached the INSERT: channel_kind is an
    // ENUM, so an unknown kind either raised a driver error or, on a
    // permissive server, stored as '' - a message accepted from the player,
    // charged against their mute state, and then readable by nobody. Failing
    // it by name is the honest answer.
    if ($channelKind !== 'GLOBAL' && $channelKind !== 'SEASON' && $channelKind !== 'DM') {
        return ['error' => 'Unknown chat channel', 'reason_code' => 'unknown_channel'];
    }
    if ($channelKind === 'SEASON') {
        if (!$player['joined_season_id']) return ['error' => 'Not in a season'];
        $seasonId = $player['joined_season_id'];
    }
    // DMs are closed.
    //
    // sendChat accepted, stored and indexed them, but getChatMessages has no DM
    // branch and returns [] - so every DM ever sent is unreadable by its
    // recipient and invisible to moderation, since ModerationService works on
    // message ids nobody can see to report. That is a private, unmoderatable
    // channel between players, which is strictly worse than no channel.
    //
    // Reopening this means building the read path with block filtering, a
    // mailbox surface, and a way for staff to action reports. Until then it is
    // rejected rather than silently swallowed.
    if ($channelKind === 'DM') {
        return ['error' => 'Direct messages are not available', 'reason_code' => 'dm_disabled'];
    }

    $muteScope = $channelKind === 'SEASON' ? 'SEASON' : 'GLOBAL';
    $mute = ModerationService::isMuted((int)$player['player_id'], $muteScope);
    if ($mute) {
        return ['error' => 'You are muted in this chat', 'mute' => $mute];
    }
    
    $db->query(
        "INSERT INTO chat_messages (channel_kind, season_id, sender_id, recipient_id, handle_snapshot, content)
         VALUES (?, ?, ?, ?, ?, ?)",
        [$channelKind, $seasonId, $player['player_id'], $recipientId, $player['handle'], $content]
    );
    
    return ['success' => true];
}

function getChatMessages($player, $input) {
    $db = Database::getInstance();
    $channelKind = strtoupper($input['channel'] ?? 'GLOBAL');

    // Only the two channels with a read path exist. Anything else - a typo, a
    // probe, or the DM kind that is deliberately closed - is refused by name
    // rather than falling through to an empty list that looks like an outage.
    if ($channelKind !== 'GLOBAL' && $channelKind !== 'SEASON') {
        return ['error' => 'Unknown chat channel', 'reason_code' => 'unknown_channel'];
    }

    // The season transcript is bound to the viewer's own participation, and a
    // client-supplied season_id is ignored outright.
    //
    // It used to be honoured, which made every season's chat world-readable:
    // passing season_id=N returned that season's full transcript to anyone,
    // including callers with no session at all. Deriving it from the player
    // is also what the send path does, so read and write now agree on which
    // season "SEASON" means.
    //
    // Staff are the one exception, because moderating a transcript requires
    // reading it, and they may not be in the season they are moderating.
    $isStaff = $player && Permissions::isStaff($player);
    $seasonId = null;
    if ($channelKind === 'SEASON') {
        if ($isStaff && !empty($input['season_id'])) {
            $seasonId = (int)$input['season_id'];
        } elseif ($player && !empty($player['joined_season_id'])) {
            $seasonId = (int)$player['joined_season_id'];
        }
    }

    $canViewRemoved = $isStaff;
    $removedSql = $canViewRemoved ? "1=1" : "is_removed = 0";

    // Blocking now hides the blocked player's messages.
    //
    // SocialService::isBlockedEitherWay had exactly one caller - sendRequest -
    // so blocking did nothing a player could perceive. Note that blocking
    // deliberately does NOT prevent being frozen or robbed: letting a player
    // block the whole leaderboard to become untouchable would be a far worse
    // exploit. The UI must say what it does, or it reads as a safety tool it
    // is not.
    $blockedSql = '';
    $blockedParams = [];
    if ($player && !$canViewRemoved) {
        $blockedIds = SocialService::blockedIds((int)$player['player_id']);
        if (!empty($blockedIds)) {
            $blockedSql = ' AND (sender_id IS NULL OR sender_id NOT IN ('
                        . implode(',', array_fill(0, count($blockedIds), '?')) . '))';
            $blockedParams = $blockedIds;
        }
    }

    if ($channelKind === 'GLOBAL') {
        $messages = $db->fetchAll(
            "SELECT message_id, sender_id, handle_snapshot, content, is_admin_post, is_removed, removed_by, removed_at, removal_reason, created_at
             FROM chat_messages 
             WHERE channel_kind = 'GLOBAL' AND {$removedSql}{$blockedSql}
             ORDER BY created_at DESC LIMIT ?",
            array_merge($blockedParams, [CHAT_MAX_ROWS])
        );
        foreach ($messages as &$m) {
            $m['created_at'] = iso_utc_datetime($m['created_at'] ?? null);
        }
        return $messages;
    }
    
    if ($channelKind === 'SEASON' && $seasonId) {
        $messages = $db->fetchAll(
            "SELECT message_id, sender_id, handle_snapshot, content, is_removed, removed_by, removed_at, removal_reason, created_at
             FROM chat_messages 
             WHERE channel_kind = 'SEASON' AND season_id = ? AND {$removedSql}{$blockedSql}
             ORDER BY created_at DESC LIMIT ?",
            array_merge([$seasonId], $blockedParams, [CHAT_MAX_ROWS])
        );
        foreach ($messages as &$m) {
            $m['created_at'] = iso_utc_datetime($m['created_at'] ?? null);
        }
        return $messages;
    }
    
    return [];
}

function getProfile($viewer, $targetId) {
    $db = Database::getInstance();
    $effectiveScoreSql = seasonEffectiveScoreSql('sp');
    $target = $db->fetch(
        "SELECT player_id, handle, role, global_stars, global_stars_fractional_fp, profile_visibility, created_at, profile_deleted_at,
            joined_season_id, participation_enabled, activity_state, online_current
         FROM players WHERE player_id = ?",
        [$targetId]
    );
    if (!$target) return ['error' => 'Player not found'];

    if ($target['profile_deleted_at']) {
        return ['player_id' => $target['player_id'], 'handle' => '[Removed]', 'deleted' => true];
    }

    // Enforce profile_visibility.
    //
    // This function previously accepted $viewer and never used it: the visibility
    // setting was selected and then ignored, so the dropdown players could set in
    // their account panel did nothing, and the whole profile - including the exact
    // per-tier sigil inventory - was readable by unauthenticated callers.
    $viewerId  = (int)($viewer['player_id'] ?? 0);
    $targetIdInt = (int)$target['player_id'];
    $viewerIsSelf  = ($viewerId > 0 && $viewerId === $targetIdInt);
    $viewerIsStaff = (is_array($viewer) && !empty($viewer) && Permissions::isStaff($viewer));
    $visibility = strtoupper((string)($target['profile_visibility'] ?? 'PUBLIC'));

    if (!$viewerIsSelf && !$viewerIsStaff) {
        $blocked = ($visibility === 'HIDDEN')
            || ($visibility === 'FRIENDS_ONLY' && !SocialService::areFriends($viewerId, $targetIdInt));
        if ($blocked) {
            return [
                'player_id'  => $targetIdInt,
                'handle'     => $target['handle'],
                'restricted' => true,
                'visibility' => $visibility,
            ];
        }
    }

    // Get badges
    $badges = $db->fetchAll(
        "SELECT * FROM badges WHERE player_id = ? ORDER BY awarded_at DESC",
        [$targetId]
    );
    
    // Get season history
    $history = $db->fetchAll(
        "SELECT sp.*, {$effectiveScoreSql} AS effective_seasonal_stars, s.start_time, s.end_time, s.status AS season_status
         FROM season_participation sp
         JOIN seasons s ON s.season_id = sp.season_id
         WHERE sp.player_id = ? AND (sp.end_membership = 1 OR sp.lock_in_effect_tick IS NOT NULL)
         ORDER BY s.start_time DESC LIMIT 20",
        [$targetId]
    );
    
    // Relationship state, so the client can render the correct social action.
    // Without it there was no way to know whether to offer Add Friend or Remove
    // Friend, Block or Unblock - which is part of why neither button was ever
    // built and the block list could only ever be empty.
    $target['relationship'] = ($viewerId > 0 && !$viewerIsSelf) ? [
        'is_friend'       => SocialService::areFriends($viewerId, $targetIdInt),
        'is_blocked'      => SocialService::hasBlocked($viewerId, $targetIdInt),
        'request_pending' => SocialService::hasPendingRequest($viewerId, $targetIdInt),
    ] : null;

    $target['badges'] = $badges;
    $target['season_history'] = normalizeParticipationScorePayloads($history);
    $target['active_participation'] = null;
    $target['global_stars_progress'] = getGlobalStarsProgressPayload($target['global_stars_fractional_fp'] ?? 0);
    $target['equipped_cosmetics'] = getEquippedCosmeticsForPlayerIds([(int)$targetId])[(int)$targetId] ?? getEmptyEquippedCosmeticsPayload();
    // Normalise DATETIME to ISO 8601 UTC so JS Date() parses it unambiguously.
    $target['created_at'] = iso_utc_datetime($target['created_at'] ?? null);

    // The live-season block below carries combat-relevant state: exact per-tier
    // sigil counts, coin balance, freeze/theft posture, and can_freeze/can_melt/
    // can_steal. Require a session for it, so it cannot be scraped anonymously
    // into a census of the whole season. (What one *logged-in* player may see of
    // another is a separate design question and is deliberately unchanged here.)
    if ($viewerId <= 0) {
        return $target;
    }

    if (!empty($target['joined_season_id']) && (int)$target['participation_enabled'] === 1) {
        $season = $db->fetch("SELECT * FROM seasons WHERE season_id = ?", [$target['joined_season_id']]);
        if ($season) {
            $status = GameTime::getSeasonStatus($season);
            if ($status === 'Active' || $status === 'Blackout') {
                $participation = $db->fetch(
                    "SELECT coins, seasonal_stars, lock_in_effect_tick, lock_in_snapshot_seasonal_stars,
                            sigils_t1, sigils_t2, sigils_t3, sigils_t4, sigils_t5, sigils_t6, first_joined_at
                     FROM season_participation
                     WHERE player_id = ? AND season_id = ?",
                    [$targetId, $target['joined_season_id']]
                );
                if ($participation) {
                    $participation = normalizeParticipationScorePayload($participation);
                    $profileBoosts = getActiveBoosts($target, $participation);
                    $profilePrimaryBoost = (isset($profileBoosts['self'][0]) && is_array($profileBoosts['self'][0]))
                        ? $profileBoosts['self'][0]
                        : null;
                    $profileFreeze = getFreezeStatusForPlayer((int)$targetId, (int)$target['joined_season_id'], $participation);
                    $profileTheft = getTheftStatusForPlayer((int)$targetId, (int)$target['joined_season_id'], $participation);
                    $target['active_participation'] = [
                        'season_id' => (int)$target['joined_season_id'],
                        'season_status' => $status,
                        'activity_state' => (string)($target['activity_state'] ?? 'Active'),
                        'coins' => (int)$participation['coins'],
                        'seasonal_stars' => (int)($participation['seasonal_stars'] ?? 0),
                        'effective_seasonal_stars' => (int)($participation['effective_seasonal_stars'] ?? 0),
                        'lock_in_stars' => isset($participation['lock_in_snapshot_seasonal_stars'])
                            ? (int)$participation['lock_in_snapshot_seasonal_stars']
                            : null,
                        'sigils' => [
                            (int)$participation['sigils_t1'],
                            (int)$participation['sigils_t2'],
                            (int)$participation['sigils_t3'],
                            (int)$participation['sigils_t4'],
                            (int)$participation['sigils_t5'],
                            (int)($participation['sigils_t6'] ?? 0)
                        ],
                        'active_boost' => [
                            'is_active' => !!$profilePrimaryBoost,
                            'boost_id' => $profilePrimaryBoost ? (int)($profilePrimaryBoost['boost_id'] ?? 0) : null,
                            'total_modifier_percent' => (float)($profileBoosts['total_modifier_percent'] ?? 0),
                            'expires_at_real' => $profilePrimaryBoost ? (int)($profilePrimaryBoost['expires_at_real'] ?? 0) : null,
                            'remaining_real_seconds' => $profilePrimaryBoost ? (int)($profilePrimaryBoost['remaining_real_seconds'] ?? 0) : 0,
                        ],
                        'freeze' => $profileFreeze,
                        'theft' => $profileTheft,
                        'can_freeze' => in_array($status, ['Active', 'Blackout'], true)
                            && playerHasSigilInTiers($participation, SIGIL_FREEZE_SPEND_TIERS),
                        'can_melt' => in_array($status, ['Active', 'Blackout'], true)
                            && ($profileFreeze['is_frozen'] ?? false)
                            && (((int)($participation['sigils_t5'] ?? 0) > 0) || ((int)($participation['sigils_t6'] ?? 0) > 0)),
                        'can_steal' => in_array($status, ['Active', 'Blackout'], true)
                            && !($profileTheft['is_on_cooldown'] ?? false)
                            && playerHasSigilInTiers($participation, SIGIL_THEFT_SPEND_TIERS),
                    ];
                }
            }
        }
    }
    
    return $target;
}

function getMyBadges($player) {
    $db = Database::getInstance();
    return $db->fetchAll(
        "SELECT b.*, s.start_time, s.end_time
         FROM badges b
         LEFT JOIN seasons s ON s.season_id = b.season_id
         WHERE b.player_id = ?
         ORDER BY b.awarded_at DESC",
        [$player['player_id']]
    );
}

function getSeasonHistory($player) {
    $db = Database::getInstance();
    $effectiveScoreSql = seasonEffectiveScoreSql('sp');
    $rows = $db->fetchAll(
        "SELECT sp.*, {$effectiveScoreSql} AS effective_seasonal_stars, s.start_time, s.end_time, s.status as season_status
         FROM season_participation sp
         JOIN seasons s ON s.season_id = sp.season_id
         WHERE sp.player_id = ?
         ORDER BY s.start_time DESC LIMIT 50",
        [$player['player_id']]
    );

    return normalizeParticipationScorePayloads($rows);
}

function getBoostCatalog() {
    $db = Database::getInstance();
    $catalog = $db->fetchAll("SELECT * FROM boost_catalog ORDER BY tier_required ASC, boost_id ASC");
    foreach ($catalog as &$boost) {
        $boost = BoostCatalog::normalize($boost);
    }
    unset($boost);
    return $catalog;
}

function resolveBoostSigilTierFromInput(array $input, int $boostId = 0): int {
    if (isset($input['sigil_tier'])) {
        return (int)$input['sigil_tier'];
    }
    if (isset($input['time_sigil_tier'])) {
        return (int)$input['time_sigil_tier'];
    }
    if ($boostId > 0) {
        $db = Database::getInstance();
        $row = $db->fetch("SELECT tier_required FROM boost_catalog WHERE boost_id = ?", [$boostId]);
        if ($row) {
            return (int)($row['tier_required'] ?? 0);
        }
    }
    return 0;
}

function getActiveBoosts($player, $participation = null) {
    $db = Database::getInstance();
    if (!$player['joined_season_id']) {
        return [
            'self' => [],
            'global' => [],
            'total_modifier_fp' => 0,
            'total_modifier_percent' => 0,
            'server_now' => GameTime::now(),
            'server_real_now' => time(),
        ];
    }
    
    $gameTime = GameTime::now();
    $serverNowUnix = time();
    $seasonId = $player['joined_season_id'];
    $runStartTick = getSeasonRunStartTick((int)$player['player_id'], (int)$seasonId, $participation);
    $timeCapTicks = BoostCatalog::getTimeCapTicks();
    
    $selfBoosts = $db->fetchAll(
        "SELECT ab.*, bc.name, bc.description, bc.tier_required, bc.icon
         FROM active_boosts ab
         JOIN boost_catalog bc ON bc.boost_id = ab.boost_id
         WHERE ab.player_id = ? AND ab.season_id = ? AND ab.is_active = 1 AND ab.scope = 'SELF' AND ab.expires_tick >= ?
           AND ab.activated_tick >= ?
           AND (ab.activated_tick <= 0 OR ab.activated_tick + ? >= ?)
         ORDER BY ab.expires_tick DESC, ab.id ASC
         LIMIT 1",
        [$player['player_id'], $seasonId, $gameTime, $runStartTick, $timeCapTicks, $gameTime]
    );
    
    // Annotate each boost with wall-clock expiry data for client-side real-time countdown.
    // expires_at_real is computed as the absolute real Unix timestamp of the start of
    // tick (expires_tick + 1) – the first moment at which gameTime exceeds expires_tick
    // and the boost is no longer active.  Using the tick-boundary formula makes this
    // value stable: it does not change between API calls, even if the game tick advances
    // between the purchase request and the subsequent active_boosts query.
    // remaining_real_seconds is derived from the same stable timestamp so it matches.
    foreach ($selfBoosts as &$b) {
        $b = BoostCatalog::normalize($b);
        $b['expires_tick'] = BoostCatalog::getEffectiveExpiresTick(
            (int)$b['expires_tick'],
            (int)($b['activated_tick'] ?? 0)
        );
        $expiresAtReal = GameTime::tickStartRealUnix((int)$b['expires_tick'] + 1);
        $b['expires_at_real'] = $expiresAtReal;
        $b['remaining_real_seconds'] = max(0, $expiresAtReal - $serverNowUnix);
    }
    unset($b);
    
    // Calculate total modifier
    $totalModFp = 0;
    foreach ($selfBoosts as $b) $totalModFp += (int)$b['modifier_fp'];
    $totalModFp = min($totalModFp, BoostCatalog::TOTAL_POWER_CAP_FP); // Cap at 500% bonus
    
    return [
        'self' => $selfBoosts,
        // Compatibility field retained, always empty under SELF-only boost rules.
        'global' => [],
        'total_modifier_fp' => $totalModFp,
        'total_modifier_percent' => round($totalModFp / 10000, 1),
        'server_now' => $gameTime,
        'server_real_now' => $serverNowUnix
    ];
}

function getRecentSigilDrops($player, $participation = null) {
    $db = Database::getInstance();
    if (!$player['joined_season_id']) return [];
    
    $seasonId = $player['joined_season_id'];
    $runStartTick = getSeasonRunStartTick((int)$player['player_id'], (int)$seasonId, $participation);
    
    $drops = $db->fetchAll(
        "SELECT sdl.tier, sdl.source, sdl.drop_tick, sdl.created_at, pn.payload_json,
                s.start_time AS season_start_time, s.end_time AS season_end_time
         FROM sigil_drop_log sdl
         JOIN seasons s ON s.season_id = sdl.season_id
         LEFT JOIN player_notifications pn
           ON pn.player_id = sdl.player_id
          AND pn.event_key = CONCAT('sigil_drop:', sdl.season_id, ':', sdl.drop_tick, ':', sdl.tier, ':', LOWER(sdl.source))
                 WHERE sdl.player_id = ? AND sdl.season_id = ? AND sdl.drop_tick >= ?
         ORDER BY drop_tick DESC LIMIT 20",
                [$player['player_id'], $seasonId, $runStartTick]
    );

    foreach ($drops as &$d) {
        $d = normalizeRecentSigilDropRow($d);
        $d['created_at'] = iso_utc_datetime($d['created_at'] ?? null);
    }
    return $drops;
}

function getNotificationIdsFromInput($input) {
    if (isset($input['notification_ids']) && is_array($input['notification_ids'])) {
        return $input['notification_ids'];
    }
    if (isset($input['notification_id'])) {
        return [$input['notification_id']];
    }
    return [];
}

function getActiveNotificationTarget(int $targetId): ?array {
    if ($targetId <= 0) {
        return null;
    }

    $row = Database::getInstance()->fetch(
        "SELECT player_id, handle, role
         FROM players
         WHERE player_id = ? AND profile_deleted_at IS NULL",
        [$targetId]
    );

    return $row ?: null;
}

function normalizeStaffNotificationInput(array $input): array {
    $category = trim((string)($input['category'] ?? 'staff'));
    if ($category === '') $category = 'staff';
    if (strlen($category) > 40) {
        return ['error' => 'Category must be 40 characters or fewer'];
    }

    $title = trim((string)($input['title'] ?? $input['message'] ?? ''));
    if ($title === '') {
        return ['error' => 'Notification title required'];
    }
    if (strlen($title) > 100) {
        return ['error' => 'Notification title must be 100 characters or fewer'];
    }

    $bodyRaw = $input['body'] ?? null;
    $body = is_string($bodyRaw) ? trim($bodyRaw) : null;
    if ($body === '') $body = null;
    if ($body !== null && strlen($body) > 255) {
        return ['error' => 'Notification body must be 255 characters or fewer'];
    }

    $severity = isset($input['severity']) ? trim((string)$input['severity']) : 'info';
    if (!in_array($severity, ['info', 'success', 'warning', 'danger'], true)) {
        return ['error' => 'Invalid notification severity'];
    }

    $actionUrl = isset($input['action_url']) ? trim((string)$input['action_url']) : null;
    if ($actionUrl === '') $actionUrl = null;
    if ($actionUrl !== null && strlen($actionUrl) > 255) {
        return ['error' => 'Notification action URL must be 255 characters or fewer'];
    }

    $payload = null;
    if (isset($input['payload'])) {
        if (!is_array($input['payload'])) {
            return ['error' => 'Notification payload must be an object'];
        }
        $payload = $input['payload'];
    }

    $options = ['severity' => $severity];
    if ($actionUrl !== null) $options['action_url'] = $actionUrl;
    if ($payload !== null) $options['payload'] = $payload;

    return [
        'category' => $category,
        'title' => $title,
        'body' => $body,
        'options' => $options
    ];
}

function getCombineRecipesForParticipation($participation) {
    $recipes = [];
    foreach (SIGIL_COMBINE_RECIPES as $fromTier => $required) {
        $fromCol = 'sigils_t' . (int)$fromTier;
        $owned = (int)($participation[$fromCol] ?? 0);
        $recipes[] = [
            'from_tier' => (int)$fromTier,
            'to_tier' => (int)$fromTier + 1,
            'required' => (int)$required,
            'owned' => $owned,
            'can_combine' => $owned >= (int)$required
                && Economy::canReceiveSigilTier($participation, ((int)$fromTier + 1), 1, (int)$required),
        ];
    }
    return $recipes;
}

function shouldRevealTier6($participation) {
    $ownedT6 = (int)($participation['sigils_t6'] ?? 0);
    return $ownedT6 > 0;
}

function isPlayerFrozen($playerId, $seasonId, $participation = null) {
    // Delegates to the shared predicate in Economy so the API and the tick
    // engine cannot disagree about who is frozen. They previously did: the tick
    // engine omitted the run-start scoping, so a freeze predating a player's
    // current run zeroed their income while this reported "not frozen".
    return Economy::isPlayerFrozenAt(
        Database::getInstance(),
        (int)$playerId,
        (int)$seasonId,
        (int)GameTime::now(),
        (int)getSeasonRunStartTick($playerId, $seasonId, $participation)
    );
}

function getFreezeStatusForPlayer($playerId, $seasonId, $participation = null) {
    if (!$seasonId) {
        return [
            'is_frozen' => false,
            'remaining_ticks' => 0,
            'expires_tick' => null,
            'expires_at_real' => null,
            'remaining_real_seconds' => 0,
        ];
    }
    $db = Database::getInstance();
    $gameTime = GameTime::now();
    $serverNowUnix = time();
    $runStartTick = getSeasonRunStartTick($playerId, $seasonId, $participation);
    $row = $db->fetch(
        "SELECT expires_tick FROM active_freezes
         WHERE target_player_id = ? AND season_id = ? AND is_active = 1 AND expires_tick >= ? AND activated_tick >= ?
         ORDER BY expires_tick DESC LIMIT 1",
        [(int)$playerId, (int)$seasonId, (int)$gameTime, (int)$runStartTick]
    );
    if (!$row) {
        return [
            'is_frozen' => false,
            'remaining_ticks' => 0,
            'expires_tick' => null,
            'expires_at_real' => null,
            'remaining_real_seconds' => 0,
        ];
    }
    $expiresTick = (int)$row['expires_tick'];
    $expiresAtReal = GameTime::tickStartRealUnix($expiresTick + 1);
    return [
        'is_frozen' => true,
        'remaining_ticks' => max(0, $expiresTick - (int)$gameTime),
        'expires_tick' => $expiresTick,
        'expires_at_real' => $expiresAtReal,
        'remaining_real_seconds' => max(0, $expiresAtReal - $serverNowUnix),
    ];
}

// ==================== ECONOMIC CONSEQUENCE PREVIEW HELPERS ====================

/**
 * Compute a risk object for an action.
 *
 * @param float $spendFraction  0.0–1.0: fraction of relevant balance consumed.
 * @param array $extraFlags     Additional string flags to include.
 * @return array{severity: string, flags: array, explain: string}
 */
function computeEconomicRisk(float $spendFraction, array $extraFlags = []): array {
    $flags = $extraFlags;
    $severity = 'low';
    $lines = [];

    if ($spendFraction >= 0.80) {
        $severity = 'high';
        $flags[] = 'large_spend';
        $lines[] = sprintf('Action spends %.0f%% of your available balance.', $spendFraction * 100);
    } elseif ($spendFraction >= 0.50) {
        $severity = 'medium';
        $flags[] = 'moderate_spend';
        $lines[] = sprintf('Action spends %.0f%% of your available balance.', $spendFraction * 100);
    }

    if (in_array('last_sigil', $extraFlags, true)) {
        $severity = ($severity === 'low') ? 'medium' : $severity;
        $lines[] = 'This will consume your last sigil of this tier.';
    }
    if (in_array('no_boost_active', $extraFlags, true)) {
        $lines[] = 'No existing boost of this type is active; this will start a new one.';
    }
    if (in_array('time_extend', $extraFlags, true)) {
        $lines[] = 'This extends an existing boost duration.';
    }

    $explain = implode(' ', $lines) ?: 'Action is within normal spend parameters.';
    return [
        'severity' => $severity,
        'flags' => array_values($flags),
        'explain' => $explain,
    ];
}

/**
 * Build a standard preview payload.
 */
function buildPreviewPayload(
    int $estimatedTotalCost,
    int $estimatedFee,
    int $estimatedPriceImpactBp,
    int $postBalanceEstimate,
    array $risk,
    string $type = 'coins'
): array {
    $impactPct = round($estimatedPriceImpactBp / 100, 4);
    return [
        'estimated_total_cost'     => $estimatedTotalCost,
        'estimated_fee'            => $estimatedFee,
        'estimated_price_impact_bp'  => $estimatedPriceImpactBp,
        'estimated_price_impact_pct' => $impactPct,
        'post_balance_estimate'    => max(0, $postBalanceEstimate),
        'balance_type'             => $type,
        'risk'                     => $risk,
        'requires_explicit_confirm' => ($risk['severity'] !== 'low'),
    ];
}

/**
 * Preview a star purchase without executing it.
 */
function previewStarPurchase(array $player, int $starsRequested): array {
    $db = Database::getInstance();
    $playerId = (int)$player['player_id'];
    $fullPlayer = $db->fetch("SELECT * FROM players WHERE player_id = ?", [$playerId]);

    if (!$fullPlayer['participation_enabled'] || !$fullPlayer['joined_season_id']) {
        return ['error' => 'Not participating in any season'];
    }
    if ($fullPlayer['idle_modal_active']) {
        return ['error' => 'Cannot perform actions while idle', 'reason_code' => 'idle_gated'];
    }

    $seasonId = (int)$fullPlayer['joined_season_id'];
    $season = $db->fetch("SELECT * FROM seasons WHERE season_id = ?", [$seasonId]);
    $status = GameTime::getSeasonStatus($season);
    if ($status !== 'Active' && $status !== 'Blackout') {
        return ['error' => 'Star purchases are only available during the season'];
    }

    if ($starsRequested <= 0) {
        return ['error' => 'Must request a positive star quantity'];
    }

    $participation = $db->fetch(
        "SELECT * FROM season_participation WHERE player_id = ? AND season_id = ?",
        [$playerId, $seasonId]
    );

    $starPrice = Economy::publishedStarPrice($season, $status);
    if ($starPrice <= 0) return ['error' => 'Invalid star price'];

    $estimatedCost = $starsRequested * $starPrice;
    $coins = (int)$participation['coins'];
    $totalSupply = max(1, (int)$season['total_coins_supply']);
    $priceImpactBp = (int)round(($estimatedCost / $totalSupply) * 10000);

    $spendFraction = $coins > 0 ? min(1.0, $estimatedCost / $coins) : 1.0;
    $risk = computeEconomicRisk($spendFraction);

    $payload = buildPreviewPayload(
        $estimatedCost,
        0,
        $priceImpactBp,
        $coins - $estimatedCost,
        $risk
    );
    $payload['stars_requested'] = $starsRequested;
    $payload['star_price'] = $starPrice;
    $payload['coins_available'] = $coins;

    return array_merge(['success' => true, 'preview_type' => 'star_purchase'], $payload);
}

/**
 * Gated star purchase: runs preview first; blocks medium/high risk without confirm flag.
 */
function gatedStarPurchase(array $player, int $starsRequested, bool $confirmed): array {
    $preview = previewStarPurchase($player, $starsRequested);
    if (!empty($preview['error'])) return $preview;

    if ($preview['requires_explicit_confirm'] && !$confirmed) {
        return [
            'error' => 'confirmation_required',
            'reason_code' => 'confirmation_required',
            'message' => 'This action has medium or high economic impact. Send confirm_economic_impact=1 to proceed.',
            'preview' => $preview,
        ];
    }

    $result = Actions::purchaseStars($player['player_id'], $starsRequested);
    if (!empty($result['success'])) {
        $result['receipt'] = [
            'executed_total_cost'     => (int)($result['coins_spent'] ?? 0),
            'executed_fee'            => 0,
            'executed_price_impact_bp' => $preview['estimated_price_impact_bp'],
            'executed_price_impact_pct' => $preview['estimated_price_impact_pct'],
            'post_balance_estimate'   => max(0, $preview['coins_available'] - (int)($result['coins_spent'] ?? 0)),
            'stars_purchased'         => (int)($result['stars_purchased'] ?? 0),
        ];
    }
    return $result;
}

/**
 * Preview a sigil theft attempt without executing it.
 */
function previewSigilTheft(
    array $player,
    int $targetPlayerId,
    array $spentSigils,
    array $requestedSigils
): array {
    $db = Database::getInstance();
    $playerId = (int)$player['player_id'];
    $fullPlayer = $db->fetch("SELECT * FROM players WHERE player_id = ?", [$playerId]);

    if (!$fullPlayer['participation_enabled'] || !$fullPlayer['joined_season_id']) {
        return ['error' => 'Not participating in any season'];
    }
    if ($fullPlayer['idle_modal_active']) {
        return ['error' => 'Cannot perform actions while idle', 'reason_code' => 'idle_gated'];
    }
    if (!tableExists($db, 'sigil_theft_attempts')) {
        return ['error' => 'Sigil theft is unavailable until migrations are applied'];
    }

    $seasonId = (int)$fullPlayer['joined_season_id'];
    $season = $db->fetch("SELECT * FROM seasons WHERE season_id = ?", [$seasonId]);
    $status = GameTime::getSeasonStatus($season);
    if ($status !== 'Active' && $status !== 'Blackout') {
        return ['error' => 'Sigil theft is only available during Active or Blackout phase'];
    }

    $targetPlayerId = (int)$targetPlayerId;
    if ($targetPlayerId <= 0 || $targetPlayerId === $playerId) {
        return ['error' => 'Choose another player in your season'];
    }

    $target = $db->fetch(
        "SELECT player_id, handle FROM players WHERE player_id = ? AND joined_season_id = ? AND participation_enabled = 1",
        [$targetPlayerId, $seasonId]
    );
    if (!$target) {
        return ['error' => 'Target player not found in this active season'];
    }

    $participation = $db->fetch(
        "SELECT * FROM season_participation WHERE player_id = ? AND season_id = ?",
        [$playerId, $seasonId]
    );
    $targetParticipation = $db->fetch(
        "SELECT * FROM season_participation WHERE player_id = ? AND season_id = ?",
        [$targetPlayerId, $seasonId]
    );
    if (!$participation || !$targetParticipation) {
        return ['error' => 'Both players must be active season participants'];
    }

    $spentSigils = normalizeSigilCounts($spentSigils);
    $requestedSigils = normalizeSigilCounts($requestedSigils);

    $spentCount = sumSigilCounts($spentSigils, SIGIL_THEFT_SPEND_TIERS);
    if ($spentCount <= 0) {
        return ['error' => 'Select at least one ' . formatSigilTierList(SIGIL_THEFT_SPEND_TIERS) . ' sigil to spend'];
    }
    for ($tier = 1; $tier <= 6; $tier++) {
        if (!in_array($tier, SIGIL_THEFT_SPEND_TIERS, true) && (int)($spentSigils[$tier - 1] ?? 0) > 0) {
            return ['error' => 'Only ' . formatSigilTierList(SIGIL_THEFT_SPEND_TIERS) . ' sigils can be spent on theft'];
        }
    }

    $requestedCount = sumSigilCounts($requestedSigils, SIGIL_THEFT_TARGET_TIERS);
    if ($requestedCount <= 0) {
        return ['error' => 'Select at least one sigil to attempt to steal'];
    }

    $attackerTheft = getTheftStatusForPlayer($playerId, $seasonId, $participation);
    if ($attackerTheft['is_on_cooldown'] ?? false) {
        return ['error' => 'Your theft cooldown is active'];
    }

    $targetTheft = getTheftStatusForPlayer($targetPlayerId, $seasonId, $targetParticipation);
    if ($targetTheft['is_protected'] ?? false) {
        return ['error' => 'Target theft protection is active'];
    }

    foreach (SIGIL_THEFT_SPEND_TIERS as $tier) {
        $amount = max(0, (int)($spentSigils[$tier - 1] ?? 0));
        if ($amount > (int)($participation['sigils_t' . $tier] ?? 0)) {
            return ['error' => 'Insufficient Tier ' . $tier . ' Sigils'];
        }
    }

    // The preview deliberately does NOT check the request against the target's
    // holdings.
    //
    // It used to, and answered "Requested sigils exceed target inventory" -
    // which is a yes/no on "does this player hold at least N of tier T",
    // available for free, instantly, unlimited, with no cooldown and no notice
    // to the target. Walking N per tier reads their exact inventory, which is
    // the information theft is supposed to be a gamble about.
    //
    // Nothing is lost by omitting it: attemptSigilTheft re-validates the same
    // condition inside its transaction under SELECT ... FOR UPDATE, so an
    // over-large request still cannot take what is not there. The odds below
    // are computed from the requested value, which is what the attacker is
    // choosing to stake.

    $spendValue = calculateSigilCountsValue($spentSigils, SIGIL_UTILITY_VALUE_BY_TIER);
    $requestedValue = calculateSigilCountsValue($requestedSigils, SIGIL_UTILITY_VALUE_BY_TIER);
    if ($requestedValue > $spendValue) {
        return ['error' => 'Requested loot value exceeds your theft spend value'];
    }

    $projectedAttacker = $participation;
    foreach (SIGIL_THEFT_SPEND_TIERS as $tier) {
        $col = 'sigils_t' . $tier;
        $projectedAttacker[$col] = max(0, (int)$projectedAttacker[$col] - (int)($spentSigils[$tier - 1] ?? 0));
    }
    foreach (SIGIL_THEFT_TARGET_TIERS as $tier) {
        $col = 'sigils_t' . $tier;
        $projectedAttacker[$col] = max(0, (int)$projectedAttacker[$col] + (int)($requestedSigils[$tier - 1] ?? 0));
    }

    $capError = Actions::validateSigilInventoryCaps($projectedAttacker);
    if ($capError !== null) {
        return ['error' => $capError];
    }

    $availableSpendSigils = array_fill(0, 6, 0);
    foreach (SIGIL_THEFT_SPEND_TIERS as $tier) {
        $availableSpendSigils[$tier - 1] = max(0, (int)($participation['sigils_t' . $tier] ?? 0));
    }
    $availableSpendValue = calculateSigilCountsValue($availableSpendSigils, SIGIL_UTILITY_VALUE_BY_TIER);
    $availableSpendCount = sumSigilCounts($availableSpendSigils, SIGIL_THEFT_SPEND_TIERS);
    $spendFraction = $availableSpendValue > 0 ? min(1.0, $spendValue / $availableSpendValue) : 1.0;
    $risk = computeEconomicRisk($spendFraction);
    if (($risk['severity'] ?? 'low') === 'low') {
        $risk['severity'] = 'medium';
    }
    $risk['explain'] = trim('Spent sigils are always consumed, even when the theft fails. ' . ($risk['explain'] ?? ''));

    $successChanceFp = min(
        (int)SIGIL_THEFT_SUCCESS_CAP_FP,
        intdiv($spendValue * FP_SCALE, max(1, $spendValue + ((int)SIGIL_THEFT_VALUE_PRESSURE_MULTIPLIER * $requestedValue)))
    );

    $payload = buildPreviewPayload(
        $spentCount,
        0,
        0,
        max(0, $availableSpendCount - $spentCount),
        $risk,
        'sigils_mixed'
    );
    $payload['requires_explicit_confirm'] = true;
    $payload['target_player_id'] = (int)$target['player_id'];
    $payload['target_handle'] = (string)$target['handle'];
    $payload['spent_sigils'] = $spentSigils;
    $payload['requested_sigils'] = $requestedSigils;
    $payload['spend_value'] = $spendValue;
    $payload['requested_value'] = $requestedValue;
    $payload['success_chance_fp'] = $successChanceFp;
    $payload['success_chance_pct'] = round($successChanceFp / 10000, 2);
    $payload['available_spend_sigils'] = $availableSpendCount;
    $payload['theft_cooldown_state'] = $attackerTheft;
    $payload['theft_protection_state'] = $targetTheft;

    return array_merge(['success' => true, 'preview_type' => 'sigil_theft'], $payload);
}

/**
 * Gated sigil theft attempt: runs preview first and requires explicit confirmation.
 */
function gatedSigilTheftAttempt(
    array $player,
    int $targetPlayerId,
    array $spentSigils,
    array $requestedSigils,
    bool $confirmed
): array {
    $preview = previewSigilTheft($player, $targetPlayerId, $spentSigils, $requestedSigils);
    if (!empty($preview['error'])) return $preview;

    if ($preview['requires_explicit_confirm'] && !$confirmed) {
        return [
            'error' => 'confirmation_required',
            'reason_code' => 'confirmation_required',
            'message' => 'This theft attempt is chance-based and requires confirmation. Send confirm_economic_impact=1 to proceed.',
            'preview' => $preview,
        ];
    }

    $result = Actions::attemptSigilTheft($player['player_id'], $targetPlayerId, $spentSigils, $requestedSigils);
    if (!empty($result['error']) && !empty($confirmed)) {
        $dynamicErrors = [
            'Insufficient Tier 3 Sigils',
            'Insufficient Tier 4 Sigils',
            'Insufficient Tier 5 Sigils',
            'Requested sigils exceed target inventory',
            'Target theft protection is active',
            'Your theft cooldown is active',
            'Sigil inventory cap reached for Tier 1',
            'Sigil inventory cap reached for Tier 2',
            'Sigil inventory cap reached for Tier 3',
            'Sigil inventory cap reached for Tier 4',
            'Sigil inventory cap reached for Tier 5',
            'Sigil inventory cap reached for Tier 6',
            'Sigil inventory total cap reached',
        ];
        if (in_array((string)$result['error'], $dynamicErrors, true)) {
            $latestPreview = previewSigilTheft($player, $targetPlayerId, $spentSigils, $requestedSigils);
            return [
                'error' => 'balance_changed',
                'reason_code' => 'balance_changed',
                'message' => 'Player state changed since confirmation. Please review the theft preview again.',
                'preview' => $latestPreview,
                'prior_error' => $result['error'],
            ];
        }
    }

    if (!empty($result['success'])) {
        $result['receipt'] = [
            'preview_type' => 'sigil_theft',
            'executed_total_cost' => sumSigilCounts($spentSigils, SIGIL_THEFT_SPEND_TIERS),
            'post_balance_estimate' => (int)($preview['post_balance_estimate'] ?? 0),
            'spent_sigils' => $spentSigils,
            'requested_sigils' => $requestedSigils,
            'transferred_sigils' => $result['transferred_sigils'] ?? [0, 0, 0, 0, 0, 0],
            'success_chance_pct' => (float)($preview['success_chance_pct'] ?? 0),
            'spend_value' => (int)($preview['spend_value'] ?? 0),
            'requested_value' => (int)($preview['requested_value'] ?? 0),
            'theft_success' => !empty($result['theft_success']),
            'target_handle' => (string)($result['target_handle'] ?? ($preview['target_handle'] ?? '')),
        ];
    }

    return $result;
}

/**
 * Preview a boost activation without executing it.
 */
function previewBoostActivate(array $player, int $sigilTier, string $purchaseKind, ?int $boostId = null): array {
    $db = Database::getInstance();
    $playerId = (int)$player['player_id'];
    $fullPlayer = $db->fetch("SELECT * FROM players WHERE player_id = ?", [$playerId]);

    if (!$fullPlayer['participation_enabled'] || !$fullPlayer['joined_season_id']) {
        return ['error' => 'Not participating in any season'];
    }
    if ($fullPlayer['idle_modal_active']) {
        return ['error' => 'Cannot perform actions while idle', 'reason_code' => 'idle_gated'];
    }

    $purchaseKind = strtolower(trim($purchaseKind));
    $sigilTier = (int)$sigilTier;
    if ($purchaseKind !== 'power' && $purchaseKind !== 'time') {
        return ['error' => 'Invalid boost purchase kind'];
    }
    if (!BoostCatalog::canSpendSigilTier($sigilTier)) {
        if ($sigilTier === 6) {
            return ['error' => 'Tier 6 sigils cannot be used for power boosts or time extensions'];
        }
        return ['error' => 'Invalid sigil tier'];
    }
    $seasonId = (int)$fullPlayer['joined_season_id'];
    $season = $db->fetch("SELECT * FROM seasons WHERE season_id = ?", [$seasonId]);
    $status = GameTime::getSeasonStatus($season);
    if ($status !== 'Active' && $status !== 'Blackout') {
        return ['error' => 'Boost activation is only available during active season or blackout'];
    }

    $catalogByTier = $db->fetch("SELECT * FROM boost_catalog WHERE tier_required = ? ORDER BY boost_id ASC LIMIT 1", [$sigilTier]);
    if (!$catalogByTier) return ['error' => 'Boost catalog unavailable for selected sigil tier'];
    $catalogByTier = BoostCatalog::normalize($catalogByTier);

    $resolvedBoostId = (int)$catalogByTier['boost_id'];
    $resolvedBoostName = (string)($catalogByTier['name'] ?? ('Tier ' . $sigilTier . ' Boost'));
    $sigilCost = 1;
    $powerIncrementFp = max(1, BoostCatalog::getSpendPowerFpForTier($sigilTier));
    $timeIncrementTicks = max(1, BoostCatalog::getSpendTimeTicksForTier($sigilTier));
    $timeIncrementRealSeconds = max(1, BoostCatalog::getSpendTimeRealSecondsForTier($sigilTier));
    $initialPowerFp = max(1, BoostCatalog::getInitialPowerFpForTier($sigilTier));
    $initialDurationTicks = max(1, BoostCatalog::getInitialDurationTicksForTier($sigilTier));
    $initialDurationRealSeconds = max(1, BoostCatalog::getInitialDurationRealSecondsForTier($sigilTier));
    $totalPowerCapFp = BoostCatalog::TOTAL_POWER_CAP_FP;
    $timeCapTicks = BoostCatalog::getTimeCapTicks();
    $recoveryTicks = BoostCatalog::getRecoveryTicks();
    $recoveryLabel = ((int)BoostCatalog::RECOVERY_SECONDS_AFTER_SESSION % 3600 === 0)
        ? ((int)(BoostCatalog::RECOVERY_SECONDS_AFTER_SESSION / 3600) . 'h')
        : ((int)BoostCatalog::RECOVERY_SECONDS_AFTER_SESSION . 's');
    $recoveryError = "Boost is recovering ({$recoveryLabel})";

    $participation = $db->fetch(
        "SELECT * FROM season_participation WHERE player_id = ? AND season_id = ?",
        [$playerId, $seasonId]
    );

    $sigilCol = "sigils_t{$sigilTier}";
    $sigilsOwned = (int)($participation[$sigilCol] ?? 0);
    if ($sigilsOwned < $sigilCost) {
        return ['error' => "Insufficient Tier {$sigilTier} Sigils"];
    }

    $gameTime = GameTime::now();
    $activeRows = $db->fetchAll(
        "SELECT * FROM active_boosts
         WHERE player_id = ? AND season_id = ? AND scope = 'SELF' AND is_active = 1 AND expires_tick >= ?
           AND (activated_tick <= 0 OR activated_tick + ? >= ?)
         ORDER BY expires_tick DESC, id ASC",
        [$playerId, $seasonId, $gameTime, $timeCapTicks, $gameTime]
    );
    $activeRow = count($activeRows) > 0 ? $activeRows[0] : null;

    $currentBoostTotalFp = 0;
    foreach ($activeRows as $row) {
        $currentBoostTotalFp += max(0, (int)($row['modifier_fp'] ?? 0));
    }

    $combinedRow = $db->fetch(
        "SELECT COALESCE(SUM(modifier_fp), 0) AS total_fp
         FROM active_boosts
         WHERE season_id = ? AND is_active = 1 AND expires_tick >= ?
           AND (activated_tick <= 0 OR activated_tick + ? >= ?)
           AND scope = 'SELF' AND player_id = ?",
        [$seasonId, $gameTime, $timeCapTicks, $gameTime, $playerId]
    );
    $combinedCurrentFp = max(0, (int)($combinedRow['total_fp'] ?? 0));

    if (!$activeRow) {
        $recentSelfBoosts = $db->fetchAll(
            "SELECT expires_tick, activated_tick FROM active_boosts
             WHERE player_id = ? AND season_id = ? AND scope = 'SELF'
             ORDER BY id DESC
             LIMIT 50",
            [$playerId, $seasonId]
        );
        $latestExpiresTick = 0;
        foreach ($recentSelfBoosts as $row) {
            $latestExpiresTick = max(
                $latestExpiresTick,
                BoostCatalog::getEffectiveExpiresTick(
                    (int)($row['expires_tick'] ?? 0),
                    (int)($row['activated_tick'] ?? 0)
                )
            );
        }
        if ($latestExpiresTick > 0 && $latestExpiresTick < $gameTime && BoostCatalog::isRecoveringAfterSession($latestExpiresTick, $gameTime)) {
            return [
                'error' => $recoveryError,
                'reason_code' => 'boost_recovery',
                'recovery_until_tick' => BoostCatalog::getRecoveryUntilTick($latestExpiresTick),
                'recovery_ticks' => $recoveryTicks,
            ];
        }

        $projectedCombinedFp = $combinedCurrentFp - $currentBoostTotalFp + $initialPowerFp;
        if ($projectedCombinedFp > $totalPowerCapFp) {
            return ['error' => 'Total boost cap reached (500% combined)'];
        }
    }

    if ($purchaseKind === 'power' && $activeRow) {
        $currentModifierFp = max(0, (int)($activeRow['modifier_fp'] ?? 0));
        $newModifierFp = min($totalPowerCapFp, $currentModifierFp + $powerIncrementFp);
        $projectedCombinedFp = $combinedCurrentFp - $currentBoostTotalFp + $newModifierFp;
        if ($projectedCombinedFp > $totalPowerCapFp || $newModifierFp <= $currentModifierFp) {
            return ['error' => 'Total boost cap reached (500% combined)'];
        }
    }

    if ($purchaseKind === 'time' && $activeRow) {
        $currentRawExpiresTick = max($gameTime, (int)$activeRow['expires_tick']);
        $currentActivatedTick = max(0, (int)($activeRow['activated_tick'] ?? 0));
        if ($currentActivatedTick <= 0) {
            $currentActivatedTick = max(0, $currentRawExpiresTick - $timeCapTicks);
        }
        $currentExpiresTick = BoostCatalog::getEffectiveExpiresTick($currentRawExpiresTick, $currentActivatedTick);
        $maxExpiresTick = BoostCatalog::getSessionMaxExpiresTick($currentActivatedTick);
        if ($currentExpiresTick >= $maxExpiresTick) {
            return ['error' => 'Maximum boost session time reached'];
        }
    }

    $extraFlags = [];
    if (!$activeRow) $extraFlags[] = 'no_boost_active';
    if ($activeRow && $purchaseKind === 'time') $extraFlags[] = 'time_extend';
    if ($sigilsOwned === $sigilCost) $extraFlags[] = 'last_sigil';

    $spendFraction = $sigilsOwned > 0 ? min(1.0, $sigilCost / $sigilsOwned) : 1.0;
    $risk = computeEconomicRisk($spendFraction, $extraFlags);

    $payload = buildPreviewPayload(
        $sigilCost,
        0,
        0,
        max(0, $sigilsOwned - $sigilCost),
        $risk,
        "sigils_t{$sigilTier}"
    );
    $payload['boost_id']       = $resolvedBoostId;
    $payload['boost_name']     = $resolvedBoostName;
    $payload['tier_required']  = $sigilTier;
    $payload['sigil_tier_to_consume'] = $sigilTier;
    $payload['sigil_tier']     = $sigilTier;
    $payload['sigil_cost']     = $sigilCost;
    $payload['sigils_owned']   = $sigilsOwned;
    $payload['purchase_kind']  = $purchaseKind;
    $payload['modifier_fp']    = $activeRow ? max(0, (int)($activeRow['modifier_fp'] ?? 0)) : $initialPowerFp;
    $payload['modifier_percent'] = round((int)$payload['modifier_fp'] / 10000, 1);
    $payload['time_extension_ticks'] = $activeRow ? ($purchaseKind === 'time' ? $timeIncrementTicks : 0) : $initialDurationTicks;
    $payload['time_extension_real_seconds'] = $activeRow ? ($purchaseKind === 'time' ? $timeIncrementRealSeconds : 0) : $initialDurationRealSeconds;
    $payload['time_sigil_tier_used'] = ($purchaseKind === 'time') ? $sigilTier : null;
    $payload['power_cap_fp'] = $totalPowerCapFp;
    $payload['total_power_cap_fp'] = $totalPowerCapFp;
    $payload['time_cap_ticks'] = $timeCapTicks;
    $payload['recovery_ticks'] = $recoveryTicks;
    $payload['initialized_from_inactive'] = !$activeRow;

    return array_merge(['success' => true, 'preview_type' => 'boost_activate'], $payload);
}

/**
 * Gated boost activate: runs preview first; blocks medium/high risk without confirm flag.
 */
function gatedBoostActivate(array $player, int $sigilTier, string $purchaseKind, bool $confirmed, ?int $boostId = null): array {
    $preview = previewBoostActivate($player, $sigilTier, $purchaseKind, $boostId);
    if (!empty($preview['error'])) return $preview;

    if ($preview['requires_explicit_confirm'] && !$confirmed) {
        return [
            'error' => 'confirmation_required',
            'reason_code' => 'confirmation_required',
            'message' => 'This boost activation has medium or high economic impact. Send confirm_economic_impact=1 to proceed.',
            'preview' => $preview,
        ];
    }

    $result = Actions::purchaseBoost($player['player_id'], $sigilTier, $purchaseKind, $boostId);
    if (!empty($result['success'])) {
        $result['receipt'] = [
            'preview_type'            => 'boost_activate',
            'executed_total_cost'      => (int)($result['sigils_consumed'] ?? 0),
            'executed_fee'             => 0,
            'executed_price_impact_bp' => 0,
            'executed_price_impact_pct' => 0.0,
            'post_balance_estimate'    => (int)$preview['post_balance_estimate'],
            'purchase_kind'            => (string)($result['purchase_kind'] ?? ''),
            'sigil_tier'               => (int)($result['sigil_tier'] ?? 0),
            'tier_consumed'            => (int)($result['tier_consumed'] ?? 0),
            'sigils_consumed'          => (int)($result['sigils_consumed'] ?? 0),
            'initialized_from_inactive' => !empty($result['initialized_from_inactive']),
            'purchased_power_percent'  => (float)($result['purchased_power_percent'] ?? 0),
            'purchased_time_real_seconds' => (int)($result['purchased_time_real_seconds'] ?? 0),
            'modifier_percent'         => (float)($result['modifier_percent'] ?? 0),
            'time_extension_real_seconds' => (int)($result['time_extension_real_seconds'] ?? 0),
        ];
    }
    return $result;
}
