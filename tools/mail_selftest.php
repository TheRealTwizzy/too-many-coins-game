<?php
/**
 * Mail transport selftest.
 *
 * Run inside the running container BEFORE opening public signups:
 *   php /app/tools/mail_selftest.php you@example.com
 *
 * Prints the resolved mail configuration (password masked), attempts one real
 * send, and prints the SMTP transcript when TMC_MAIL_TRACE=true.
 * Exit code 0 = delivered to the SMTP server, 1 = failed.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/mailer.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$to = $argv[1] ?? '';
if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    echo "usage: php tools/mail_selftest.php recipient@example.com\n";
    exit(1);
}

$mask = static function (string $value): string {
    if ($value === '') return '(empty)';
    return str_repeat('*', max(4, min(12, strlen($value))));
};

echo "mail configuration\n";
echo "  TMC_MAIL_DEV_LOG   : " . (TMC_MAIL_DEV_LOG ? 'true  <-- nothing will actually send' : 'false') . "\n";
echo "  TMC_MAIL_FROM      : " . TMC_MAIL_FROM . "\n";
echo "  TMC_MAIL_FROM_NAME : " . TMC_MAIL_FROM_NAME . "\n";
echo "  TMC_PUBLIC_BASE_URL: " . (TMC_PUBLIC_BASE_URL !== '' ? TMC_PUBLIC_BASE_URL : '(empty) <-- verification links will be relative') . "\n";
echo "  TMC_SMTP_HOST      : " . (TMC_SMTP_HOST !== '' ? TMC_SMTP_HOST : '(empty) <-- will fall through to mail()') . "\n";
echo "  TMC_SMTP_PORT      : " . TMC_SMTP_PORT . "\n";
echo "  TMC_SMTP_SECURE    : " . TMC_SMTP_SECURE . "\n";
echo "  TMC_SMTP_USER      : " . (TMC_SMTP_USER !== '' ? TMC_SMTP_USER : '(empty, unauthenticated)') . "\n";
echo "  TMC_SMTP_PASS      : " . $mask((string)TMC_SMTP_PASS) . "\n";
echo "  TMC_MAIL_TRACE     : " . (TMC_MAIL_TRACE ? 'true' : 'false') . "\n\n";

$subject = 'Too Many Coins mail selftest';
$body = "This is a transport selftest from the Too Many Coins container.\n\n"
    . "If you received it, account verification mail works on this deployment.\n"
    . "Sent at " . date('c') . "\n";

echo "sending to {$to} ...\n";
$ok = Mailer::send($to, $subject, $body);

if (Mailer::$lastTrace) {
    echo "\nsmtp transcript\n";
    foreach (Mailer::$lastTrace as $line) {
        echo '  ' . $line . "\n";
    }
}

echo "\nresult: " . ($ok ? "OK — accepted by the server\n" : "FAILED — " . (Mailer::$lastError ?: 'unknown error') . "\n");

if ($ok && TMC_MAIL_DEV_LOG) {
    echo "warning: TMC_MAIL_DEV_LOG is true, so this was only written to the error log.\n";
    echo "         set TMC_MAIL_DEV_LOG=false on the live web service.\n";
}

exit($ok ? 0 : 1);
