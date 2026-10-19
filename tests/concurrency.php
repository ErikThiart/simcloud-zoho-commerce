<?php
declare(strict_types=1);
require_once (getenv('SC_TEST_RUNTIME') ?: dirname(__DIR__)) . '/server/bootstrap.php';

function race_config(string $directory): array {
    return ['organization_id' => '12345', 'database' => $directory . '/ledger.sqlite', 'encryption_key' => base64_encode(str_repeat('k', 32)),
        'live_enabled' => true, 'verified_payment_modes' => ['Stripe'], 'recipient_field' => 'cf_simcloud_recipient', 'meter_field' => 'cf_simcloud_meter'];
}
function race_order(): array {
    return ['salesorder_id' => '9001', 'currency_code' => 'ZAR', 'invoiced_status' => 'paid', 'order_status' => 'confirmed',
        'is_offline_payment' => false, 'is_test_order' => false, 'payment_mode' => 'Stripe', 'total' => '10.00', 'contact_persons' => [],
        'payments' => [['payment_id' => '80', 'online_transaction_id' => 'fixture-txn', 'amount' => '10.00']],
        'line_items' => [['line_item_id' => '701', 'item_id' => '201', 'quantity' => 1,
            'item_custom_fields' => [['api_name' => 'cf_simcloud_recipient', 'value' => '0821234567']]]]];
}
if (($argv[1] ?? '') === 'child') {
    $directory = $argv[2]; $config = race_config($directory); $db = sc_db($config);
    $zoho = fn() => ['salesorder' => race_order()];
    $simcloud = function ($service, $method, $payload) use ($directory) {
        if ($method === 'GET') { usleep(30000); return ['code' => 200, 'body' => ['status' => 'success', 'network' => 'MTN']]; }
        file_put_contents($directory . '/attempts.txt', "POST\n", FILE_APPEND | LOCK_EX);
        usleep(50000);
        return ['code' => 201, 'body' => $payload + ['status' => 'success', 'service' => 'airtime', 'request_id' => 301]];
    };
    sc_submit($db, $config, 1, $zoho, $simcloud);
    exit(0);
}
$directory = sys_get_temp_dir() . '/simcloud-zoho-race-' . bin2hex(random_bytes(8));
mkdir($directory, 0700); $config = race_config($directory); $db = sc_db($config);
try {
    $product = sc_fixed_product(['service' => 'airtime', 'network' => 'mtn', 'amount' => '10.00']);
    $stmt = $db->prepare('INSERT INTO products(sku,product_id,variant_id,state,snapshot) VALUES(?,?,?,\'imported\',?)');
    $stmt->execute([$product['sku'], '101', '201', json_encode($product, JSON_THROW_ON_ERROR)]);
    sc_prepare($db, $config, '9001', fn() => ['salesorder' => race_order()]);
    $children = [];
    for ($i = 0; $i < 8; $i++) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, __FILE__, 'child', $directory], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('Unable to start concurrency test process.'); }
        fclose($pipes[0]); $children[] = [$process, $pipes];
    }
    foreach ($children as [$process, $pipes]) {
        stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) { throw new RuntimeException('Concurrency process failed: ' . $errors); }
    }
    $attempts = file($directory . '/attempts.txt', FILE_IGNORE_NEW_LINES);
    if (count($attempts) !== 1 || sc_row($db, 1)['state'] !== 'queued') { throw new RuntimeException('Concurrent workers caused an unsafe submission count.'); }
    echo "Passed real concurrency test: 8 PHP processes, 1 purchase attempt, 1 saved provider ID.\n";
} finally {
    $db = null;
    foreach (glob($directory . '/*') ?: [] as $file) { unlink($file); }
    rmdir($directory);
}
