<?php
/**
 * Too Many Coins - API Router
 * All game API endpoints
 */

// Security headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Session-Token');
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

    // Most reverse proxies sit on private/reserved addresses from the app's perspective.
    return tmc_is_private_or_reserved_ip($remoteAddr);
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

// Rate limiting (file-based) keyed by session identity when available.
$rateLimitDir = sys_get_temp_dir() . '/tmc_ratelimit';
if (!is_dir($rateLimitDir)) {
    @mkdir($rateLimitDir, 0755, true);
}

$sessionToken = $_COOKIE['tmc_session'] ?? ($_SERVER['HTTP_X_SESSION_TOKEN'] ?? '');
$sessionToken = is_string($sessionToken) ? trim($sessionToken) : '';
$hasSessionIdentity = preg_match('/^[a-f0-9]{64}$/i', $sessionToken) === 1;

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
$rateData = file_exists($rateLimitFile) ? json_decode(file_get_contents($rateLimitFile), true) : null;
if (!is_array($rateData) || !isset($rateData['window_start']) || ($now - (int)$rateData['window_start']) >= $rateWindow) {
    $rateData = ['window_start' => $now, 'count' => 0];
}
$rateData['count'] = (int)($rateData['count'] ?? 0) + 1;
file_put_contents($rateLimitFile, json_encode($rateData));

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

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/game_time.php';
require_once __DIR__ . '/../includes/boost_catalog.php';
require_once __DIR__ . '/../includes/economy.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/actions.php';
require_once __DIR__ . '/../includes/tick_engine.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/sigil_drops_api.php';

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
        TickEngine::processTicks();
        echo json_encode([
            'ok' => true,
            'server_now' => GameTime::now()
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
            $seasonId = (int)($input['season_id'] ?? 0);
            $limit = isset($input['limit']) ? (int)$input['limit'] : 0;
            echo json_encode(getLeaderboard($seasonId, $limit));
            break;
            
        case 'global_leaderboard':
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
            echo json_encode(Actions::combineSigils($player['player_id'], $fromTier));
            break;

        case 'freeze_player_ubi':
            $player = Auth::requireAuth();
            echo json_encode(Actions::freezePlayerUbi(
                $player['player_id'],
                (int)($input['target_player_id'] ?? 0),
                isset($input['target_handle']) ? (string)$input['target_handle'] : null
            ));
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

        case 'notifications_create':
            $player = Auth::requireAuth();
            $category = trim((string)($input['category'] ?? 'gameplay'));
            if ($category === '') $category = 'gameplay';
            $title = trim((string)($input['title'] ?? $input['message'] ?? 'Notification'));
            if ($title === '') $title = 'Notification';
            $bodyRaw = $input['body'] ?? null;
            $body = is_string($bodyRaw) ? trim($bodyRaw) : null;
            if ($body === '') $body = null;

            $payload = null;
            if (isset($input['payload']) && is_array($input['payload'])) {
                $payload = $input['payload'];
            }

            $id = Notifications::create(
                $player['player_id'],
                $category,
                $title,
                $body,
                [
                    'is_read' => !empty($input['is_read']),
                    'event_key' => isset($input['event_key']) ? (string)$input['event_key'] : null,
                    'payload' => $payload
                ]
            );

            echo json_encode([
                'success' => true,
                'notification' => Notifications::getByIdForPlayer($player['player_id'], $id),
                'unread_count' => Notifications::unreadCount($player['player_id'])
            ]);
            break;
            
        // ==================== TRADING ====================
        case 'trade_preview':
            $player = Auth::requireAuth();
            $sideASigils = normalizeSigilCounts($input['side_a_sigils'] ?? [0,0,0,0,0,0]);
            $sideBSigils = normalizeSigilCounts($input['side_b_sigils'] ?? [0,0,0,0,0,0]);
            echo json_encode(previewTrade(
                $player,
                (int)($input['acceptor_id'] ?? 0),
                (int)($input['side_a_coins'] ?? 0),
                $sideASigils,
                (int)($input['side_b_coins'] ?? 0),
                $sideBSigils
            ));
            break;

        case 'trade_initiate':
            $player = Auth::requireAuth();
            $confirmed = !empty($input['confirm_economic_impact']);
            $sideASigils = normalizeSigilCounts($input['side_a_sigils'] ?? [0,0,0,0,0,0]);
            $sideBSigils = normalizeSigilCounts($input['side_b_sigils'] ?? [0,0,0,0,0,0]);
            $result = gatedTradeInitiate(
                $player,
                (int)($input['acceptor_id'] ?? 0),
                (int)($input['side_a_coins'] ?? 0),
                $sideASigils,
                (int)($input['side_b_coins'] ?? 0),
                $sideBSigils,
                $confirmed
            );
            echo json_encode($result);
            break;
            
        case 'trade_accept':
            $player = Auth::requireAuth();
            echo json_encode(Actions::tradeAccept(
                $player['player_id'],
                (int)($input['trade_id'] ?? 0)
            ));
            break;
            
        case 'trade_decline':
            $player = Auth::requireAuth();
            echo json_encode(Actions::tradeCancel(
                $player['player_id'],
                (int)($input['trade_id'] ?? 0),
                'DECLINED'
            ));
            break;
            
        case 'trade_cancel':
            $player = Auth::requireAuth();
            echo json_encode(Actions::tradeCancel(
                $player['player_id'],
                (int)($input['trade_id'] ?? 0),
                'CANCELED'
            ));
            break;
            
        case 'my_trades':
            $player = Auth::requireAuth();
            echo json_encode(getMyTrades($player));
            break;
            
        case 'season_players':
            $seasonId = (int)($input['season_id'] ?? 0);
            echo json_encode(getSeasonPlayers($seasonId));
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
            $player = Auth::getCurrentPlayer();
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
                'combine_sigil', 'freeze_player_ubi',
                'lock_in', 'idle_ack', 'boost_catalog', 'purchase_boost', 'active_boosts',
                'sigil_drops', 'trade_initiate', 'trade_accept', 'trade_decline',
                'trade_cancel', 'my_trades', 'season_players', 'cosmetic_catalog',
                'purchase_cosmetic', 'equip_cosmetic', 'my_cosmetics', 'chat_send',
                'chat_messages', 'notifications_list', 'notifications_mark_read',
                'notifications_mark_all_read', 'notifications_remove', 'notifications_create',
                'profile', 'my_badges', 'season_history', 'tick',
                'star_purchase_preview', 'trade_preview', 'boost_activate_preview',
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
    if (isPlayerFrozen((int)$player['player_id'], (int)$season['season_id'])) {
        return [
            'rate_per_tick' => 0,
            'gross_rate_per_tick' => 0,
            'hoarding_sink_per_tick' => 0,
            'net_rate_per_tick' => 0,
            'hoarding_sink_active' => false,
        ];
    }

    $totalModFp = (int)($activeBoosts['total_modifier_fp'] ?? 0);
    $rates = Economy::calculateRateBreakdown($season, $player, $participation, $totalModFp, false);

    $grossRate = round(((int)$rates['gross_rate_fp']) / FP_SCALE, 2);
    $sinkPerTick = max(0, (int)$rates['sink_per_tick']);
    $netRate = round(((int)$rates['net_rate_fp']) / FP_SCALE, 2);

    return [
        // Preserve legacy key as player-facing gross rate.
        'rate_per_tick' => max(0, $grossRate),
        'gross_rate_per_tick' => max(0, $grossRate),
        'hoarding_sink_per_tick' => $sinkPerTick,
        'net_rate_per_tick' => max(0, $netRate),
        'hoarding_sink_active' => Economy::hoardingSinkEnabled($season) && $sinkPerTick > 0,
    ];
}

function calculateLeaderboardRowMetrics($season, array $row): array {
    $boostCapFp = (int)BoostCatalog::TOTAL_POWER_CAP_FP;
    $boostModFp = max(0, min((int)($row['boost_mod_fp'] ?? 0), $boostCapFp));
    $isFrozen = ((int)($row['is_frozen'] ?? 0) > 0);

    $playerShim = [
        'participation_enabled' => 1,
        'activity_state' => $row['activity_state'] ?? 'Offline',
    ];
    $participationShim = [
        'coins' => (int)($row['coins'] ?? 0),
        'participation_time_total' => (int)($row['participation_time_total'] ?? 0),
    ];

    $breakdown = Economy::calculateRateBreakdown(
        $season,
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
    
    // Get visible seasons
    $seasons = GameTime::getVisibleSeasons();
    foreach ($seasons as &$s) {
        $s['computed_status'] = GameTime::getSeasonStatus($s);
        applySeasonCountdownFields($s, $gameTime);
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

        $activeBoosts = getActiveBoosts($player);
        $rateMetrics = calculatePlayerRatePerTick($joinedSeason, $player, $participation, $activeBoosts);
        
        $state['player'] = [
            'player_id' => $player['player_id'],
            'handle' => $player['handle'],
            'role' => $player['role'],
            'global_stars' => (int)$player['global_stars'],
            'joined_season_id' => $player['joined_season_id'],
            'participation_enabled' => (bool)$player['participation_enabled'],
            'idle_modal_active' => (bool)$player['idle_modal_active'],
            'activity_state' => $player['activity_state'],
            'participation' => $participation ? [
                'coins' => (int)$participation['coins'],
                'seasonal_stars' => (int)$participation['seasonal_stars'],
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
                'sigil_drops_total' => (int)($participation['sigil_drops_total'] ?? 0),
                'eligible_ticks_since_last_drop' => (int)($participation['eligible_ticks_since_last_drop'] ?? 0),
                'combine_recipes' => getCombineRecipesForParticipation($participation),
                'tier6_visible' => shouldRevealTier6($participation),
                'can_freeze' => ((int)($participation['sigils_t6'] ?? 0) > 0),
                'freeze' => getFreezeStatusForPlayer((int)$player['player_id'], (int)$player['joined_season_id']),
                'rate_per_tick' => (float)$rateMetrics['rate_per_tick'],
                'gross_rate_per_tick' => (float)$rateMetrics['gross_rate_per_tick'],
                'hoarding_sink_per_tick' => (int)$rateMetrics['hoarding_sink_per_tick'],
                'net_rate_per_tick' => (float)$rateMetrics['net_rate_per_tick'],
                'hoarding_sink_active' => (bool)$rateMetrics['hoarding_sink_active'],
            ] : null,
            'active_boosts' => $activeBoosts,
            'recent_drops' => ($player['joined_season_id']) ? getRecentSigilDrops($player) : [],
            'notifications' => Notifications::listForPlayer($player['player_id'], 50),
            'notifications_unread_count' => Notifications::unreadCount($player['player_id']),
            'can_lock_in' => canLockIn($player, $participation),
            'can_purchase_stars' => canPurchaseStars($player),
            'can_trade' => canTrade($player),
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

function canLockIn($player, $participation) {
    if (!$player['participation_enabled'] || !$player['joined_season_id']) return false;
    if ($player['idle_modal_active']) return false;
    if (!$participation) return false;
    if ($participation['participation_ticks_since_join'] < MIN_PARTICIPATION_TICKS) return false;
    
    $db = Database::getInstance();
    $season = $db->fetch("SELECT * FROM seasons WHERE season_id = ?", [$player['joined_season_id']]);
    $status = GameTime::getSeasonStatus($season);
    return ($status === 'Active');
}

function canPurchaseStars($player) {
    if (!$player['participation_enabled'] || !$player['joined_season_id']) return false;
    if ($player['idle_modal_active']) return false;
    return true;
}

function canTrade($player) {
    if (!$player['participation_enabled'] || !$player['joined_season_id']) return false;
    if ($player['idle_modal_active']) return false;
    
    $db = Database::getInstance();
    $season = $db->fetch("SELECT * FROM seasons WHERE season_id = ?", [$player['joined_season_id']]);
    $status = GameTime::getSeasonStatus($season);
    return ($status === 'Active');
}

function getSeasonDetail($player, $seasonId) {
    $db = Database::getInstance();
    $season = $db->fetch("SELECT * FROM seasons WHERE season_id = ?", [$seasonId]);
    if (!$season) return ['error' => 'Season not found'];
    
    $gameTime = GameTime::now();
    $season['computed_status'] = GameTime::getSeasonStatus($season);
    applySeasonCountdownFields($season, $gameTime);

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
            $season['player_can_freeze'] = ((int)($participation['sigils_t6'] ?? 0) > 0);
            $season['player_freeze'] = getFreezeStatusForPlayer((int)$player['player_id'], (int)$seasonId);
        }
    }
    
    // Top players
    if ($season['computed_status'] === 'Active' || $season['computed_status'] === 'Blackout') {
        $season['leaderboard'] = $db->fetchAll(
            "SELECT p.player_id, p.handle,
                    COALESCE(sp.seasonal_stars, 0) AS seasonal_stars,
                    sp.lock_in_effect_tick
             FROM players p
             LEFT JOIN season_participation sp ON sp.player_id = p.player_id AND sp.season_id = ?
             WHERE p.joined_season_id = ? AND p.participation_enabled = 1
             ORDER BY COALESCE(sp.seasonal_stars, 0) DESC, p.player_id ASC",
            [$seasonId, $seasonId]
        );

        // Fallback for deployments where player active-state flags drifted from
        // season_participation rows; keeps leaderboard visible instead of empty.
        if (empty($season['leaderboard'])) {
            $season['leaderboard'] = $db->fetchAll(
                "SELECT sp.player_id, p.handle,
                        COALESCE(sp.seasonal_stars, 0) AS seasonal_stars,
                        sp.lock_in_effect_tick
                 FROM season_participation sp
                 JOIN players p ON p.player_id = sp.player_id
                 WHERE sp.season_id = ?
                 ORDER BY COALESCE(sp.seasonal_stars, 0) DESC, sp.player_id ASC",
                [$seasonId]
            );
        }
    } else {
        $season['leaderboard'] = $db->fetchAll(
            "SELECT sp.player_id, p.handle, sp.seasonal_stars, sp.lock_in_effect_tick
             FROM season_participation sp
             JOIN players p ON p.player_id = sp.player_id
             WHERE sp.season_id = ?
             ORDER BY sp.seasonal_stars DESC, sp.player_id ASC
             LIMIT 50",
            [$seasonId]
        );
    }
    
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

function getLeaderboard($seasonId, int $limit = 0) {
    $db = Database::getInstance();
    $season = $db->fetch("SELECT * FROM seasons WHERE season_id = ?", [$seasonId]);
    if (!$season) return [];
    $limit = max(0, min(LEADERBOARD_MAX_LIMIT, (int)$limit));
    $limitClause = $limit > 0 ? " LIMIT ?" : "";

    $status = GameTime::getSeasonStatus($season);
    if ($status === 'Active' || $status === 'Blackout') {
        $gameTime = GameTime::now();
        $boostCapFp = (int)BoostCatalog::TOTAL_POWER_CAP_FP;
        $hasBoostTables = tableExists($db, 'active_boosts');
        $hasFreezeTable = tableExists($db, 'active_freezes');

        if ($hasBoostTables && $hasFreezeTable) {
            $rows = $db->fetchAll(
                "SELECT p.player_id, p.handle,
                        COALESCE(sp.seasonal_stars, 0) AS seasonal_stars,
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
                 FROM players p
                 LEFT JOIN season_participation sp ON sp.player_id = p.player_id AND sp.season_id = ?
                 LEFT JOIN (
                     SELECT player_id, SUM(modifier_fp) AS self_fp
                     FROM active_boosts
                     WHERE season_id = ? AND is_active = 1 AND scope = 'SELF' AND expires_tick >= ?
                     GROUP BY player_id
                 ) self_b ON self_b.player_id = p.player_id
                 LEFT JOIN (
                     SELECT target_player_id AS player_id, 1 AS is_frozen
                     FROM active_freezes
                     WHERE season_id = ? AND is_active = 1 AND expires_tick >= ?
                     GROUP BY target_player_id
                                 ) frz ON frz.player_id = p.player_id
                  WHERE p.joined_season_id = ? AND p.participation_enabled = 1
                  ORDER BY COALESCE(sp.seasonal_stars, 0) DESC, p.player_id ASC{$limitClause}",
                $limit > 0
                                        ? [$boostCapFp, $seasonId, $seasonId, $gameTime, $seasonId, $gameTime, $seasonId, $limit]
                                        : [$boostCapFp, $seasonId, $seasonId, $gameTime, $seasonId, $gameTime, $seasonId]
            );
            if (empty($rows)) {
                $rows = $db->fetchAll(
                    "SELECT p.player_id, p.handle,
                            COALESCE(sp.seasonal_stars, 0) AS seasonal_stars,
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
                         GROUP BY player_id
                     ) self_b ON self_b.player_id = p.player_id
                     LEFT JOIN (
                         SELECT target_player_id AS player_id, 1 AS is_frozen
                         FROM active_freezes
                         WHERE season_id = ? AND is_active = 1 AND expires_tick >= ?
                         GROUP BY target_player_id
                     ) frz ON frz.player_id = p.player_id
                     WHERE sp.season_id = ?
                     ORDER BY COALESCE(sp.seasonal_stars, 0) DESC, sp.player_id ASC{$limitClause}",
                    $limit > 0
                        ? [$boostCapFp, $seasonId, $gameTime, $seasonId, $gameTime, $seasonId, $limit]
                        : [$boostCapFp, $seasonId, $gameTime, $seasonId, $gameTime, $seasonId]
                );
            }
        } elseif ($hasFreezeTable) {
            $rows = $db->fetchAll(
                "SELECT p.player_id, p.handle,
                        COALESCE(sp.seasonal_stars, 0) AS seasonal_stars,
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
                 FROM players p
                 LEFT JOIN season_participation sp ON sp.player_id = p.player_id AND sp.season_id = ?
                 LEFT JOIN (
                     SELECT target_player_id AS player_id, 1 AS is_frozen
                     FROM active_freezes
                     WHERE season_id = ? AND is_active = 1 AND expires_tick >= ?
                     GROUP BY target_player_id
                 ) frz ON frz.player_id = p.player_id
                 WHERE p.joined_season_id = ? AND p.participation_enabled = 1
                 ORDER BY COALESCE(sp.seasonal_stars, 0) DESC, p.player_id ASC{$limitClause}",
                $limit > 0
                    ? [$seasonId, $seasonId, $gameTime, $seasonId, $limit]
                    : [$seasonId, $seasonId, $gameTime, $seasonId]
            );
            if (empty($rows)) {
                $rows = $db->fetchAll(
                    "SELECT p.player_id, p.handle,
                            COALESCE(sp.seasonal_stars, 0) AS seasonal_stars,
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
                     ORDER BY COALESCE(sp.seasonal_stars, 0) DESC, sp.player_id ASC{$limitClause}",
                    $limit > 0
                        ? [$seasonId, $gameTime, $seasonId, $limit]
                        : [$seasonId, $gameTime, $seasonId]
                );
            }
        } else {
            $rows = $db->fetchAll(
                "SELECT p.player_id, p.handle,
                        COALESCE(sp.seasonal_stars, 0) AS seasonal_stars,
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
                 FROM players p
                 LEFT JOIN season_participation sp ON sp.player_id = p.player_id AND sp.season_id = ?
                 WHERE p.joined_season_id = ? AND p.participation_enabled = 1
                 ORDER BY COALESCE(sp.seasonal_stars, 0) DESC, p.player_id ASC{$limitClause}",
                $limit > 0
                    ? [$seasonId, $seasonId, $limit]
                    : [$seasonId, $seasonId]
            );
            if (empty($rows)) {
                $rows = $db->fetchAll(
                    "SELECT p.player_id, p.handle,
                            COALESCE(sp.seasonal_stars, 0) AS seasonal_stars,
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
                     ORDER BY COALESCE(sp.seasonal_stars, 0) DESC, sp.player_id ASC{$limitClause}",
                    $limit > 0
                        ? [$seasonId, $limit]
                        : [$seasonId]
                );
            }
        }
        foreach ($rows as &$row) {
            $metrics = calculateLeaderboardRowMetrics($season, $row);
            $row['rate_per_tick'] = (float)$metrics['rate_per_tick'];
            $row['boost_pct'] = (float)$metrics['boost_pct'];
            unset($row['boost_mod_fp']);
        }
        unset($row);
        return $rows;
    }

    $rows = $db->fetchAll(
        "SELECT sp.player_id, p.handle, sp.seasonal_stars, COALESCE(sp.coins, 0) AS coins, sp.final_rank,
                sp.lock_in_effect_tick, sp.end_membership, sp.badge_awarded,
                sp.global_stars_earned, sp.participation_bonus, sp.placement_bonus,
                p.activity_state, p.online_current,
                0 AS is_frozen,
                0.0 AS boost_pct
         FROM season_participation sp
         JOIN players p ON p.player_id = sp.player_id
         WHERE sp.season_id = ?
         ORDER BY sp.seasonal_stars DESC, sp.player_id ASC{$limitClause}",
        $limit > 0 ? [$seasonId, $limit] : [$seasonId]
    );
    foreach ($rows as &$row) {
        $row['rate_per_tick'] = 0;
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

function getGlobalLeaderboard() {
    $db = Database::getInstance();
    return $db->fetchAll(
        "SELECT player_id, handle, global_stars, activity_state, online_current
         FROM players 
         WHERE global_stars > 0 AND profile_deleted_at IS NULL
         ORDER BY global_stars DESC, player_id ASC"
    );
}

function getMyTrades($player) {
    $db = Database::getInstance();
    if (!$player['joined_season_id']) return [];
    
    return $db->fetchAll(
        "SELECT t.*, 
                pi.handle as initiator_handle,
                pa.handle as acceptor_handle
         FROM trades t
         JOIN players pi ON pi.player_id = t.initiator_id
         JOIN players pa ON pa.player_id = t.acceptor_id
         WHERE t.season_id = ? AND (t.initiator_id = ? OR t.acceptor_id = ?)
         ORDER BY t.created_at DESC LIMIT 20",
        [$player['joined_season_id'], $player['player_id'], $player['player_id']]
    );
}

function getSeasonPlayers($seasonId) {
    $db = Database::getInstance();
    return $db->fetchAll(
        "SELECT p.player_id, p.handle, p.activity_state, p.online_current
         FROM players p
         WHERE p.joined_season_id = ? AND p.participation_enabled = 1
         ORDER BY p.handle ASC",
        [$seasonId]
    );
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
         ORDER BY pc.purchased_at DESC",
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
    if ($channelKind === 'SEASON') {
        if (!$player['joined_season_id']) return ['error' => 'Not in a season'];
        $seasonId = $player['joined_season_id'];
    }
    if ($channelKind === 'DM' && !$recipientId) return ['error' => 'Recipient required for DM'];
    
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
    $seasonId = $input['season_id'] ?? null;
    
    if ($channelKind === 'GLOBAL') {
        $messages = $db->fetchAll(
            "SELECT message_id, sender_id, handle_snapshot, content, is_admin_post, is_removed, created_at
             FROM chat_messages 
             WHERE channel_kind = 'GLOBAL' AND is_removed = 0
             ORDER BY created_at DESC LIMIT ?",
            [CHAT_MAX_ROWS]
        );
        foreach ($messages as &$m) {
            $m['created_at'] = iso_utc_datetime($m['created_at'] ?? null);
        }
        return $messages;
    }
    
    if ($channelKind === 'SEASON' && $seasonId) {
        $messages = $db->fetchAll(
            "SELECT message_id, sender_id, handle_snapshot, content, is_removed, created_at
             FROM chat_messages 
             WHERE channel_kind = 'SEASON' AND season_id = ? AND is_removed = 0
             ORDER BY created_at DESC LIMIT ?",
            [$seasonId, CHAT_MAX_ROWS]
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
    $target = $db->fetch(
        "SELECT player_id, handle, role, global_stars, profile_visibility, created_at, profile_deleted_at,
                joined_season_id, participation_enabled
         FROM players WHERE player_id = ?",
        [$targetId]
    );
    if (!$target) return ['error' => 'Player not found'];
    
    if ($target['profile_deleted_at']) {
        return ['player_id' => $target['player_id'], 'handle' => '[Removed]', 'deleted' => true];
    }
    
    // Get badges
    $badges = $db->fetchAll(
        "SELECT * FROM badges WHERE player_id = ? ORDER BY awarded_at DESC",
        [$targetId]
    );
    
    // Get season history
    $history = $db->fetchAll(
        "SELECT sp.*, s.start_time, s.end_time
         FROM season_participation sp
         JOIN seasons s ON s.season_id = sp.season_id
         WHERE sp.player_id = ? AND (sp.end_membership = 1 OR sp.lock_in_effect_tick IS NOT NULL)
         ORDER BY s.start_time DESC LIMIT 20",
        [$targetId]
    );
    
    $target['badges'] = $badges;
    $target['season_history'] = $history;
    $target['active_participation'] = null;
    // Normalise DATETIME to ISO 8601 UTC so JS Date() parses it unambiguously.
    $target['created_at'] = iso_utc_datetime($target['created_at'] ?? null);

    if (!empty($target['joined_season_id']) && (int)$target['participation_enabled'] === 1) {
        $season = $db->fetch("SELECT * FROM seasons WHERE season_id = ?", [$target['joined_season_id']]);
        if ($season) {
            $status = GameTime::getSeasonStatus($season);
            if ($status === 'Active') {
                $participation = $db->fetch(
                    "SELECT coins, sigils_t1, sigils_t2, sigils_t3, sigils_t4, sigils_t5, sigils_t6
                     FROM season_participation
                     WHERE player_id = ? AND season_id = ?",
                    [$targetId, $target['joined_season_id']]
                );
                if ($participation) {
                    $profileBoosts = getActiveBoosts($target);
                    $profilePrimaryBoost = (isset($profileBoosts['self'][0]) && is_array($profileBoosts['self'][0]))
                        ? $profileBoosts['self'][0]
                        : null;
                    $profileFreeze = getFreezeStatusForPlayer((int)$targetId, (int)$target['joined_season_id']);
                    $target['active_participation'] = [
                        'season_id' => (int)$target['joined_season_id'],
                        'coins' => (int)$participation['coins'],
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
    return $db->fetchAll(
        "SELECT sp.*, s.start_time, s.end_time, s.status as season_status
         FROM season_participation sp
         JOIN seasons s ON s.season_id = sp.season_id
         WHERE sp.player_id = ?
         ORDER BY s.start_time DESC LIMIT 50",
        [$player['player_id']]
    );
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

function getActiveBoosts($player) {
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
    
    $selfBoosts = $db->fetchAll(
        "SELECT ab.*, bc.name, bc.description, bc.tier_required, bc.icon
         FROM active_boosts ab
         JOIN boost_catalog bc ON bc.boost_id = ab.boost_id
         WHERE ab.player_id = ? AND ab.season_id = ? AND ab.is_active = 1 AND ab.scope = 'SELF' AND ab.expires_tick >= ?
         ORDER BY ab.expires_tick DESC, ab.id ASC
         LIMIT 1",
        [$player['player_id'], $seasonId, $gameTime]
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

function getRecentSigilDrops($player) {
    $db = Database::getInstance();
    if (!$player['joined_season_id']) return [];
    
    $seasonId = $player['joined_season_id'];
    
    $drops = $db->fetchAll(
        "SELECT sdl.tier, sdl.source, sdl.drop_tick, sdl.created_at, pn.payload_json,
                s.start_time AS season_start_time, s.end_time AS season_end_time
         FROM sigil_drop_log sdl
         JOIN seasons s ON s.season_id = sdl.season_id
         LEFT JOIN player_notifications pn
           ON pn.player_id = sdl.player_id
          AND pn.event_key = CONCAT('sigil_drop:', sdl.season_id, ':', sdl.drop_tick, ':', sdl.tier, ':', LOWER(sdl.source))
         WHERE sdl.player_id = ? AND sdl.season_id = ?
         ORDER BY drop_tick DESC LIMIT 20",
        [$player['player_id'], $seasonId]
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

function isPlayerFrozen($playerId, $seasonId) {
    $db = Database::getInstance();
    $gameTime = GameTime::now();
    $row = $db->fetch(
        "SELECT COUNT(*) AS cnt FROM active_freezes WHERE target_player_id = ? AND season_id = ? AND is_active = 1 AND expires_tick >= ?",
        [(int)$playerId, (int)$seasonId, (int)$gameTime]
    );
    return ((int)($row['cnt'] ?? 0)) > 0;
}

function getFreezeStatusForPlayer($playerId, $seasonId) {
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
    $row = $db->fetch(
        "SELECT expires_tick FROM active_freezes
         WHERE target_player_id = ? AND season_id = ? AND is_active = 1 AND expires_tick >= ?
         ORDER BY expires_tick DESC LIMIT 1",
        [(int)$playerId, (int)$seasonId, (int)$gameTime]
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
    if ($status === 'Blackout') {
        return ['error' => 'Star purchases are not available during blackout', 'reason_code' => 'blackout_disallows_action'];
    }

    if ($starsRequested <= 0) {
        return ['error' => 'Must request a positive star quantity'];
    }

    $participation = $db->fetch(
        "SELECT * FROM season_participation WHERE player_id = ? AND season_id = ?",
        [$playerId, $seasonId]
    );

    $starPrice = (int)$season['current_star_price'];
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
 * Preview a trade without executing it.
 */
function previewTrade(
    array $player,
    int $acceptorId,
    int $sideACoins,
    array $sideASigils,
    int $sideBCoins,
    array $sideBSigils
): array {
    $db = Database::getInstance();
    $playerId = (int)$player['player_id'];
    $fullPlayer = $db->fetch("SELECT * FROM players WHERE player_id = ?", [$playerId]);

    if (!$fullPlayer['participation_enabled'] || !$fullPlayer['joined_season_id']) {
        return ['error' => 'Not participating in any season'];
    }
    if ($fullPlayer['idle_modal_active']) {
        return ['error' => 'Cannot trade while idle', 'reason_code' => 'idle_gated'];
    }

    $seasonId = (int)$fullPlayer['joined_season_id'];
    $season = $db->fetch("SELECT * FROM seasons WHERE season_id = ?", [$seasonId]);
    $status = GameTime::getSeasonStatus($season);
    if ($status === 'Blackout') {
        return ['error' => 'Trading is not available during blackout', 'reason_code' => 'blackout_disallows_action'];
    }

    $participation = $db->fetch(
        "SELECT * FROM season_participation WHERE player_id = ? AND season_id = ?",
        [$playerId, $seasonId]
    );

    $aCoins = max(0, (int)$sideACoins);
    $bCoins = max(0, (int)$sideBCoins);
    $aSigils = normalizeSigilCounts($sideASigils);
    $bSigils = normalizeSigilCounts($sideBSigils);
    $declaredValue = Economy::calculateTradeValue($season, $aCoins, $aSigils, $bCoins, $bSigils);
    $fee = Economy::calculateTradeFee($season, $declaredValue);
    $totalCost = $aCoins + $fee;
    // Both initiator and acceptor pay the same locked fee on accept.
    $estimatedBurn = $fee * 2;

    $coins = (int)$participation['coins'];
    $totalSupply = max(1, (int)$season['total_coins_supply']);
    $priceImpactBp = (int)round(($estimatedBurn / $totalSupply) * 10000);

    $spendFraction = $coins > 0 ? min(1.0, $totalCost / $coins) : 1.0;
    $extraFlags = [];
    if ($fee > 0) $extraFlags[] = 'fee_applies';
    $risk = computeEconomicRisk($spendFraction, $extraFlags);

    $payload = buildPreviewPayload(
        $totalCost,
        $fee,
        $priceImpactBp,
        $coins - $totalCost,
        $risk
    );
    $payload['declared_value'] = $declaredValue;
    $payload['side_a_coins'] = $aCoins;
    $payload['side_b_coins'] = $bCoins;
    $payload['estimated_burn_on_accept'] = $estimatedBurn;
    $payload['coins_escrowed'] = $aCoins;
    $payload['coins_available'] = $coins;

    return array_merge(['success' => true, 'preview_type' => 'trade'], $payload);
}

/**
 * Gated trade initiate: runs preview first; blocks medium/high risk without confirm flag.
 */
function gatedTradeInitiate(
    array $player,
    int $acceptorId,
    int $sideACoins,
    array $sideASigils,
    int $sideBCoins,
    array $sideBSigils,
    bool $confirmed
): array {
    $preview = previewTrade($player, $acceptorId, $sideACoins, $sideASigils, $sideBCoins, $sideBSigils);
    if (!empty($preview['error'])) return $preview;

    if ($preview['requires_explicit_confirm'] && !$confirmed) {
        return [
            'error' => 'confirmation_required',
            'reason_code' => 'confirmation_required',
            'message' => 'This trade has medium or high economic impact. Send confirm_economic_impact=1 to proceed.',
            'preview' => $preview,
        ];
    }

    $result = Actions::tradeInitiate($player['player_id'], $acceptorId, $sideACoins, $sideASigils, $sideBCoins, $sideBSigils);
    if (!empty($result['error']) && !empty($confirmed)) {
        $insufficientErrors = [
            'Insufficient coins',
            'Insufficient coins to cover trade fee',
            'Insufficient Tier 1 Sigils',
            'Insufficient Tier 2 Sigils',
            'Insufficient Tier 3 Sigils',
            'Insufficient Tier 4 Sigils',
            'Insufficient Tier 5 Sigils',
            'Insufficient Tier 6 Sigils',
        ];
        if (in_array((string)$result['error'], $insufficientErrors, true)) {
            $latestPreview = previewTrade($player, $acceptorId, $sideACoins, $sideASigils, $sideBCoins, $sideBSigils);
            return [
                'error' => 'balance_changed',
                'reason_code' => 'balance_changed',
                'message' => 'Your balance or inventory changed since confirmation. Please review and confirm again.',
                'preview' => $latestPreview,
                'prior_error' => $result['error'],
            ];
        }
    }
    if (!empty($result['success'])) {
        $result['receipt'] = [
            'executed_total_cost'      => (int)($sideACoins) + (int)($result['fee'] ?? 0),
            'executed_fee'             => (int)($result['fee'] ?? 0),
            'executed_price_impact_bp' => $preview['estimated_price_impact_bp'],
            'executed_price_impact_pct' => $preview['estimated_price_impact_pct'],
            'post_balance_estimate'    => max(0, $preview['coins_available'] - (int)$sideACoins - (int)($result['fee'] ?? 0)),
            'declared_value'           => (int)($result['declared_value'] ?? 0),
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
            return ['error' => 'Tier 6 sigils cannot be used for +Power/+Time'];
        }
        return ['error' => 'Invalid sigil tier'];
    }
    $seasonId = (int)$fullPlayer['joined_season_id'];
    $season = $db->fetch("SELECT * FROM seasons WHERE season_id = ?", [$seasonId]);
    $status = GameTime::getSeasonStatus($season);
    if ($status === 'Blackout' || $status !== 'Active') {
        return ['error' => 'Boost activation is only available during active season', 'reason_code' => 'blackout_disallows_action'];
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
    $timeCapTicks = ticks_from_real_seconds(BoostCatalog::TIME_CAP_SECONDS_PER_PRODUCT);

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
        "SELECT * FROM active_boosts WHERE player_id = ? AND season_id = ? AND scope = 'SELF' AND is_active = 1 AND expires_tick >= ? ORDER BY expires_tick DESC, id ASC",
        [$playerId, $seasonId, $gameTime]
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
           AND scope = 'SELF' AND player_id = ?",
        [$seasonId, $gameTime, $playerId]
    );
    $combinedCurrentFp = max(0, (int)($combinedRow['total_fp'] ?? 0));

    if (!$activeRow) {
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
        $currentExpiresTick = max($gameTime, (int)$activeRow['expires_tick']);
        $maxExpiresTick = $gameTime + $timeCapTicks;
        if ($currentExpiresTick >= $maxExpiresTick) {
            return ['error' => 'Maximum boost time reached (48h)'];
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
            'executed_total_cost'      => (int)($result['sigils_consumed'] ?? 0),
            'executed_fee'             => 0,
            'executed_price_impact_bp' => 0,
            'executed_price_impact_pct' => 0.0,
            'post_balance_estimate'    => (int)$preview['post_balance_estimate'],
            'tier_consumed'            => (int)($result['tier_consumed'] ?? 0),
            'sigils_consumed'          => (int)($result['sigils_consumed'] ?? 0),
        ];
    }
    return $result;
}
