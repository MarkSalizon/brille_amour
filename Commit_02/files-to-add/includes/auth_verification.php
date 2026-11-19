<?php

function authVerificationCodeTtlMinutes(): int
{
    return 10;
}

function authVerificationIsLocal(): bool
{
    $host = $_SERVER['HTTP_HOST'] ?? '';
    return $host === '' || stripos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false;
}

function authVerificationStart(string $sessionKey, string $email, string $name, string $purpose, array $payload): array
{
    $code = (string)random_int(100000, 999999);
    $_SESSION[$sessionKey] = [
        'email' => $email,
        'name' => $name,
        'purpose' => $purpose,
        'payload' => $payload,
        'code_hash' => password_hash($code, PASSWORD_DEFAULT),
        'expires_at' => time() + (authVerificationCodeTtlMinutes() * 60),
        'attempts' => 0,
    ];

    $sent = authVerificationSend($email, $name, $code, $purpose);

    return [
        'sent' => $sent,
        'local_code' => '',
    ];
}

function authVerificationPending(string $sessionKey): ?array
{
    $pending = $_SESSION[$sessionKey] ?? null;
    if (!is_array($pending)) {
        return null;
    }

    if ((int)($pending['expires_at'] ?? 0) < time()) {
        unset($_SESSION[$sessionKey]);
        return null;
    }

    return $pending;
}

function authVerificationCheck(string $sessionKey, string $code): array
{
    $pending = authVerificationPending($sessionKey);
    if (!$pending) {
        return ['ok' => false, 'error' => 'Verification code expired. Please submit the form again.'];
    }

    $code = trim($code);
    if ($code === '') {
        return ['ok' => false, 'error' => 'Please enter the verification code.'];
    }

    if (!ctype_digit($code) || strlen($code) !== 6) {
        return ['ok' => false, 'error' => 'The verification code must be 6 numbers.'];
    }

    $_SESSION[$sessionKey]['attempts'] = (int)($pending['attempts'] ?? 0) + 1;
    if ($_SESSION[$sessionKey]['attempts'] > 5) {
        unset($_SESSION[$sessionKey]);
        return ['ok' => false, 'error' => 'Too many attempts. Please submit the form again.'];
    }

    if (!password_verify($code, (string)$pending['code_hash'])) {
        return ['ok' => false, 'error' => 'Invalid verification code.'];
    }

    unset($_SESSION[$sessionKey]);
    return ['ok' => true, 'payload' => $pending['payload'] ?? []];
}

function authVerificationSend(string $email, string $name, string $code, string $purpose): bool
{
    $subject = SITE_NAME . ' verification code';
    $displayName = $name !== '' ? $name : 'there';
    $minutes = authVerificationCodeTtlMinutes();
    $body = "Hi {$displayName},\n\nYour {$purpose} verification code is: {$code}\n\nThis code expires in {$minutes} minutes.\n\nIf you did not request this, please ignore this email.";

    if (defined('GOOGLE_SMTP_EMAIL') && defined('GOOGLE_SMTP_APP_PASSWORD') && GOOGLE_SMTP_EMAIL !== '' && GOOGLE_SMTP_APP_PASSWORD !== '') {
        return authVerificationSendViaGmail($email, $subject, $body);
    }

    $headers = [
        'From: ' . SITE_NAME . ' <no-reply@brille-amour.local>',
        'Content-Type: text/plain; charset=UTF-8',
    ];

    return @mail($email, $subject, $body, implode("\r\n", $headers));
}

function authVerificationSendViaGmail(string $to, string $subject, string $body): bool
{
    $fromEmail = GOOGLE_SMTP_EMAIL;
    $fromName = defined('GOOGLE_SMTP_FROM_NAME') && GOOGLE_SMTP_FROM_NAME !== '' ? GOOGLE_SMTP_FROM_NAME : SITE_NAME;
    $appPassword = preg_replace('/\s+/', '', GOOGLE_SMTP_APP_PASSWORD);
    $socket = @stream_socket_client('tcp://smtp.gmail.com:587', $errno, $errstr, 15);

    if (!$socket) {
        return false;
    }

    stream_set_timeout($socket, 15);

    $ok = authVerificationSmtpExpect($socket, 220)
        && authVerificationSmtpCommand($socket, 'EHLO localhost', 250)
        && authVerificationSmtpCommand($socket, 'STARTTLS', 220)
        && @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)
        && authVerificationSmtpCommand($socket, 'EHLO localhost', 250)
        && authVerificationSmtpCommand($socket, 'AUTH LOGIN', 334)
        && authVerificationSmtpCommand($socket, base64_encode($fromEmail), 334)
        && authVerificationSmtpCommand($socket, base64_encode((string)$appPassword), 235)
        && authVerificationSmtpCommand($socket, 'MAIL FROM:<' . $fromEmail . '>', 250)
        && authVerificationSmtpCommand($socket, 'RCPT TO:<' . $to . '>', [250, 251])
        && authVerificationSmtpCommand($socket, 'DATA', 354);

    if ($ok) {
        $message = [
            'From: ' . authVerificationMailHeaderName($fromName) . ' <' . $fromEmail . '>',
            'To: <' . $to . '>',
            'Subject: ' . authVerificationMailHeaderText($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            '',
            $body,
        ];

        $ok = authVerificationSmtpCommand($socket, implode("\r\n", $message) . "\r\n.", 250);
    }

    authVerificationSmtpCommand($socket, 'QUIT', 221);
    fclose($socket);

    return (bool)$ok;
}

function authVerificationSmtpCommand($socket, string $command, $expected): bool
{
    fwrite($socket, $command . "\r\n");
    return authVerificationSmtpExpect($socket, $expected);
}

function authVerificationSmtpExpect($socket, $expected): bool
{
    $expectedCodes = is_array($expected) ? $expected : [$expected];
    $response = '';

    do {
        $line = fgets($socket, 515);
        if ($line === false) {
            return false;
        }

        $response .= $line;
        $done = isset($line[3]) && $line[3] === ' ';
    } while (!$done);

    $code = (int)substr($response, 0, 3);
    return in_array($code, $expectedCodes, true);
}

function authVerificationMailHeaderName(string $value): string
{
    $value = trim(str_replace(["\r", "\n"], '', $value));
    return '"' . addcslashes($value, '"\\') . '"';
}

function authVerificationMailHeaderText(string $value): string
{
    return trim(str_replace(["\r", "\n"], '', $value));
}
