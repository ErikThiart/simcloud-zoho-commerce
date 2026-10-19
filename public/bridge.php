<?php
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('zend.exception_ignore_args', '1');
require_once dirname(__DIR__) . '/server/bootstrap.php';
umask(0077);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');

function sc_reply(int $code, array $body): never {
    http_response_code($code);
    echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $organization = $_SERVER['HTTP_X_SIMCLOUD_ORGANIZATION'] ?? '';
    if (!is_string($authorization) || !preg_match('/^Bearer [^\s]{32,4096}$/D', $authorization) || sc_id($organization) === '') {
        sc_reply(401, ['error' => 'Connector authorization failed.']);
    }
    $config = sc_config();
    if (!sc_authorized($config, $_SERVER['HTTP_AUTHORIZATION'] ?? '', $_SERVER['HTTP_X_SIMCLOUD_ORGANIZATION'] ?? '')) {
        sc_reply(401, ['error' => 'Connector authorization failed.']);
    }
    $action = sc_text($_GET['action'] ?? '');
    $methods = ['health' => 'GET', 'catalogue' => 'GET', 'import' => 'POST', 'fixed' => 'POST', 'order' => 'GET', 'enqueue' => 'POST'];
    if (!isset($methods[$action]) || ($_SERVER['REQUEST_METHOD'] ?? '') !== $methods[$action]) {
        sc_reply(405, ['error' => 'Unsupported connector action or method.']);
    }
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) { sc_reply(413, ['error' => 'Request exceeds the size limit.']); }
    $input = [];
    if ($methods[$action] === 'POST') {
        $raw = file_get_contents('php://input', false, null, 0, 16385);
        if (!is_string($raw) || strlen($raw) > 16384) { sc_reply(413, ['error' => 'Request exceeds the size limit.']); }
        $input = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($input)) { sc_reply(400, ['error' => 'Expected a JSON object.']); }
    }
    $db = sc_db($config);
    [$zoho, $simcloud] = sc_bindings($config);
    if ($action === 'health') {
        $balance = $simcloud('balance', 'GET', [])['body'];
        if (($balance['status'] ?? '') !== 'success') { throw new RuntimeException('Balance check failed.'); }
        sc_reply(200, ['status' => 'connected', 'organization_id' => $config['organization_id'], 'live_enabled' => $config['live_enabled'] === true,
            'balance' => $balance['balance'] ?? null, 'checked_at' => gmdate(DATE_ATOM)]);
    }
    if ($action === 'catalogue') { sc_reply(200, sc_catalogue_fetch($simcloud) + ['checked_at' => gmdate(DATE_ATOM)]); }
    if (in_array($action, ['import', 'fixed'], true)) {
        $lock = fopen(dirname($config['database']) . '/import.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { sc_reply(409, ['error' => 'Another product import is running.']); }
        try {
            if ($action === 'fixed') { $products = [sc_fixed_product($input)]; }
            else {
                $skus = $input['skus'] ?? [];
                if (!is_array($skus) || !$skus || count($skus) > 3 || count(array_filter($skus, 'is_string')) !== count($skus)) {
                    sc_reply(400, ['error' => 'Choose between one and three catalogue products per request.']);
                }
                $catalogue = sc_catalogue_fetch($simcloud);
                $products = array_values(array_filter($catalogue['products'], fn(array $product): bool => in_array($product['sku'], $skus, true)));
                if (count($products) !== count(array_unique($skus))) { sc_reply(400, ['error' => 'One or more products are no longer available. Refresh the catalogue.']); }
            }
            $report = sc_import($db, $config, $products, $zoho);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
        sc_reply(200, $report);
    }
    $orderId = sc_id($input['order_id'] ?? $_GET['order_id'] ?? '');
    if ($orderId === '') { sc_reply(400, ['error' => 'A valid sales order ID is required.']); }
    if ($action === 'enqueue') {
        if (($config['live_enabled'] ?? false) !== true) { sc_reply(409, ['error' => 'Live fulfilment is disabled in the private connector configuration.']); }
        sc_reply(202, ['rows' => sc_prepare($db, $config, $orderId, $zoho)]);
    }
    sc_order($zoho, $orderId); // Verify ownership through the configured Zoho organization before exposing results.
    sc_reply(200, ['rows' => sc_order_rows($db, $config, $orderId)]);
} catch (JsonException $error) {
    sc_reply(400, ['error' => 'Invalid JSON request.']);
} catch (Throwable $error) {
    sc_log('connector_request_failed');
    sc_reply(503, ['error' => 'Connector could not complete this request. Check configuration, permissions and private service logs.']);
}
