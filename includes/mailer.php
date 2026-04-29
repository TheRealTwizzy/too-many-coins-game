<?php
require_once __DIR__ . '/config.php';

class Mailer {
    public static function send(string $to, string $subject, string $body): bool {
        $headers = [
            'From: ' . TMC_MAIL_FROM_NAME . ' <' . TMC_MAIL_FROM . '>',
            'Content-Type: text/plain; charset=UTF-8',
        ];

        if (TMC_MAIL_DEV_LOG) {
            error_log('[mail-dev] to=' . $to . ' subject=' . $subject . ' body=' . str_replace(["\r", "\n"], ' | ', $body));
            return true;
        }

        return mail($to, $subject, $body, implode("\r\n", $headers));
    }
}
