<?php
declare(strict_types=1);
require_once (getenv('SC_TEST_RUNTIME') ?: dirname(__DIR__)) . '/server/bootstrap.php';
error_reporting(E_ALL);
set_error_handler(function (int $level, string $message, string $file, int $line): never { throw new ErrorException($message, 0, $level, $file, $line); });
$checks = 0;
function check(bool $condition, string $label): void { global $checks; $checks++; if (!$condition) { throw new RuntimeException('FAIL: ' . $label); } }
function rejects(callable $call, string $label): void { try { $call(); } catch (Throwable $error) { check(true, $label); return; } check(false, $label); }
rejects(fn() => sc_http('http://example.invalid', 'GET', []), 'unencrypted HTTP requests rejected before transport');
rejects(fn() => sc_http('file:///etc/passwd', 'GET', []), 'local file transport rejected');
$config = ['organization_id' => '12345', 'database' => ':memory:', 'encryption_key' => base64_encode(random_bytes(32)),
    'bridge_key' => str_repeat('x', 40), 'live_enabled' => true, 'verified_payment_modes' => ['Stripe'],
    'recipient_field' => 'cf_simcloud_recipient', 'meter_field' => 'cf_simcloud_meter', 'mail_from' => 'orders@example.com'];
$data = [['product_id' => 10, 'network' => 'pd-mtn', 'description' => 'MTN 1GB', 'amount' => '29.00', 'groupname' => 'Monthly'],
    ['product_id' => 11, 'network' => 'pd-vodacom', 'description' => 'Vodacom 1GB', 'amount' => '39.00']];
$vas = [['product_id' => 20, 'voucher_type' => 'Shoprite', 'slug' => 'shoprite', 'available_amounts' => [50, 100], 'logo_url' => 'https://simcloud.co.za/img/vas_logos/shoprite.jpg']];
$catalogue = sc_catalogue($data, $vas);
check(count($catalogue['products']) === 4, 'catalogue creates each data bundle and VAS denomination');
check($catalogue['products'][0]['logo'] === 'networks/mtn.jpg', 'bundled data logo');
check($catalogue['products'][2]['logo'] === 'vas/shoprite.jpg', 'bundled VAS logo');
check($catalogue['products'][2]['amount'] === '50.00', 'API amount is retail price');
check(count(sc_catalogue(array_merge($data, [$data[0]]), [])['products']) === 2, 'identical catalogue rows deduplicate');
$ambiguous = $data[0]; $ambiguous['product_id'] = 12;
check(count(sc_catalogue([$data[0], $ambiguous], [])['products']) === 0, 'ambiguous data amounts skipped');
check(count(sc_catalogue([['amount' => -2]], [['product_id' => 1, 'available_amounts' => 'bad']])['products']) === 0, 'malformed catalogue rows skipped');
check(sc_phone('082 123 4567') === '+27821234567', 'phone normalizes');
check(sc_phone('001234') === '', 'foreign/invalid phone rejected');
check(sc_cents('1.001') === null && sc_cents('-1') === null && sc_cents(null) === null, 'strict currency parser');
check(sc_decimal(1234) === '12.34', 'exact currency serialization');
rejects(fn() => sc_fixed_product(['service' => 'electricity', 'amount' => '54']), 'minimum electricity');
rejects(fn() => sc_fixed_product(['service' => 'electricity', 'amount' => '55.50']), 'whole rand electricity');
rejects(fn() => sc_fixed_product(['service' => 'airtime', 'amount' => '1000', 'network' => 'mtn']), 'maximum airtime');
check(sc_fixed_product(['service' => 'airtime', 'amount' => '10', 'network' => 'telkom'])['network'] === 'heita', 'fixed network mapping');
check(sc_authorized($config, 'Bearer ' . $config['bridge_key'], '12345'), 'connector authenticates matching organization');
check(!sc_authorized($config, 'Bearer ' . $config['bridge_key'], '54321'), 'cross-organization blocked');
check(!sc_authorized($config, 'Bearer wrong', '12345'), 'wrong credential blocked');
$cipher = sc_encrypt(['recipient' => '+27821234567', 'code' => 'SECRET'], $config);
check(!str_contains($cipher, 'SECRET') && sc_decrypt($cipher, $config)['code'] === 'SECRET', 'encrypted records round trip');
$badKey = $config; $badKey['encryption_key'] = base64_encode(random_bytes(32));
rejects(fn() => sc_decrypt($cipher, $badKey), 'wrong encryption key fails closed');
$db = sc_db($config);
$remote = []; $creates = 0; $uploads = 0;
$zoho = function ($method, $path, $body = [], $image = null) use (&$remote, &$creates, &$uploads) {
    if ($method === 'GET' && $path === '/products') { return ['products' => array_values($remote), 'page_context' => ['has_more_page' => false]]; }
    if ($method === 'POST' && $path === '/products') {
        $creates++; $id = (string) (100 + $creates); $variant = (string) (200 + $creates);
        $product = ['product_id' => $id, 'name' => $body['name'], 'document_id' => '', 'variants' => [['variant_id' => $variant, 'sku' => $body['variants'][0]['sku'], 'rate' => $body['variants'][0]['rate']]]];
        $remote[$id] = $product; return ['product' => $product];
    }
    if ($image !== null) { $uploads++; $id = explode('/', $path)[2]; $remote[$id]['document_id'] = '999'; return ['code' => 0]; }
    throw new RuntimeException('Unexpected Zoho call in test.');
};
$report = sc_import($db, $config, $catalogue['products'], $zoho);
check($report['created'] === 4 && $report['images'] === 4, 'imports products and local images');
$remote['101']['name'] = 'Merchant name'; $remote['101']['variants'][0]['rate'] = '99.00';
$report = sc_import($db, $config, $catalogue['products'], $zoho);
check($report['existing'] === 4 && $creates === 4 && $uploads === 4, 'repeat import creates no duplicates');
check($remote['101']['name'] === 'Merchant name' && $remote['101']['variants'][0]['rate'] === '99.00', 'merchant name and price preserved');
unset($remote['102']);
sc_import($db, $config, [$catalogue['products'][1]], $zoho);
check($creates === 4, 'removed merchant product is not recreated');
$uncertain = sc_fixed_product(['service' => 'airtime', 'network' => 'mtn', 'amount' => 10]);
$unknownCreates = 0;
$unknownZoho = function ($method) use (&$unknownCreates) { if ($method === 'GET') { return ['products' => [], 'page_context' => ['has_more_page' => false]]; } $unknownCreates++; throw new RuntimeException('Lost create response.'); };
sc_import($db, $config, [$uncertain], $unknownZoho); sc_import($db, $config, [$uncertain], $unknownZoho);
check($unknownCreates === 1, 'uncertain product create is never repeated');
$recoveredZoho = function ($method, $path) use (&$unknownCreates, $uncertain) {
    if ($method === 'GET') { return ['products' => [['product_id' => '801', 'document_id' => '899', 'variants' => [['variant_id' => '802', 'sku' => $uncertain['sku']]]]], 'page_context' => ['has_more_page' => false]]; }
    $unknownCreates++; throw new RuntimeException('Recovery must not submit another create.');
};
$recovered = sc_import($db, $config, [$uncertain], $recoveredZoho);
check($recovered['existing'] === 1 && $unknownCreates === 1, 'matching existing SKU reconciles a held import without another create');
check($db->query("SELECT state FROM products WHERE sku='" . $uncertain['sku'] . "'")->fetchColumn() === 'imported', 'reconciled product stores the verified remote identity');
check(sc_product_body($uncertain)['variant_type'] === 'sales_and_purchases', 'product type matches connected Zoho service API');
check(sc_product_body($uncertain)['variants'][0]['maximum_order_quantity'] === '1', 'prepaid product requests one recipient per line');

rejects(fn() => sc_existing_products(fn() => ['products' => []]), 'incomplete pagination blocks import');
$order = ['salesorder_id' => '9001', 'currency_code' => 'ZAR', 'invoiced_status' => 'paid', 'order_status' => 'confirmed',
    'is_offline_payment' => false, 'is_test_order' => false, 'payment_mode' => 'Stripe', 'total' => '29.00',
    'payments' => [['payment_id' => '80', 'online_transaction_id' => 'confirmed-txn', 'amount' => '29.00']],
    'contact_persons' => [['email' => 'buyer@example.com']], 'line_items' => [['line_item_id' => '701', 'item_id' => '201', 'quantity' => 1,
        'item_custom_fields' => [['api_name' => 'cf_simcloud_recipient', 'value' => '0821234567']]]]];
check(sc_paid($order, $config), 'verified paid online ZAR order accepted');
foreach (['is_offline_payment' => true, 'is_test_order' => true, 'currency_code' => 'USD', 'invoiced_status' => 'unpaid', 'order_status' => 'cancelled', 'payment_mode' => 'Cash On Delivery', 'payments' => []] as $key => $value) {
    $bad = $order; $bad[$key] = $value; check(!sc_paid($bad, $config), 'payment gate rejects ' . $key);
}
$orderZoho = function ($method, $path) use (&$order) { return ['salesorder' => $order]; };
$rows = sc_prepare($db, $config, '9001', $orderZoho); sc_prepare($db, $config, '9001', $orderZoho);
check(count($rows) === 1 && (int) $db->query('SELECT COUNT(*) FROM ledger')->fetchColumn() === 1, 'repeated webhook creates one durable ledger row');
$snapshot = sc_decrypt(sc_row($db, 1)['snapshot'], $config);
check($snapshot['amount'] === '29.00' && $snapshot['recipient'] === '+27821234567', 'trusted product face value and recipient persisted');
$posts = 0;
$simcloud = function ($service, $method, $body) use (&$posts, &$data, $snapshot) {
    if ($service === 'network') { return ['code' => 200, 'body' => ['status' => 'success', 'network' => 'MTN']]; }
    if ($method === 'GET' && isset($body['products'])) { return ['code' => 200, 'body' => ['status' => 'success', 'products' => $data]]; }
    if ($method === 'POST') { $posts++; return ['code' => 201, 'body' => array_merge($body, ['status' => 'success', 'service' => $service, 'request_id' => 301])]; }
    return ['code' => 200, 'body' => array_merge(sc_payload($snapshot, sc_row($GLOBALS['db'], 1)['reference']), ['status' => 'delivered', 'service' => $service, 'request_id' => 301])];
};
$off = $config; $off['live_enabled'] = false;
sc_submit($db, $off, 1, $orderZoho, $simcloud); check($posts === 0, 'disabled live flag blocks purchases');
sc_submit($db, $config, 1, $orderZoho, $simcloud); sc_submit($db, $config, 1, $orderZoho, $simcloud);
check($posts === 1 && sc_row($db, 1)['state'] === 'queued', 'one submission despite repeated processing');
$db->exec('UPDATE ledger SET next_poll=0 WHERE id=1'); sc_poll($db, $config, 1, $simcloud);
check(sc_row($db, 1)['state'] === 'delivered', 'matching status confirms delivery');
$row = sc_row($db, 1);
rejects(fn() => sc_status(['status' => 'delivered', 'request_id' => 999], $snapshot, $row), 'mismatched provider identity blocked');
$order['salesorder_id'] = '9002';
sc_prepare($db, $config, '9002', $orderZoho);
$timeoutPosts = 0;
$timeout = function ($service, $method, $body) use ($simcloud, &$timeoutPosts) { if ($method === 'POST') { $timeoutPosts++; throw new RuntimeException('Timeout after provider may accept.'); } return $simcloud($service, $method, $body); };
sc_submit($db, $config, 2, $orderZoho, $timeout); sc_submit($db, $config, 2, $orderZoho, $timeout);
check($timeoutPosts === 1 && sc_row($db, 2)['state'] === 'needs_review', 'ambiguous purchase held without retry');
$order['salesorder_id'] = '9003'; sc_prepare($db, $config, '9003', $orderZoho); $order['invoiced_status'] = 'refunded';
sc_submit($db, $config, 3, $orderZoho, $simcloud);
check($posts === 1 && sc_row($db, 3)['state'] === 'needs_review', 'refund before submission blocks purchase');
$vasSnapshot = $catalogue['products'][2] + ['recipient' => '', 'meter' => '', 'email' => 'buyer@example.com'];
$vasAck = ['status' => 'success', 'service' => 'vas', 'order_id' => '333', 'product_id' => '20', 'amount' => 50, 'reference' => 'ref'];
check(sc_ack($vasAck, $vasSnapshot, 'ref'), 'VAS acknowledgement validated');
$vasBody = array_merge($vasAck, ['vouchers' => [['voucher_code' => 'PRIVATE-CODE', 'voucher_type' => 'Shoprite', 'expiry_date' => null]]]);
$parsed = sc_status($vasBody, $vasSnapshot, ['reference' => 'ref', 'provider_id' => '333']);
check($parsed['result'][0]['code'] === 'PRIVATE-CODE', 'voucher code normalized');
rejects(fn() => sc_status($vasAck, $vasSnapshot, ['reference' => 'ref', 'provider_id' => '333']), 'success without voucher blocked');
$electricity = sc_fixed_product(['service' => 'electricity', 'amount' => 100]) + ['recipient' => '+27821234567', 'meter' => '12345678901'];
$payload = sc_payload($electricity, 'e-ref'); check($payload['send_sms'] === false && !isset($payload['reference']), 'electricity payload shape');
$electricAck = ['status' => 'success', 'order_id' => '444', 'amount' => 100, 'submitted_reference' => 'e-ref', 'client_reference' => 'e-ref-time', 'meter_number' => $electricity['meter'], 'recipient' => $electricity['recipient']];
check(sc_ack($electricAck, $electricity, 'e-ref'), 'electricity transformed reference accepted');
$electricStatus = ['status' => 'success', 'transaction_details' => ['meter_number' => $electricity['meter'], 'recipient' => $electricity['recipient']],
    'recharge_pin_information' => [['pin' => 'KEY-CHANGE-ONE', 'description' => 'Key change'], ['pin' => 'ENERGY-TOKEN', 'units' => '35.8']]];
check(count(sc_status($electricStatus, $electricity, [])['result']) === 2, 'all electricity tokens preserved in order');
rejects(fn() => sc_snapshot($electricity, ['quantity' => 2], $config), 'quantity greater than one rejected');
$stmt = $db->prepare('INSERT INTO ledger(order_id,line_id,reference,state,snapshot,result,created_at,updated_at) VALUES(?,?,?,\'delivered\',?,?,?,?)');
$stmt->execute(['9004', '704', 'mail-ref', sc_encrypt($vasSnapshot, $config), sc_encrypt($parsed['result'], $config), time(), time()]);
$mailCount = 0; $mailer = function ($to, $subject, $body) use (&$mailCount) { $mailCount++; check($to === 'buyer@example.com' && str_contains($body, 'PRIVATE-CODE'), 'delivery email goes to verified order contact'); return true; };
sc_notify($db, $config, 4, $mailer); sc_notify($db, $config, 4, $mailer);
check($mailCount === 1, 'delivery notification sent once');
check(!str_contains(sc_row($db, 4)['result'], 'PRIVATE-CODE'), 'redeemable codes encrypted at rest');
check(!sc_transition($db, 1, 'ready', 'submitting'), 'atomic stale claim cannot win');
// Exercise the full prepare -> submit -> poll lifecycle for each service, not only response parsers.
foreach (['airtime', 'data', 'vas', 'electricity'] as $service) {
    $flowDb = sc_db($config);
    $flowProduct = match ($service) {
        'data' => $catalogue['products'][0],
        'vas' => $catalogue['products'][2],
        default => sc_fixed_product(['service' => $service, 'network' => 'mtn', 'amount' => $service === 'electricity' ? 100 : 10]),
    };
    $stmt = $flowDb->prepare('INSERT INTO products(sku,product_id,variant_id,state,snapshot) VALUES(?,?,?,\'imported\',?)');
    $stmt->execute([$flowProduct['sku'], '101', '201', json_encode($flowProduct, JSON_THROW_ON_ERROR)]);
    $flowOrder = $order;
    $flowOrder['salesorder_id'] = '9100'; $flowOrder['invoiced_status'] = 'paid';
    $flowOrder['total'] = $flowProduct['amount']; $flowOrder['payments'][0]['amount'] = $flowProduct['amount'];
    $flowOrder['line_items'][0]['item_custom_fields'][] = ['api_name' => 'cf_simcloud_meter', 'value' => '00123456789'];
    $flowZoho = fn() => ['salesorder' => $flowOrder];
    $flowPosts = 0; $ack = [];
    $flowProvider = function ($endpoint, $method, $input) use ($service, $data, $vas, &$flowPosts, &$ack) {
        if ($method === 'POST') {
            $flowPosts++;
            $ack = $service === 'electricity'
                ? ['status' => 'success', 'order_id' => 600, 'amount' => $input['amount'], 'submitted_reference' => $input['client_reference'],
                    'client_reference' => $input['client_reference'] . '-provider', 'meter_number' => $input['meter_number'], 'recipient' => $input['recipient']]
                : $input + ['status' => 'success', 'service' => $service, $service === 'vas' ? 'order_id' : 'request_id' => 600];
            return ['code' => 201, 'body' => $ack];
        }
        if ($endpoint === 'network') { return ['code' => 200, 'body' => ['status' => 'success', 'network' => 'MTN']]; }
        if (isset($input['meter_number'])) { return ['code' => 200, 'body' => ['supported' => true, 'meter_number' => $input['meter_number']]]; }
        if (isset($input['products']) || ($endpoint === 'vas' && !$input)) { return ['code' => 200, 'body' => ['status' => 'success', 'products' => $endpoint === 'vas' ? $vas : $data]]; }
        if ($service === 'electricity') { return ['code' => 200, 'body' => ['status' => 'success',
            'transaction_details' => ['meter_number' => $ack['meter_number'], 'recipient' => $ack['recipient']],
            'recharge_pin_information' => [['pin' => 'SYNTHETIC-KEY-CHANGE'], ['pin' => 'SYNTHETIC-TOKEN', 'units' => 25]]]]; }
        $body = $ack; $body['status'] = $service === 'vas' ? 'success' : 'delivered';
        if ($service === 'vas') { $body['vouchers'] = [['voucher_code' => 'SYNTHETIC-VOUCHER', 'voucher_type' => 'Shoprite']]; }
        return ['code' => 200, 'body' => $body];
    };
    $flowRows = sc_prepare($flowDb, $config, '9100', $flowZoho);
    check(count($flowRows) === 1, $service . ' end-to-end prepared');
    sc_submit($flowDb, $config, 1, $flowZoho, $flowProvider);
    check($flowPosts === 1 && sc_row($flowDb, 1)['state'] === 'queued', $service . ' end-to-end submitted');
    sc_submit($flowDb, $config, 1, $flowZoho, $flowProvider);
    check($flowPosts === 1, $service . ' end-to-end duplicate blocked');
    $flowDb->exec('UPDATE ledger SET next_poll=0 WHERE id=1'); sc_poll($flowDb, $config, 1, $flowProvider);
    check(sc_row($flowDb, 1)['state'] === 'delivered', $service . ' end-to-end delivery matched');
    if ($service === 'electricity') { check(count(sc_decrypt(sc_row($flowDb, 1)['result'], $config)) === 2, 'electricity lifecycle retains key-change token order'); }
}
$backlogDb = sc_db($config);
$backlogProduct = $catalogue['products'][0] + ['recipient' => '+27821234567', 'meter' => '', 'email' => ''];
$stmt = $backlogDb->prepare('INSERT INTO ledger(order_id,line_id,reference,state,snapshot,provider_id,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)');
for ($i = 1; $i <= 101; $i++) {
    $stmt->execute([(string) (20000 + $i), (string) $i, 'backlog-' . $i, 'ready', sc_encrypt($backlogProduct, $config), '', time(), time()]);
}
$stmt->execute(['30000', '102', 'accepted-before-pause', 'queued', sc_encrypt($backlogProduct, $config), '600', time(), time()]);
$backlogReads = 0;
$pausedProvider = function ($service, $method, $input) use (&$backlogReads, $backlogProduct) {
    check($method === 'GET', 'paused worker performs no purchase'); $backlogReads++;
    return ['code' => 200, 'body' => sc_payload($backlogProduct, 'accepted-before-pause') + ['status' => 'delivered', 'service' => 'data', 'request_id' => 600]];
};
sc_work($backlogDb, $off, fn() => throw new RuntimeException('No new order reads while paused.'), $pausedProvider, fn() => true);
check($backlogReads === 1 && sc_row($backlogDb, 102)['state'] === 'delivered', 'paused ready backlog cannot starve accepted purchase reconciliation');
check(sc_row($backlogDb, 1)['state'] === 'ready', 'paused backlog remains unsubmitted');
echo "Passed {$checks} server assertions (simulated providers, real SQLite).\n";
