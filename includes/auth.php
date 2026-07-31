<?php
/**
 * Too Many Coins - Authentication
 */
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/economy.php';

class Auth {
    /**
     * A throwaway hash used to spend the same time on a failed login as on a
     * successful one.
     *
     * Computed at the CURRENT default cost rather than hardcoded, because a
     * hardcoded one drifts: a literal cost-10 hash measured 59ms here against
     * 232ms for a real password at PHP 8.4's default cost of 12, which is a
     * four-fold difference and just as usable an oracle as no dummy at all.
     * Deriving it costs one bcrypt per process, on the failed-login path only.
     */
    private static ?string $dummyHash = null;

    private static function dummyPasswordHash(): string {
        if (self::$dummyHash === null) {
            self::$dummyHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
        }
        return self::$dummyHash;
    }


    private static $presenceTouchedInRequest = [];

    /**
     * True when the request reached us over TLS, including via a terminating
     * proxy (Dokploy/nginx/Apache front ends set X-Forwarded-Proto).
     * Used to set the Secure cookie flag without breaking plain-HTTP local dev.
     */
    private static function requestIsHttps(): bool {
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        if ($forwardedProto !== '') {
            // May be a comma-separated chain; the client-facing scheme is first.
            $first = strtolower(trim(explode(',', (string)$forwardedProto)[0]));
            if ($first === 'https') {
                return true;
            }
        }
        return ((int)($_SERVER['SERVER_PORT'] ?? 0)) === 443;
    }

    /**
     * Single place that writes the session cookie, so the security attributes
     * cannot drift between the register and login paths.
     */
    private static function setSessionCookie(string $token, int $expires): void {
        setcookie('tmc_session', $token, [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => self::requestIsHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    
    /**
     * Get current authenticated player from session token
     */
    public static function getCurrentPlayer() {
        $token = $_COOKIE['tmc_session'] ?? ($_SERVER['HTTP_X_SESSION_TOKEN'] ?? null);
        if (!$token) return null;
        
        $db = Database::getInstance();
        $player = $db->fetch(
            "SELECT * FROM players WHERE session_token = ? AND profile_deleted_at IS NULL",
            [$token]
        );

        if (!$player && TMC_AUTH_TRACE) {
            error_log('[auth] token_miss action=' . ($_REQUEST['action'] ?? 'unknown'));
        }
        
        if ($player) {
            self::touchPresence($player['player_id'], $player);
        }
        
        return $player;
    }

    /**
     * Does this session token belong to a real account?
     *
     * Existence only - no presence touch, no player row returned, and it is
     * deliberately callable before the request is dispatched. The rate limiter
     * needs to know whether a caller-supplied token is genuine before it
     * decides which bucket to charge, and using getCurrentPlayer() for that
     * would mark a forged token's "session" as online on every probe.
     */
    public static function sessionTokenExists($token): bool {
        if (!is_string($token) || $token === '') return false;
        try {
            $row = Database::getInstance()->fetch(
                "SELECT 1 AS ok FROM players WHERE session_token = ? AND profile_deleted_at IS NULL LIMIT 1",
                [$token]
            );
            return !empty($row);
        } catch (Throwable $e) {
            // A database problem must not decide the rate limit, in either
            // direction. Throwing here would take down every request including
            // the ones that need no database at all, and it would skip the
            // limiter entirely - so an attacker who can make the database
            // wobble would get unmetered access at the same moment the server
            // is least able to absorb it. Unknown token -> the smaller
            // anonymous allowance, and the endpoint reports the real fault.
            return false;
        }
    }

    /**
     * Mark player as online with throttling to avoid request-path write contention.
     */
    public static function touchPresence($playerId, $player = null) {
        $playerId = (int)$playerId;
        if ($playerId <= 0) {
            return;
        }

        if (isset(self::$presenceTouchedInRequest[$playerId])) {
            return;
        }
        self::$presenceTouchedInRequest[$playerId] = true;

        if (self::shouldDeferPresenceRefresh($player)) {
            return;
        }

        $touchEverySeconds = max(5, (int)TMC_PRESENCE_TOUCH_SECONDS);
        $db = Database::getInstance();
        $db->query(
            "UPDATE players
             SET last_seen_at = NOW(), online_current = 1
             WHERE player_id = ?
               AND (
                   online_current = 0
                   OR last_seen_at IS NULL
                   OR TIMESTAMPDIFF(SECOND, last_seen_at, NOW()) >= ?
               )",
            [$playerId, $touchEverySeconds]
        );
    }

    private static function currentActionName() {
        return strtolower(trim((string)($_REQUEST['action'] ?? '')));
    }

    private static function actionAllowsPresenceRecovery($action) {
        return in_array((string)$action, ['idle_ack'], true);
    }

    private static function shouldDeferPresenceRefresh($player) {
        if (!is_array($player)) {
            return false;
        }

        if (!empty($player['online_current'])) {
            return false;
        }

        if (empty($player['idle_modal_active'])) {
            return false;
        }

        if (!Economy::presenceIsStale($player)) {
            return false;
        }

        return !self::actionAllowsPresenceRecovery(self::currentActionName());
    }
    
    /**
     * Register a new player
     */
    public static function register($handle, $email, $password) {
        $db = Database::getInstance();
        
        // Sanitize inputs
        $handle = trim($handle);
        $email = trim(strtolower($email));
        
        // Validate password length
        if (strlen($password) < 6) {
            return ['error' => 'Password must be at least 6 characters'];
        }
        if (strlen($password) > 128) {
            return ['error' => 'Password is too long'];
        }
        
        // Validate handle
        $error = self::validateHandle($handle);
        if ($error) return ['error' => $error];
        
        // Validate email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['error' => 'Invalid email address'];
        }
        if (strlen($email) > 255) {
            return ['error' => 'Email is too long'];
        }
        
        // Check email uniqueness
        $existing = $db->fetch("SELECT player_id FROM players WHERE email = ?", [$email]);
        if ($existing) return ['error' => 'Email already registered'];
        
        // Check handle uniqueness (including historical)
        $handleLower = strtolower($handle);
        $existingHandle = $db->fetch("SELECT handle_lower FROM handle_registry WHERE handle_lower = ?", [$handleLower]);
        if ($existingHandle) return ['error' => 'Handle is already taken or was previously used'];
        
        $existingPlayer = $db->fetch("SELECT player_id FROM players WHERE handle_lower = ?", [$handleLower]);
        if ($existingPlayer) return ['error' => 'Handle is already taken'];
        
        // Create player
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $token = bin2hex(random_bytes(32));
        
        $db->beginTransaction();
        try {
            $playerId = $db->insert(
                "INSERT INTO players (handle, handle_lower, email, password_hash, session_token, online_current, last_seen_at)
                 VALUES (?, ?, ?, ?, ?, 1, NOW())",
                [$handle, $handleLower, $email, $hash, $token]
            );
            
            // Register handle
            $db->query(
                "INSERT INTO handle_registry (handle_lower, player_id) VALUES (?, ?)",
                [$handleLower, $playerId]
            );
            
            $db->commit();
            
            self::setSessionCookie($token, time() + SESSION_LIFETIME);
            
            return [
                'success' => true,
                'player_id' => $playerId,
                'handle' => $handle,
                'token' => $token
            ];
        } catch (Exception $e) {
            $db->rollback();
            // The driver's message goes to the log, not to the caller. It
            // carries table and column names, and on a constraint violation it
            // quotes the offending value straight back - which on this path is
            // an email address, to an unauthenticated caller.
            error_log('[auth] registration failed: ' . $e->getMessage());
            return ['error' => 'Registration failed. Please try again.'];
        }
    }
    
    /**
     * Login
     */
    public static function login($email, $password) {
        $db = Database::getInstance();
        
        $player = $db->fetch(
            "SELECT * FROM players WHERE email = ? AND profile_deleted_at IS NULL",
            [$email]
        );
        
        // The message is already uniform for "no such account" and "wrong
        // password", but the TIME was not: password_verify only ran when the
        // account existed, so a missing account answered in a fraction of the
        // time a real one took. Bcrypt is deliberately slow, which makes that
        // difference large and easy to measure - a usable oracle for whether
        // an address has an account here.
        //
        // Verifying against a fixed dummy hash costs the same work on the
        // miss path, so both answers take comparable time.
        if (!$player) {
            password_verify($password, self::dummyPasswordHash());
            return ['error' => 'Invalid email or password'];
        }
        if (!password_verify($password, $player['password_hash'])) {
            return ['error' => 'Invalid email or password'];
        }
        
        $token = bin2hex(random_bytes(32));
        $db->query(
            "UPDATE players SET session_token = ?, online_current = 1, last_seen_at = NOW(), 
             connection_seq = connection_seq + 1 WHERE player_id = ?",
            [$token, $player['player_id']]
        );
        
        self::setSessionCookie($token, time() + SESSION_LIFETIME);
        
        return [
            'success' => true,
            'player_id' => $player['player_id'],
            'handle' => $player['handle'],
            'token' => $token
        ];
    }
    
    /**
     * Logout
     */
    public static function logout() {
        $player = self::getCurrentPlayer();
        if ($player) {
            $db = Database::getInstance();
            $db->query(
                "UPDATE players SET session_token = NULL, online_current = 0 WHERE player_id = ?",
                [$player['player_id']]
            );
        }
        self::setSessionCookie('', time() - 3600);
        return ['success' => true];
    }
    
    /**
     * Validate handle format
     */
    public static function validateHandle($handle) {
        if (strlen($handle) < HANDLE_MIN_LENGTH) return 'Handle must be at least ' . HANDLE_MIN_LENGTH . ' characters';
        if (strlen($handle) > HANDLE_MAX_LENGTH) return 'Handle must be at most ' . HANDLE_MAX_LENGTH . ' characters';
        if (!preg_match(HANDLE_PATTERN, $handle)) return 'Handle may only contain letters, numbers, and underscores';
        if (in_array(strtolower($handle), RESERVED_HANDLES)) return 'This handle is reserved';
        return null;
    }
    
    /**
     * Require authentication, return player or send error
     */
    public static function requireAuth() {
        $player = self::getCurrentPlayer();
        if (!$player) {
            if (TMC_AUTH_TRACE) {
                error_log('[auth] require_auth_401 action=' . ($_REQUEST['action'] ?? 'unknown'));
            }
            http_response_code(401);
            echo json_encode(['error' => 'Authentication required']);
            exit;
        }
        return $player;
    }
}
