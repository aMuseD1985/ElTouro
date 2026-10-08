<?php
/**
 * Sign in with Google (OpenID Connect, authorization code flow with PKCE, state and nonce).
 * No SDK: two HTTPS calls. Enabled only when 'google' => ['client_id', 'client_secret'] is configured;
 * the redirect URI to register in the Google Cloud console is <base_url>/auth/google/callback.
 *
 * The ID token comes straight from Google's token endpoint over TLS (certificate checked by curl), so per
 * Google's documentation its signature does not need to be verified again; issuer, audience, expiry and
 * nonce are still checked. 'auth_url', 'token_url' and 'issuer' can be overridden for local tests only.
 */
declare(strict_types=1);

const GOOGLE_FLOW_TTL = 600;        // seconds between leaving for Google and coming back
const GOOGLE_PENDING_TTL = 1800;    // seconds to finish the sign-up form after Google

function googleConfig(): ?array
{
    global $CONFIG;
    $g = $CONFIG['google'] ?? [];
    if (empty($g['client_id']) || empty($g['client_secret'])) {
        return null;
    }
    return $g + [
        'auth_url'  => 'https://accounts.google.com/o/oauth2/v2/auth',
        'token_url' => 'https://oauth2.googleapis.com/token',
        'issuer'    => ['https://accounts.google.com', 'accounts.google.com'],
    ];
}

function googleEnabled(): bool
{
    return googleConfig() !== null;
}

function googleRedirectUri(): string
{
    return baseUrl() . '/auth/google/callback';
}

function base64Url(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

/** Sends the browser to Google. $next is where to go after a successful sign-in (relative URL). */
function googleStart(string $next): never
{
    $g = googleConfig();
    $flow = [
        'state'    => base64Url(random_bytes(24)),
        'nonce'    => base64Url(random_bytes(24)),
        'verifier' => base64Url(random_bytes(48)),
        'next'     => str_starts_with($next, '/') && !str_starts_with($next, '//') ? $next : '/',
        'at'       => time(),
    ];
    $_SESSION['google_flow'] = $flow;
    $url = $g['auth_url'] . '?' . http_build_query([
        'client_id'             => $g['client_id'],
        'redirect_uri'          => googleRedirectUri(),
        'response_type'         => 'code',
        'scope'                 => 'openid email profile',
        'state'                 => $flow['state'],
        'nonce'                 => $flow['nonce'],
        'code_challenge'        => base64Url(hash('sha256', $flow['verifier'], true)),
        'code_challenge_method' => 'S256',
        'prompt'                => 'select_account',
    ]);
    header('Location: ' . $url, true, 302);
    exit;
}

/**
 * Handles the callback: checks state, exchanges the code, validates the ID token.
 * @return array{sub:string, email:string, name:string, next:string}
 * @throws RuntimeException with a code: 'cancelled', 'state', 'token', 'claims'
 */
function googleFinish(array $query): array
{
    $g = googleConfig();
    $flow = $_SESSION['google_flow'] ?? null;
    unset($_SESSION['google_flow']);   // a flow can only be completed once
    if (isset($query['error'])) {
        throw new RuntimeException('cancelled');
    }
    if (!is_array($flow) || $flow['at'] < time() - GOOGLE_FLOW_TTL
        || !hash_equals($flow['state'], (string)($query['state'] ?? '')) || empty($query['code'])) {
        throw new RuntimeException('state');
    }

    $ch = curl_init($g['token_url']);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'code'          => (string)$query['code'],
            'client_id'     => $g['client_id'],
            'client_secret' => $g['client_secret'],
            'redirect_uri'  => googleRedirectUri(),
            'grant_type'    => 'authorization_code',
            'code_verifier' => $flow['verifier'],
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    unset($ch);
    $response = is_string($raw) ? json_decode($raw, true) : null;
    if ($status !== 200 || !is_array($response) || empty($response['id_token'])) {
        error_log('ElTouro Google token: HTTP ' . $status . ' ' . substr((string)$raw, 0, 300));
        throw new RuntimeException('token');
    }

    $parts = explode('.', (string)$response['id_token']);
    $claims = count($parts) === 3 ? json_decode((string)base64_decode(strtr($parts[1], '-_', '+/')), true) : null;
    $issuers = (array)$g['issuer'];
    $aud = (array)($claims['aud'] ?? []);
    $verified = ($claims['email_verified'] ?? false) === true || ($claims['email_verified'] ?? '') === 'true';
    if (!is_array($claims) || !in_array($claims['iss'] ?? '', $issuers, true) || !in_array($g['client_id'], $aud, true)
        || (int)($claims['exp'] ?? 0) < time() - 60 || !hash_equals($flow['nonce'], (string)($claims['nonce'] ?? ''))
        || empty($claims['sub']) || empty($claims['email']) || !$verified) {
        error_log('ElTouro Google: rejected ID token claims');
        throw new RuntimeException('claims');
    }
    return [
        'sub'   => (string)$claims['sub'],
        'email' => strtolower((string)$claims['email']),
        'name'  => (string)($claims['name'] ?? $claims['given_name'] ?? ''),
        'next'  => $flow['next'],
    ];
}

/** A display name suggestion from the Google name (or the email's local part) that fits our rules. */
function suggestDisplayName(string $name, string $email): string
{
    $base = $name !== '' ? $name : explode('@', $email)[0];
    $base = strtr($base, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss', ' ' => '.']);
    $base = mb_substr(preg_replace('/[^\p{L}\p{N}._-]/u', '', $base), 0, 26);
    if (mb_strlen($base) < 3) {
        $base = 'Fahrer';
    }
    $name = $base;
    for ($i = 2; dbOne('SELECT id FROM users WHERE display_name = ?', [$name]) && $i < 1000; $i++) {
        $name = $base . $i;
    }
    return $name;
}

/** The "Sign in with Google" button (official colours and "G" mark), or '' if Google is not configured. */
function googleButton(string $next, string $labelKey = 'google.button'): string
{
    if (!googleEnabled()) {
        return '';
    }
    $logo = '<svg aria-hidden="true" width="20" height="20" viewBox="0 0 48 48"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>'
          . '<path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>'
          . '<path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>'
          . '<path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>';
    return '<p class="google-login"><a class="btn-google" href="/auth/google?next=' . e(rawurlencode($next)) . '">' . $logo
         . '<span>' . te($labelKey) . '</span></a></p><p class="or-line"><span>' . te('google.or') . '</span></p>';
}
