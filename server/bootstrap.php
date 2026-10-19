<?php
declare(strict_types=1);
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/import.php';
require_once __DIR__ . '/fulfilment.php';

function sc_config(): array {
    $path = getenv('SIMCLOUD_ZOHO_CONFIG');
    if (!is_string($path) || !str_starts_with($path, '/') || !is_file($path)) {
        throw new RuntimeException('Set SIMCLOUD_ZOHO_CONFIG to the private configuration file.');
    }
    $config = require $path;
    if (!is_array($config)) { throw new RuntimeException('Configuration must return an array.'); }
    foreach (['organization_id', 'bridge_key', 'simcloud_token', 'database', 'encryption_key', 'zoho_region', 'recipient_field', 'meter_field'] as $key) {
        if (!is_string($config[$key] ?? null) || $config[$key] === '' || preg_match('/[\r\n]/', $config[$key])) {
            throw new RuntimeException('Required private configuration is incomplete.');
        }
    }
    $encryption = base64_decode($config['encryption_key'], true);
    if (strlen($config['bridge_key']) < 32 || sc_id($config['organization_id']) === '' || $encryption === false || strlen($encryption) !== 32
        || !in_array($config['zoho_region'], ['com', 'eu', 'in', 'com.au', 'jp', 'ca', 'com.cn', 'sa'], true)) {
        throw new RuntimeException('Invalid organization, region or security keys.');
    }
    $docroot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    $private = realpath($path);
    $databaseDir = realpath(dirname($config['database']));
    if ($docroot && (($private && str_starts_with($private, $docroot . '/')) || ($databaseDir && ($databaseDir === $docroot || str_starts_with($databaseDir, $docroot . '/'))))) {
        throw new RuntimeException('Configuration and database must be above the public web root.');
    }
    return $config;
}

function sc_authorized(array $config, string $header, string $organization): bool {
    return $organization === $config['organization_id'] && strlen($config['bridge_key']) >= 32
        && hash_equals('Bearer ' . $config['bridge_key'], $header);
}

function sc_bindings(array $config): array {
    return [
        fn(string $method, string $path, array $data = [], ?string $image = null): array => sc_zoho($config, $method, $path, $data, $image),
        fn(string $service, string $method = 'GET', array $data = []): array => sc_simcloud($config, $service, $method, $data),
        fn(string $to, string $subject, string $text, string $from): bool => mail($to, $subject, $text, ['From' => $from, 'Content-Type' => 'text/plain; charset=UTF-8']),
    ];
}
