<?php
/**
 * Mailversand. SMTP über vendorten PHPMailer (lib/PHPMailer, siehe VERSION),
 * Fallback auf mail(), wenn in der Config kein SMTP-Host steht.
 *
 * Bewusst PHPMailer statt eigenem SMTP-Client: STARTTLS, AUTH, Zeichensätze und
 * Zeilenumbrüche korrekt hinzubekommen ist fehleranfällig – eine nicht zugestellte
 * Bestätigungsmail kostet uns direkt eine Anmeldung.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/PHPMailer/Exception.php';
require_once __DIR__ . '/lib/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/lib/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

/**
 * @throws RuntimeException wenn der Versand fehlschlägt
 */
function sendeMail(string $an, string $betreff, string $html, string $text, ?string $unsubUrl = null): void
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
            $verschluesselung = $smtp['encryption'] ?? 'tls';
            if ($verschluesselung === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($verschluesselung === 'none') {   // nur für lokale Tests
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
        $mail->Sender = (string)$CONFIG['mail_from'];   // Envelope-Absender (SPF)
        $mail->addAddress($an);

        if ($unsubUrl !== null) {
            $mail->addCustomHeader('List-Unsubscribe', '<' . $unsubUrl . '>');
        }

        $mail->isHTML(true);
        $mail->Subject = $betreff;
        $mail->Body    = $html;
        $mail->AltBody = $text;   // Textteil für Clients ohne HTML und für Spamfilter

        $mail->send();
    } catch (\Throwable $ex) {
        // ErrorInfo enthält nie das Passwort, nur die SMTP-Antwort
        throw new RuntimeException('Mailversand fehlgeschlagen: ' . $mail->ErrorInfo, 0, $ex);
    }
}
