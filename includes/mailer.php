<?php
/**
 * Too Many Coins - Mailer
 *
 * Account verification, deletion confirmation and staff notices go out through
 * here. The container image ships no MTA, so PHP mail() has no transport and
 * fails silently; this sends over SMTP directly instead, with no new system
 * dependency and no change to the image.
 *
 * Behaviour, in order:
 *   1. TMC_MAIL_DEV_LOG=true  -> log the message, send nothing (dev/test lanes)
 *   2. TMC_SMTP_HOST set      -> send over SMTP
 *   3. otherwise              -> legacy mail() (works only where an MTA exists)
 *
 * Verify inside the running container before opening signups:
 *   php /app/tools/mail_selftest.php you@example.com
 */
require_once __DIR__ . '/config.php';

class Mailer {
    /** @var string[] transcript of the last SMTP conversation (trace only) */
    public static $lastTrace = [];
    public static $lastError = '';

    public static function send(string $to, string $subject, string $body): bool {
        self::$lastTrace = [];
        self::$lastError = '';

        if (TMC_MAIL_DEV_LOG) {
            error_log('[mail-dev] to=' . $to . ' subject=' . $subject . ' body=' . str_replace(["\r", "\n"], ' | ', $body));
            return true;
        }

        if (TMC_SMTP_HOST !== '') {
            if (self::sendSmtp($to, $subject, $body)) {
                return true;
            }
            error_log('[mail] smtp send failed to=' . $to . ' error=' . self::$lastError);
            if (!TMC_SMTP_FALLBACK_TO_MAIL) {
                return false;
            }
        }

        $headers = [
            'From: ' . self::fromHeader(),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
        ];
        $ok = mail($to, $subject, $body, implode("\r\n", $headers));
        if (!$ok) {
            self::$lastError = self::$lastError ?: 'mail() returned false (no MTA in this container?)';
            error_log('[mail] mail() transport failed to=' . $to);
        }
        return $ok;
    }

    private static function fromHeader(): string {
        $name = trim((string)TMC_MAIL_FROM_NAME);
        $addr = trim((string)TMC_MAIL_FROM);
        if ($name === '') return $addr;
        return '=?UTF-8?B?' . base64_encode($name) . '?= <' . $addr . '>';
    }

    private static function sendSmtp(string $to, string $subject, string $body): bool {
        $secure = TMC_SMTP_SECURE;          // tls | ssl | none
        $port = (int)TMC_SMTP_PORT;
        $host = TMC_SMTP_HOST;
        $endpoint = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;

        $context = stream_context_create([
            'ssl' => ['SNI_enabled' => true, 'peer_name' => $host],
        ]);
        $errno = 0; $errstr = '';
        $socket = @stream_socket_client(
            $endpoint,
            $errno,
            $errstr,
            (int)TMC_SMTP_TIMEOUT_SECONDS,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!$socket) {
            self::$lastError = 'connect failed: ' . $errstr . ' (' . $errno . ')';
            return false;
        }
        stream_set_timeout($socket, (int)TMC_SMTP_TIMEOUT_SECONDS);

        try {
            if (!self::expect($socket, 220)) return false;

            $ehloHost = self::heloName();
            if (!self::command($socket, 'EHLO ' . $ehloHost, 250)) return false;

            if ($secure === 'tls') {
                if (!self::command($socket, 'STARTTLS', 220)) return false;
                $crypto = @stream_socket_enable_crypto(
                    $socket,
                    true,
                    STREAM_CRYPTO_METHOD_TLS_CLIENT
                );
                if ($crypto !== true) {
                    self::$lastError = 'STARTTLS negotiation failed';
                    return false;
                }
                if (!self::command($socket, 'EHLO ' . $ehloHost, 250)) return false;
            }

            if (TMC_SMTP_USER !== '') {
                if (!self::command($socket, 'AUTH LOGIN', 334)) return false;
                if (!self::command($socket, base64_encode(TMC_SMTP_USER), 334)) return false;
                if (!self::command($socket, base64_encode(TMC_SMTP_PASS), 235)) return false;
            }

            if (!self::command($socket, 'MAIL FROM:<' . TMC_MAIL_FROM . '>', 250)) return false;
            if (!self::command($socket, 'RCPT TO:<' . $to . '>', [250, 251])) return false;
            if (!self::command($socket, 'DATA', 354)) return false;

            $data = self::buildMessage($to, $subject, $body);
            self::write($socket, $data . "\r\n.");
            if (!self::expect($socket, 250)) return false;

            self::command($socket, 'QUIT', [221, 250]);
            return true;
        } finally {
            @fclose($socket);
        }
    }

    private static function buildMessage(string $to, string $subject, string $body): string {
        $encodedBody = rtrim(chunk_split(base64_encode($body), 76, "\r\n"), "\r\n");
        $headers = [
            'Date: ' . date('r'),
            'From: ' . self::fromHeader(),
            'To: <' . $to . '>',
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . self::heloName() . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'Auto-Submitted: auto-generated',
        ];
        return implode("\r\n", $headers) . "\r\n\r\n" . $encodedBody;
    }

    private static function heloName(): string {
        $base = (string)TMC_PUBLIC_BASE_URL;
        $hostFromUrl = $base !== '' ? parse_url($base, PHP_URL_HOST) : null;
        if (is_string($hostFromUrl) && $hostFromUrl !== '') return $hostFromUrl;
        $fromDomain = strstr((string)TMC_MAIL_FROM, '@');
        if (is_string($fromDomain) && strlen($fromDomain) > 1) return substr($fromDomain, 1);
        return 'localhost';
    }

    /** @param int|int[] $expected */
    private static function command($socket, string $line, $expected): bool {
        self::write($socket, $line);
        return self::expect($socket, $expected);
    }

    private static function write($socket, string $line): void {
        if (TMC_MAIL_TRACE) {
            $safe = preg_match('/^[A-Za-z0-9+\/=]{8,}$/', $line) ? '[base64 credential]' : $line;
            self::$lastTrace[] = '> ' . $safe;
        }
        fwrite($socket, $line . "\r\n");
    }

    /** @param int|int[] $expected */
    private static function expect($socket, $expected): bool {
        $codes = is_array($expected) ? $expected : [$expected];
        $response = '';
        while (($chunk = fgets($socket, 1024)) !== false) {
            $response .= $chunk;
            // Multiline replies repeat the code with a hyphen: "250-STARTTLS".
            if (strlen($chunk) < 4 || $chunk[3] !== '-') break;
        }
        if ($response === '') {
            self::$lastError = 'no response from server (timeout?)';
            return false;
        }
        if (TMC_MAIL_TRACE) {
            self::$lastTrace[] = '< ' . trim($response);
        }
        $code = (int)substr($response, 0, 3);
        if (!in_array($code, $codes, true)) {
            self::$lastError = 'expected ' . implode('/', $codes) . ', got: ' . trim($response);
            return false;
        }
        return true;
    }
}
