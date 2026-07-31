#!/usr/bin/env php
<?php
/**
 * Too Many Coins - Email Verification Self-Check
 *
 *   php tools/email_verification_selfcheck.php --base=http://127.0.0.1:8000
 *
 * An unconfirmed account may do four things: leave, confirm, ask for another
 * link, and read enough state for the client to say so. Everything else is
 * refused. That is a gate on the whole account, so the checks here are mostly
 * about what must NOT work - a gate that only proves the happy path is a gate
 * nobody has tested.
 *
 * Covers the token's own rules too: single use, expiry, and that a wrong or
 * absent token confirms nothing.
 *
 * Needs a running server and DB_* in the environment. Registers throwaway
 * accounts; clean up with:
 *   php tools/purge_test_accounts.php --handle=ev_* --apply
 *
 * Exit: 0 = every check passed, 1 = at least one is wrong.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/account.php';

$opts = getopt('', ['base::', 'verbose']);
$base = rtrim($opts['base'] ?? 'http://127.0.0.1:8000', '/');
$api = $base . '/api/index.php';

$pass = 0;
$fail = 0;

function check(string $name, bool $ok, $detail = null): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  pass  {$name}\n"; return; }
    $fail++;
    echo "  FAIL  {$name}";
    if ($detail !== null) echo "\n          " . json_encode($detail);
    echo "\n";
}

function section(string $name): void { echo "\n{$name}\n"; }

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

/** A brand-new, deliberately unconfirmed account. */
function newAccount(string $tag): array {
    $handle = 'ev_' . $tag . '_' . substr(bin2hex(random_bytes(3)), 0, 4);
    $email = $handle . '@example.test';
    $res = req('register', ['handle' => $handle, 'email' => $email, 'password' => 'verifycheck123']);
    $token = $res['body']['token'] ?? null;
    if (!$token) {
        fwrite(STDERR, "could not register {$handle}: " . $res['raw'] . "\n");
        exit(1);
    }
    return ['handle' => $handle, 'email' => $email, 'token' => $token, 'register' => $res['body']];
}

/**
 * Issue a link and read the raw token out of it.
 *
 * The raw value is never returned by the API and never stored - only its
 * SHA-256 - which is the correct design and leaves a test with one honest way
 * to obtain it: send a real message with the dev-log transport pointed at a
 * file we own, and read the link the player would have received. That also
 * checks the mail body actually carries a usable link, which asserting on the
 * database could not.
 */
function issueLinkAndReadToken(array $player): ?string {
    $logFile = sys_get_temp_dir() . '/tmc_verify_mail_' . bin2hex(random_bytes(4)) . '.log';
    $previous = ini_get('error_log');
    ini_set('error_log', $logFile);
    AccountService::sendEmailVerification($player);
    ini_set('error_log', $previous === false ? '' : $previous);

    $contents = @file_get_contents($logFile);
    @unlink($logFile);
    if ($contents === false) return null;
    if (!preg_match('/verify_action=EMAIL_VERIFY&token=([a-f0-9]{64})/', $contents, $m)) return null;
    return $m[1];
}

echo "Email verification self-check\n";
echo "  base: {$base}\n";

$db = Database::getInstance();

section('a fresh account is unconfirmed and told so');
$a = newAccount('gate');
$playerRow = $db->fetch("SELECT * FROM players WHERE handle_lower = LOWER(?)", [$a['handle']]);
check('registration does not confirm the address by itself', empty($playerRow['email_verified_at']));
check('registration reports whether the mail went out',
    array_key_exists('verification_sent', $a['register']), $a['register']);

$state = req('game_state', [], $a['token']);
check('game_state stays readable so the client can render the prompt', $state['status'] === 200);
check('the poll publishes email_verified=false',
    ($state['body']['player']['email_verified'] ?? null) === false,
    $state['body']['player'] ?? null);
check('the poll publishes the address the link went to',
    ($state['body']['player']['email'] ?? '') === $a['email']);

section('everything else is refused, not merely filtered');
foreach ([
    'season_join'        => ['season_id' => 1],
    'chat_send'          => ['message' => 'hello', 'channel_kind' => 'GLOBAL'],
    'purchase_stars'     => ['season_id' => 1, 'quantity' => 1],
    'profile'            => ['handle' => $a['handle']],
    'notifications_list' => [],
    'friends_list'       => [],
    'cosmetic_catalog'   => [],
    'leaderboard'        => ['season_id' => 1],
] as $action => $body) {
    $res = req($action, $body, $a['token']);
    check("{$action} is refused for an unconfirmed account",
        $res['status'] === 403 && ($res['body']['reason_code'] ?? '') === 'email_verification_required',
        ['status' => $res['status'], 'body' => $res['body']]);
}

section('the four things it may still do');
check('logout is allowed', req('logout', [], $a['token'])['status'] === 200);
$a2 = newAccount('flow');
check('game_state is allowed', req('game_state', [], $a2['token'])['status'] === 200);
check('account_get is allowed', req('account_get', [], $a2['token'])['status'] === 200);
$resend = req('email_verify_resend', [], $a2['token']);
check('email_verify_resend is allowed', $resend['status'] === 200 && !isset($resend['body']['error']),
    $resend['body']);

section('the token decides, and it decides once');
$row = $db->fetch("SELECT * FROM players WHERE handle_lower = LOWER(?)", [$a2['handle']]);
$raw = issueLinkAndReadToken($row);
check('the emailed message carries a usable confirmation link', $raw !== null);

check('an absent token confirms nothing',
    (req('email_verify_confirm', ['token' => ''])['body']['reason_code'] ?? '') === 'verification_token_invalid');
check('a wrong token confirms nothing',
    (req('email_verify_confirm', ['token' => str_repeat('a', 64)])['body']['reason_code'] ?? '')
        === 'verification_token_invalid');

$stillBlocked = req('friends_list', [], $a2['token']);
check('still blocked while the link is unused', $stillBlocked['status'] === 403);

$confirm = req('email_verify_confirm', ['token' => (string)$raw]);
check('the link confirms the address', ($confirm['body']['success'] ?? false) === true, $confirm['body']);
check('confirmation is not authenticated - the token is the credential',
    ($confirm['body']['handle'] ?? '') === $a2['handle'], $confirm['body']);

$replay = req('email_verify_confirm', ['token' => (string)$raw]);
check('the same link cannot be used twice',
    ($replay['body']['reason_code'] ?? '') === 'verification_token_invalid', $replay['body']);

section('confirmed accounts are ordinary again');
$after = req('game_state', [], $a2['token']);
check('the poll now publishes email_verified=true',
    ($after['body']['player']['email_verified'] ?? null) === true);
check('a previously refused action is allowed',
    req('friends_list', [], $a2['token'])['status'] === 200);

section('expiry is enforced, not merely recorded');
$c = newAccount('expiry');
$rowC = $db->fetch("SELECT * FROM players WHERE handle_lower = LOWER(?)", [$c['handle']]);
$rawC = issueLinkAndReadToken($rowC);
$db->query(
    "UPDATE account_verification_tokens SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE)
     WHERE token_hash = ? AND action_type = 'EMAIL_VERIFY'",
    [hash('sha256', (string)$rawC)]
);
$expired = req('email_verify_confirm', ['token' => (string)$rawC]);
check('an expired link is refused',
    ($expired['body']['reason_code'] ?? '') === 'verification_token_invalid', $expired['body']);
check('and the account stays unconfirmed',
    empty($db->fetch("SELECT email_verified_at FROM players WHERE player_id = ?",
        [(int)$rowC['player_id']])['email_verified_at']));

echo "\n--------------------------------------------------------------\n";
echo "{$pass} passed, {$fail} failed\n";
echo $fail === 0
    ? "Result: PASS - unconfirmed accounts can do nothing but confirm.\n"
    : "Result: FAIL\n";
exit($fail === 0 ? 0 : 1);
