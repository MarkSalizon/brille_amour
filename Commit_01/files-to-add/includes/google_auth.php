<?php
function googleAuthConfigured(): bool
{
    return defined('GOOGLE_CLIENT_ID')
        && defined('GOOGLE_CLIENT_SECRET')
        && GOOGLE_CLIENT_ID !== ''
        && GOOGLE_CLIENT_SECRET !== '';
}

function googleAuthMissingMessage(string $role): string
{
    $callback = googleAuthRedirectUri($role);

    return 'Google verification is not configured yet. Add GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET in includes/config.php, then add this authorized redirect URI in Google Cloud: ' . $callback;
}

function googleAuthRedirectUri(string $role): string
{
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $secure ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');

    return $scheme . '://' . $host . $basePath . '/google_callback.php';
}

function googleAuthStart(string $role, string $mode, string $emailHint = ''): void
{
    if (!googleAuthConfigured()) {
        throw new RuntimeException(googleAuthMissingMessage($role));
    }

    $state = bin2hex(random_bytes(32));
    $_SESSION['google_oauth_state'] = [
        'state' => $state,
        'role' => $role,
        'mode' => $mode,
        'created_at' => time(),
    ];

    $params = [
        'client_id' => GOOGLE_CLIENT_ID,
        'redirect_uri' => googleAuthRedirectUri($role),
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'access_type' => 'online',
        'prompt' => 'select_account',
        'state' => $state,
    ];

    if ($emailHint !== '') {
        $params['login_hint'] = $emailHint;
    }

    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params));
    exit;
}

function googleAuthExchangeCode(string $role): array
{
    if (!googleAuthConfigured()) {
        return ['ok' => false, 'error' => googleAuthMissingMessage($role)];
    }

    if (!empty($_GET['error'])) {
        return ['ok' => false, 'error' => 'Google verification was cancelled or denied.'];
    }

    $pending = $_SESSION['google_oauth_state'] ?? null;
    $state = (string)($_GET['state'] ?? '');
    $code = (string)($_GET['code'] ?? '');

    unset($_SESSION['google_oauth_state']);

    if (!is_array($pending) || !hash_equals((string)($pending['state'] ?? ''), $state)) {
        return ['ok' => false, 'error' => 'Google verification expired. Please try again.'];
    }

    if (($pending['role'] ?? '') !== $role || time() - (int)($pending['created_at'] ?? 0) > 600) {
        return ['ok' => false, 'error' => 'Google verification expired. Please try again.'];
    }

    if ($code === '') {
        return ['ok' => false, 'error' => 'Google did not return a verification code.'];
    }

    $tokenResponse = googleAuthPost('https://oauth2.googleapis.com/token', [
        'code' => $code,
        'client_id' => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri' => googleAuthRedirectUri($role),
        'grant_type' => 'authorization_code',
    ]);

    if (!$tokenResponse['ok'] || empty($tokenResponse['data']['access_token'])) {
        return ['ok' => false, 'error' => 'Unable to confirm the Google verification code.'];
    }

    $profileResponse = googleAuthGet(
        'https://openidconnect.googleapis.com/v1/userinfo',
        (string)$tokenResponse['data']['access_token']
    );

    if (!$profileResponse['ok'] || empty($profileResponse['data']['email'])) {
        return ['ok' => false, 'error' => 'Unable to read the verified Google account.'];
    }

    return [
        'ok' => true,
        'mode' => (string)($pending['mode'] ?? ''),
        'email' => strtolower(trim((string)$profileResponse['data']['email'])),
        'name' => (string)($profileResponse['data']['name'] ?? ''),
        'sub' => (string)($profileResponse['data']['sub'] ?? ''),
        'picture' => (string)($profileResponse['data']['picture'] ?? ''),
        'verified_email' => (bool)($profileResponse['data']['email_verified'] ?? $profileResponse['data']['verified_email'] ?? false),
    ];
}

function googleAuthPost(string $url, array $params): array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
            'content' => http_build_query($params),
            'ignore_errors' => true,
            'timeout' => 15,
        ],
    ]);

    return googleAuthDecodeResponse(@file_get_contents($url, false, $context));
}

function googleAuthGet(string $url, string $accessToken): array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Authorization: Bearer {$accessToken}\r\nAccept: application/json\r\n",
            'ignore_errors' => true,
            'timeout' => 15,
        ],
    ]);

    return googleAuthDecodeResponse(@file_get_contents($url, false, $context));
}

function googleAuthDecodeResponse($body): array
{
    if (!is_string($body) || $body === '') {
        return ['ok' => false, 'data' => []];
    }

    $data = json_decode($body, true);

    return [
        'ok' => is_array($data) && empty($data['error']),
        'data' => is_array($data) ? $data : [],
    ];
}
