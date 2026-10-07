<?php
/**
 * Sending mail. SMTP via the vendored PHPMailer (lib/PHPMailer, see VERSION),
 * falling back to mail() when the config has no SMTP host.
 *
 * Deliberately PHPMailer instead of a home-grown SMTP client: getting STARTTLS, AUTH, charsets
 * and line breaks right is error-prone – an undelivered confirmation mail costs us a sign-up.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/PHPMailer/Exception.php';
require_once __DIR__ . '/lib/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/lib/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

/**
 * @throws RuntimeException when sending fails
 */
function sendMail(string $to, string $subject, string $html, string $text, ?string $unsubUrl = null): void
{
    global $CONFIG;

    $mail = new PHPMailer(true);
    try {
        $smtp = $CONFIG['smtp'] ?? [];
        if (!empty($smtp['host'])) {
            $mail->isSMTP();
            $mail->Host       = (string)$smtp['host'];
            $mail->Port       = (int)($smtp['port'] ?? 587);
            $mail->SMTPAuth   = ($smtp['user'] ?? '') !== '';
            $mail->Username   = (string)($smtp['user'] ?? '');
            $mail->Password   = (string)($smtp['pass'] ?? '');
            $encryption = $smtp['encryption'] ?? 'tls';
            if ($encryption === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($encryption === 'none') {   // local tests only
                $mail->SMTPSecure  = '';
                $mail->SMTPAutoTLS = false;
            } else {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }
            $mail->Timeout    = 15;
            if (!empty($smtp['debug'])) {
                $mail->SMTPDebug   = SMTP::DEBUG_SERVER;
                $mail->Debugoutput = 'error_log';
            }
        } else {
            $mail->isMail();
        }

        $mail->CharSet  = PHPMailer::CHARSET_UTF8;
        $mail->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;
        $mail->setFrom((string)$CONFIG['mail_from'], (string)$CONFIG['mail_from_name']);
        $mail->Sender = (string)$CONFIG['mail_from'];   // envelope sender (SPF)
        $mail->addAddress($to);

        if ($unsubUrl !== null) {
            $mail->addCustomHeader('List-Unsubscribe', '<' . $unsubUrl . '>');
        }

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = $text;   // text part for clients without HTML and for spam filters

        $mail->send();
    } catch (\Throwable $ex) {
        // ErrorInfo never contains the password, only the SMTP response
        throw new RuntimeException('Mail delivery failed (SMTP): ' . $mail->ErrorInfo, 0, $ex);
    }
}
