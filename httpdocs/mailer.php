<?php
// mailer.php — Shared SMTP email sender
// Reads SMTP config from the system_settings DB table (set via Settings panel).

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

/**
 * Load SMTP settings from DB, falling back to config.php constants.
 */
function getSmtpSettings(): array {
    try {
        $pdo = db();
        $st  = $pdo->query('SELECT `key`, `value` FROM system_settings');
        $rows = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable $e) {
        $rows = [];
    }

    return [
        'host'      => $rows['smtp_host']      ?? (defined('SMTP_HOST')      ? SMTP_HOST      : ''),
        'port'      => (int)($rows['smtp_port'] ?? (defined('SMTP_PORT')      ? SMTP_PORT      : 587)),
        'secure'    => $rows['smtp_secure']    ?? (defined('SMTP_SECURE')    ? SMTP_SECURE    : 'tls'),
        'username'  => $rows['smtp_username']  ?? (defined('SMTP_USERNAME')  ? SMTP_USERNAME  : ''),
        'password'  => $rows['smtp_password']  ?? (defined('SMTP_PASSWORD')  ? SMTP_PASSWORD  : ''),
        'from'      => $rows['smtp_from']      ?? (defined('SMTP_FROM')      ? SMTP_FROM      : ''),
        'from_name' => $rows['smtp_from_name'] ?? (defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'NextGen Technologies'),
    ];
}

/**
 * Send an HTML email via SMTP (or mail() fallback).
 *
 * @return array ['ok' => bool, 'error' => string]
 */
function sendMail(string $toEmail, string $toName, string $subject, string $htmlBody): array
{
    $cfg = getSmtpSettings();

    // Sanitize header values
    $toName    = str_replace(["\r", "\n"], '', $toName);
    $subject   = str_replace(["\r", "\n"], '', $subject);
    $fromName  = str_replace(["\r", "\n"], '', $cfg['from_name']);

    if ($cfg['host'] && $cfg['username'] && $cfg['from']) {
        return _sendViaSmtp(
            $cfg['host'], $cfg['port'], $cfg['username'], $cfg['password'], $cfg['secure'],
            $cfg['from'], $fromName,
            $toEmail, $toName,
            $subject, $htmlBody
        );
    }

    // Fallback: PHP mail()
    $fromAddr = $cfg['from'] ?: ('noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $headers  = "MIME-Version: 1.0\r\n"
              . "Content-Type: text/html; charset=UTF-8\r\n"
              . "From: {$fromName} <{$fromAddr}>\r\n"
              . "Reply-To: {$fromAddr}\r\n";

    $sent = @mail($toEmail, $subject, $htmlBody, $headers);
    if ($sent) return ['ok' => true, 'error' => ''];
    return ['ok' => false, 'error' => 'mail() failed. Please configure SMTP in Settings for reliable delivery.'];
}

/** Send via raw SMTP socket — supports ssl and starttls */
function _sendViaSmtp(
    string $host, int $port, string $user, string $pass, string $secure,
    string $fromAddr, string $fromName,
    string $toAddr,   string $toName,
    string $subject,  string $body
): array {
    $errno = 0; $errstr = '';
    $prefix = ($secure === 'ssl') ? 'ssl://' : '';
    $socket = @fsockopen($prefix . $host, $port, $errno, $errstr, 15);
    if (!$socket) {
        return ['ok' => false, 'error' => "SMTP connect failed ({$host}:{$port}): {$errstr}"];
    }
    stream_set_timeout($socket, 15);

    $read = function() use ($socket): string {
        $r = '';
        while ($line = fgets($socket, 515)) {
            $r .= $line;
            if (substr($line, 3, 1) === ' ') break;
        }
        return $r;
    };
    $cmd = function(string $c) use ($socket, $read): string {
        fputs($socket, $c . "\r\n");
        return $read();
    };

    $read(); // greeting
    $cmd('EHLO ' . $host);

    if ($secure === 'tls') {
        $resp = $cmd('STARTTLS');
        if (substr(trim($resp), 0, 3) !== '220') {
            fclose($socket);
            return ['ok' => false, 'error' => 'STARTTLS failed: ' . trim($resp)];
        }
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket);
            return ['ok' => false, 'error' => 'TLS handshake failed.'];
        }
        $cmd('EHLO ' . $host);
    }

    $cmd('AUTH LOGIN');
    $cmd(base64_encode($user));
    $authResp = $cmd(base64_encode($pass));
    if (substr(trim($authResp), 0, 3) !== '235') {
        fclose($socket);
        return ['ok' => false, 'error' => 'SMTP auth failed — check username/password: ' . trim($authResp)];
    }

    $date    = date('r');
    $msgId   = '<' . uniqid('ng', true) . '@' . $host . '>';
    $message = "Date: {$date}\r\n"
             . "Message-ID: {$msgId}\r\n"
             . "From: {$fromName} <{$fromAddr}>\r\n"
             . "To: {$toName} <{$toAddr}>\r\n"
             . "Subject: {$subject}\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: text/html; charset=UTF-8\r\n"
             . "Content-Transfer-Encoding: quoted-printable\r\n"
             . "\r\n"
             . quoted_printable_encode($body);

    $cmd("MAIL FROM: <{$fromAddr}>");
    $rcpt = $cmd("RCPT TO: <{$toAddr}>");
    if (substr(trim($rcpt), 0, 1) !== '2') {
        fclose($socket);
        return ['ok' => false, 'error' => 'Recipient rejected: ' . trim($rcpt)];
    }
    $cmd('DATA');
    fputs($socket, $message . "\r\n.\r\n");
    $resp = $read();
    $cmd('QUIT');
    fclose($socket);

    if (substr(trim($resp), 0, 3) === '250') {
        return ['ok' => true, 'error' => ''];
    }
    return ['ok' => false, 'error' => 'SMTP send failed: ' . trim($resp)];
}
