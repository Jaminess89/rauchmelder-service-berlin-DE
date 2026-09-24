<?php
declare(strict_types=1);

/**
 * sendmail.php – Lokales Formular-Backend für rauchmelder-service-berlin.de
 *
 * Nimmt die beiden Angebots-Formulare der Website entgegen (Angebots-Formular
 * und Abschluss-Formular) und versendet sie per authentifiziertem SMTP (PHPMailer) direkt vom
 * ALL-INKL-Server (kein externer Dienst, < 1 s).
 *   form=angebot  → E-Mail, Telefon, Art, Objektgröße, Bezirk, Nachricht
 *
 * Antwort: JSON  {"ok":true} | {"ok":false,"error":"..."}
 */

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

// Honeypot: stiller Erfolg ohne Versand (Bots füllen das versteckte Feld)
if (trim((string) ($_POST['website'] ?? '')) !== '') {
    echo json_encode(['ok' => true]);
    exit;
}

$cleanLine = static function (string $v): string {
    return trim(str_replace(["\r", "\n"], ' ', $v));
};
$cleanText = static function (string $v): string {
    return trim(str_replace("\r", '', $v));
};
$esc = static function (string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
};

$formType = (string) ($_POST['form'] ?? '');

if ($formType !== 'angebot') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'validation']);
    exit;
}

$email         = $cleanLine((string) ($_POST['email'] ?? ''));
$art           = $cleanLine((string) ($_POST['art'] ?? '')) ?: 'Installation';
$objektgroesse = $cleanLine((string) ($_POST['objektgroesse'] ?? ''));
$bezirk        = $cleanLine((string) ($_POST['bezirk'] ?? ''));
$telefon       = $cleanLine((string) ($_POST['telefon'] ?? ''));
$nachrichtText = $cleanText((string) ($_POST['nachricht'] ?? ''));
$formId        = $cleanLine((string) ($_POST['formId'] ?? ''));

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'validation']);
    exit;
}

$replyTo   = $email;
$formLabel = $formId === 'final-angebot' ? 'Abschluss-Formular' : 'Angebots-Formular';
$subject   = 'Neue Angebotsanfrage: ' . $art . ' in Berlin';
if ($formId === 'final-angebot') {
    $subject .= ' (Abschluss-Formular)';
}

// ---------- Tabellenzeilen ----------
$linkStyle = 'color:#0e5aad;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:bold;text-decoration:none;';
$rows    = [];
$rows[]  = ['E-Mail', '<a href="mailto:' . $esc($email) . '" style="' . $linkStyle . '">' . $esc($email) . '</a>'];
if ($telefon !== '') {
    $telLink = preg_replace('/[^0-9+]/', '', $telefon);
    $rows[]  = ['Telefon', '<a href="tel:' . $telLink . '" style="' . $linkStyle . '">' . $esc($telefon) . '</a>'];
}
$rows[] = ['Was ben&ouml;tigt', $esc($art)];
if ($objektgroesse !== '') {
    $rows[] = ['Objektgr&ouml;&szlig;e', $esc($objektgroesse)];
}
if ($bezirk !== '') {
    $rows[] = ['Bezirk', $esc($bezirk)];
}

$textRows   = [];
$textRows[] = 'E-Mail: ' . $email;
if ($telefon !== '') {
    $textRows[] = 'Telefon: ' . $telefon;
}
$textRows[] = 'Was benoetigt: ' . $art;
if ($objektgroesse !== '') {
    $textRows[] = 'Objektgroesse: ' . $objektgroesse;
}
if ($bezirk !== '') {
    $textRows[] = 'Bezirk: ' . $bezirk;
}
if ($nachrichtText !== '') {
    $textRows[] = '';
    $textRows[] = 'Nachricht:';
    $textRows[] = $nachrichtText;
}

$rowsHtml  = '';
$lastIdx   = count($rows) - 1;
$labelBase = 'padding:10px 0;color:#7a8a99;font-family:Arial,Helvetica,sans-serif;font-size:11px;font-weight:bold;letter-spacing:1.2px;text-transform:uppercase;width:170px;vertical-align:top;';
$valueBase = 'padding:10px 0;color:#16283a;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:bold;vertical-align:top;';
foreach ($rows as $i => [$label, $valueHtml]) {
    $border = $i === $lastIdx ? '' : 'border-bottom:1px solid #eef2f5;';
    $rowsHtml .= '<tr><td style="' . $labelBase . $border . '">' . $label . '</td>'
        . '<td style="' . $valueBase . $border . '">' . $valueHtml . '</td></tr>';
}

// ---------- Nachrichten-Box ----------
$messageHtml = '';
if ($nachrichtText !== '') {
    $messageHtml = '<tr><td style="padding:16px 30px 6px;">'
        . '<div style="color:#7a8a99;font-family:Arial,Helvetica,sans-serif;font-size:11px;font-weight:bold;letter-spacing:1.2px;text-transform:uppercase;padding-bottom:9px;">Nachricht</div>'
        . '<div style="background-color:#f7f9fa;border:1px solid #e3e9ee;border-left:4px solid #63c62f;border-radius:8px;padding:15px 17px;color:#22303d;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.65;">'
        . nl2br($esc($nachrichtText), false)
        . '</div></td></tr>';
}

// ---------- Antwort-Button + Tipp ----------
$buttonHtml = '<tr><td align="center" style="padding:24px 30px 26px;">'
    . '<a href="mailto:' . $esc($replyTo) . '?subject=Ihre%20Anfrage%20Rauchmelder-Service%20Berlin" style="display:inline-block;background-color:#63c62f;color:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:bold;padding:13px 30px;border-radius:999px;text-decoration:none;">Auf die Anfrage antworten</a>'
    . '</td></tr>';
$tipHtml = '<br>Tipp: Sie k&ouml;nnen diese E-Mail auch einfach direkt beantworten &ndash; die Antwort geht an ' . $esc($replyTo) . '.';

$sendDate = date('d.m.Y');
$sendTime = date('H:i');

// ---------- Plain-Text-Alternative ----------
$textBody = 'Neue Anfrage (' . $formLabel . ') auf rauchmelder-service-berlin.de' . "\r\n\r\n"
    . implode("\r\n", $textRows) . "\r\n\r\n---\r\n"
    . 'Gesendet am ' . $sendDate . ' um ' . $sendTime . ' Uhr';

// ---------- HTML-Version ----------
$htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="de">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Neue Anfrage</title></head>
<body style="margin:0;padding:0;background-color:#eef2f5;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#eef2f5;padding:28px 12px;">
    <tr><td align="center">
      <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #dfe7ec;">

        <!-- Header -->
        <tr>
          <td style="background-color:#062B4A;padding:24px 30px 22px;">
            <div style="color:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:21px;font-weight:bold;line-height:1.3;">Neue Anfrage &uuml;ber das {$formLabel}</div>
          </td>
        </tr>
        <tr><td style="background-color:#63c62f;height:4px;font-size:0;line-height:0;">&nbsp;</td></tr>

        <!-- Eingaben -->
        <tr>
          <td style="padding:22px 30px 6px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
              {$rowsHtml}
            </table>
          </td>
        </tr>

        {$messageHtml}

        {$buttonHtml}

        <!-- Footer -->
        <tr>
          <td style="background-color:#f2f5f7;padding:15px 30px;color:#7a8a99;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.6;">
            Gesendet am {$sendDate} um {$sendTime} Uhr &uuml;ber das {$formLabel} auf rauchmelder-service-berlin.de{$tipHtml}
          </td>
        </tr>

      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

// ---------- Versand ueber authentifiziertes SMTP mit PHPMailer ----------
require __DIR__ . '/lib/PHPMailer/Exception.php';
require __DIR__ . '/lib/PHPMailer/PHPMailer.php';
require __DIR__ . '/lib/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

$smtpCfg = require __DIR__ . '/lib/smtp-config.php';
$diag    = hash_equals((string) ($smtpCfg['diag_token'] ?? 'x'), (string) ($_REQUEST['diag'] ?? 'y'));

$mail    = new PHPMailer(true);
$smtpLog = [];

try {
    $mail->isSMTP();
    $mail->Host       = $smtpCfg['host'];
    $mail->Port       = $smtpCfg['port'];
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->SMTPAuth   = true;
    $mail->Username   = $smtpCfg['user'];
    $mail->Password   = $smtpCfg['pass'];
    $mail->Timeout    = 20;
    $mail->CharSet    = 'UTF-8';
    $mail->SMTPDebug  = 2;
    $mail->Debugoutput = static function (string $str) use (&$smtpLog): void {
        static $maskNext = 0;
        if (preg_match('/CLIENT -> SERVER: AUTH (LOGIN|PLAIN)/i', $str, $m)) {
            $maskNext = (strtoupper($m[1]) === 'PLAIN') ? 1 : 2; // LOGIN: user+pass-Zeilen
            $smtpLog[] = trim($str);
            return;
        }
        if ($maskNext > 0 && stripos($str, 'CLIENT -> SERVER:') !== false) {
            $smtpLog[] = 'CLIENT -> SERVER: *** (credential masked)';
            $maskNext--;
            return;
        }
        $smtpLog[] = trim($str);
    };

    $mail->setFrom($smtpCfg['from'], 'Website Kontaktanfrage');
    $mail->addAddress($smtpCfg['to']);
    $mail->addBCC($smtpCfg['bcc']);
    if (isset($replyTo) && $replyTo !== null && $replyTo !== '') {
        $mail->addReplyTo($replyTo);
    }
    $mail->Sender  = $smtpCfg['from']; // Envelope-From = authentifiziertes Postfach
    $mail->Subject = $subject;
    $mail->isHTML(true);
    $mail->Body    = $htmlBody;
    $mail->AltBody = $textBody;

    $mail->send();

    error_log('sendmail.php (rauchmelder-service-berlin.de): SMTP-Versand OK, message-id=' . $mail->getLastMessageID());
} catch (Throwable $e) {
    error_log('sendmail.php (rauchmelder-service-berlin.de): SMTP-Versand FEHLER: ' . $mail->ErrorInfo
        . ' | smtp-transcript: ' . implode(' | ', array_slice($smtpLog, -20)));
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'mail_failed']);
    exit;
}

if ($diag) {
    echo json_encode([
        'ok'              => true,
        'diag'            => true,
        'message_id'      => $mail->getLastMessageID(),
        'envelope_from'   => $smtpCfg['from'],
        'header_from'     => $smtpCfg['from'],
        'to'              => $smtpCfg['to'],
        'bcc'             => $smtpCfg['bcc'],
        'smtp_host'       => $smtpCfg['host'] . ':' . $smtpCfg['port'] . ' ssl+auth',
        'smtp_transcript' => $smtpLog,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true]);
