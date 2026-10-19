<?php
declare(strict_types=1);

function sc_http(string $url, string $method, array $headers, mixed $body = null): array {
    if (parse_url($url, PHP_URL_SCHEME) !== 'https') { throw new RuntimeException('Remote connector requests require HTTPS.'); }
    $curl = curl_init($url);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 35,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
    if ($body !== null) { curl_setopt($curl, CURLOPT_POSTFIELDS, $body); }
    $raw = curl_exec($curl);
    $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    unset($curl); // CurlHandle releases itself; curl_close is deprecated in PHP 8.5.
    if (!is_string($raw)) { throw new RuntimeException('Remote transport failed; an attempted purchase may have been accepted.'); }
    $decoded = json_decode($raw, true);
    if ($code < 200 || $code > 299 || !is_array($decoded)) {
        throw new RuntimeException('Remote request failed with HTTP ' . $code . ' or invalid JSON.', $code);
    }
    return ['code' => $code, 'body' => $decoded];
}

function sc_simcloud(array $config, string $service, string $method = 'GET', array $data = []): array {
    if (!in_array($service, ['balance', 'data', 'vas', 'airtime', 'network', 'electricity'], true)
        || !in_array($method, ['GET', 'POST'], true)) { throw new RuntimeException('Unsupported SIMcloud operation.'); }
    $url = 'https://simcloud.co.za/api/' . $service . '.php';
    $headers = ['Accept: application/json', 'Authorization: Bearer ' . $config['simcloud_token']];
    if ($method === 'GET') { $url .= $data ? '?' . http_build_query($data) : ''; }
    else { $headers[] = 'Content-Type: application/json'; }
    return sc_http($url, $method, $headers, $method === 'POST' ? json_encode($data, JSON_THROW_ON_ERROR) : null);
}

function sc_zoho_token(array $config): string {
    // Request-scoped only: no token is returned to a browser or retained in a public file.
    static $tokens = [];
    $key = hash('sha256', ($config['zoho_client_id'] ?? '') . ':' . ($config['zoho_refresh_token'] ?? ''));
    if (isset($tokens[$key])) { return $tokens[$key]; }
    if (($config['zoho_refresh_token'] ?? '') === '') {
        if (($config['zoho_access_token'] ?? '') === '') { throw new RuntimeException('Zoho authorization is missing.'); }
        return $config['zoho_access_token'];
    }
    $response = sc_http('https://accounts.zoho.' . $config['zoho_region'] . '/oauth/v2/token', 'POST',
        ['Content-Type: application/x-www-form-urlencoded'], http_build_query(['grant_type' => 'refresh_token',
            'client_id' => $config['zoho_client_id'], 'client_secret' => $config['zoho_client_secret'], 'refresh_token' => $config['zoho_refresh_token']]));
    $token = $response['body']['access_token'] ?? '';
    if (!is_string($token) || $token === '') { throw new RuntimeException('Zoho token refresh failed.'); }
    return $tokens[$key] = $token;
}

function sc_zoho(array $config, string $method, string $path, array $data = [], ?string $image = null): array {
    if (!preg_match('#^/(?:products(?:/[1-9][0-9]{0,29}(?:/images)?)?|salesorders/[1-9][0-9]{0,29})$#D', $path)) {
        throw new RuntimeException('Unsupported Zoho API resource.');
    }
    $url = 'https://commerce.zoho.' . $config['zoho_region'] . '/store/api/v1' . $path;
    $headers = ['Accept: application/json', 'Authorization: Zoho-oauthtoken ' . sc_zoho_token($config),
        'X-com-zoho-store-organizationid: ' . $config['organization_id']];
    $body = null;
    if ($method === 'GET') { $url .= $data ? '?' . http_build_query($data) : ''; }
    elseif ($image !== null) {
        $source = realpath(__DIR__ . '/../app/images/' . $image);
        $base = realpath(__DIR__ . '/../app/images');
        if (!$source || !$base || !str_starts_with($source, $base . DIRECTORY_SEPARATOR) || pathinfo($source, PATHINFO_EXTENSION) !== 'jpg') {
            throw new RuntimeException('Invalid bundled product image.');
        }
        $body = ['image' => new CURLFile($source, 'image/jpeg', basename($source))];
    } else { $headers[] = 'Content-Type: application/json'; $body = json_encode($data, JSON_THROW_ON_ERROR); }
    $response = sc_http($url, $method, $headers, $body);
    if (($response['body']['code'] ?? null) !== 0) { throw new RuntimeException('Zoho rejected the requested operation.'); }
    return $response['body'];
}

function sc_catalogue_fetch(callable $simcloud): array {
    $data = $simcloud('data', 'GET', ['products' => 1])['body'];
    $vas = $simcloud('vas', 'GET', [])['body'];
    foreach ([$data, $vas] as $body) {
        if (($body['status'] ?? '') !== 'success' || !is_array($body['products'] ?? null)) { throw new RuntimeException('SIMcloud catalogue unavailable.'); }
    }
    return sc_catalogue($data['products'], $vas['products']);
}
