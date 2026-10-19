<?php
declare(strict_types=1);

function sc_order(callable $zoho, string $orderId): array {
    if (sc_id($orderId) === '') { throw new RuntimeException('A valid Zoho sales order ID is required.'); }
    $body = $zoho('GET', '/salesorders/' . $orderId, []);
    $order = $body['salesorder'] ?? [];
    if (!is_array($order) || sc_id($order['salesorder_id'] ?? '') !== $orderId || !is_array($order['line_items'] ?? null)) {
        throw new RuntimeException('Zoho order identity or line items could not be verified.');
    }
    return $order;
}

function sc_order_email(array $order): string {
    $emails = [];
    foreach ($order['contact_persons'] ?? [] as $person) {
        $email = sc_text(is_array($person) ? ($person['email'] ?? '') : '');
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) { $emails[strtolower($email)] = $email; }
    }
    // Ambiguous contacts need merchant review; never pick an arbitrary destination for redeemable codes.
    return count($emails) === 1 ? reset($emails) : '';
}

function sc_prepare(PDO $db, array $config, string $orderId, callable $zoho): array {
    $order = sc_order($zoho, $orderId);
    if (!sc_paid($order, $config)) { throw new RuntimeException('Order is not a verified paid ZAR online purchase.'); }
    $prepared = [];
    foreach ($order['line_items'] as $line) {
        if (!is_array($line)) { throw new RuntimeException('Malformed order line.'); }
        $variantId = sc_id($line['item_id'] ?? '');
        $stmt = $db->prepare('SELECT snapshot FROM products WHERE variant_id=? AND state=\'imported\'');
        $stmt->execute([$variantId]); $saved = $stmt->fetchColumn();
        if ($saved === false) { continue; } // Ordinary merchant products are left to Zoho.
        $lineId = sc_id($line['line_item_id'] ?? '');
        if ($lineId === '') { throw new RuntimeException('Order line identifier is missing.'); }
        $snapshot = sc_snapshot(json_decode($saved, true, 512, JSON_THROW_ON_ERROR), $line, $config);
        $snapshot['email'] = sc_order_email($order);
        if (in_array($snapshot['service'], ['vas', 'electricity'], true)
            && ($snapshot['email'] === '' || !filter_var($config['mail_from'] ?? '', FILTER_VALIDATE_EMAIL))) {
            throw new RuntimeException('Voucher/token delivery requires one verified order contact and a configured sender.');
        }
        $prepared[$lineId] = $snapshot;
    }
    if (!$prepared) { throw new RuntimeException('No imported SIMcloud products were found on this order.'); }
    $db->beginTransaction();
    try {
        foreach ($prepared as $lineId => $snapshot) {
            $reference = 'zc-' . substr(hash('sha256', $config['organization_id'] . ':' . $orderId . ':' . $lineId), 0, 28);
            $stmt = $db->prepare('INSERT OR IGNORE INTO ledger(order_id,line_id,reference,state,snapshot,created_at,updated_at) VALUES(?,?,?,\'ready\',?,?,?)');
            $stmt->execute([$orderId, (string) $lineId, $reference, sc_encrypt($snapshot, $config), time(), time()]);
        }
        $db->commit();
    } catch (Throwable $error) { $db->rollBack(); throw $error; }
    return sc_order_rows($db, $config, $orderId);
}

function sc_order_rows(PDO $db, array $config, string $orderId): array {
    $stmt = $db->prepare('SELECT * FROM ledger WHERE order_id=? ORDER BY id'); $stmt->execute([$orderId]);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $product = sc_decrypt($row['snapshot'], $config);
        $rows[] = ['id' => $row['id'], 'line_id' => $row['line_id'], 'name' => $product['name'], 'service' => $product['service'],
            'amount' => $product['amount'], 'state' => $row['state'], 'provider_id' => $row['provider_id'], 'reference' => $row['reference'],
            'message' => $row['message'], 'notified' => $row['notified_at'] > 0,
            'result' => $row['result'] !== '' ? sc_decrypt($row['result'], $config) : []];
    }
    return $rows;
}

function sc_preflight(array $snapshot, callable $simcloud): void {
    $service = $snapshot['service'];
    if (in_array($service, ['airtime', 'data'], true)) {
        $body = $simcloud('network', 'GET', ['msisdn' => $snapshot['recipient']])['body'];
        if (($body['status'] ?? '') !== 'success' || sc_network($body['network'] ?? '') !== $snapshot['network']) {
            throw new RuntimeException('Recipient network could not be verified.');
        }
    }
    if ($service === 'electricity') {
        $body = $simcloud('electricity', 'GET', ['meter_number' => $snapshot['meter']])['body'];
        if (($body['supported'] ?? null) !== true || ($body['meter_number'] ?? '') !== $snapshot['meter']) {
            throw new RuntimeException('Meter support could not be verified.');
        }
    }
    if (in_array($service, ['data', 'vas'], true)) {
        $body = $simcloud($service, 'GET', $service === 'data' ? ['products' => 1] : [])['body'];
        if (($body['status'] ?? '') !== 'success' || !is_array($body['products'] ?? null)) { throw new RuntimeException('Catalogue recheck failed.'); }
        $catalogue = sc_catalogue($service === 'data' ? $body['products'] : [], $service === 'vas' ? $body['products'] : []);
        $matched = false;
        foreach ($catalogue['products'] as $product) {
            if ($product['sku'] === $snapshot['sku'] && $product['network'] === $snapshot['network'] && $product['amount'] === $snapshot['amount']) { $matched = true; break; }
        }
        if (!$matched) { throw new RuntimeException('Purchased product or denomination is no longer available.'); }
    }
}

function sc_submit(PDO $db, array $config, int $id, callable $zoho, callable $simcloud): void {
    $row = sc_row($db, $id);
    if (!$row || $row['state'] !== 'ready' || ($config['live_enabled'] ?? false) !== true) { return; }
    try {
        $snapshot = sc_decrypt($row['snapshot'], $config);
        $order = sc_order($zoho, $row['order_id']);
        if (!sc_paid($order, $config)) { throw new RuntimeException('Payment recheck failed.'); }
        $matched = false;
        foreach ($order['line_items'] as $line) {
            if (sc_id($line['line_item_id'] ?? '') !== $row['line_id']) { continue; }
            if (sc_id($line['item_id'] ?? '') === '') { throw new RuntimeException('Item identity missing.'); }
            $stmt = $db->prepare('SELECT variant_id FROM products WHERE sku=? AND state=\'imported\''); $stmt->execute([$snapshot['sku']]);
            $savedVariant = $stmt->fetchColumn();
            $stmt->closeCursor(); // End the SQLite read snapshot before claiming a write after network checks.
            if ($savedVariant !== sc_id($line['item_id'] ?? '')) { throw new RuntimeException('Product changed after payment.'); }
            $current = sc_snapshot($snapshot, $line, $config);
            if ($current['recipient'] !== $snapshot['recipient'] || $current['meter'] !== $snapshot['meter'] || sc_order_email($order) !== $snapshot['email']) {
                throw new RuntimeException('Recipient changed after the order snapshot.');
            }
            $matched = true;
        }
        if (!$matched) { throw new RuntimeException('Purchased line no longer exists.'); }
        sc_preflight($snapshot, $simcloud);
    } catch (Throwable $error) {
        sc_transition($db, $id, 'ready', 'needs_review', ['message' => 'Payment, product or recipient validation failed before submission.']);
        sc_log('preflight_requires_review', $row, $error->getCode()); return;
    }
    if (!sc_transition($db, $id, 'ready', 'submitting')) { return; }
    try {
        $response = $simcloud($snapshot['service'], 'POST', sc_payload($snapshot, $row['reference']));
        if ($response['code'] !== 201 || !sc_ack($response['body'], $snapshot, $row['reference'])) {
            throw new RuntimeException('Acknowledgement mismatch.');
        }
        $body = $response['body'];
        sc_transition($db, $id, 'submitting', 'queued', ['provider_id' => (string) ($body['request_id'] ?? $body['order_id']),
            'provider_reference' => sc_text($body['client_reference'] ?? $body['transaction_id'] ?? ''), 'next_poll' => time() + 30]);
        sc_log('purchase_queued', sc_row($db, $id));
    } catch (Throwable $error) {
        sc_transition($db, $id, 'submitting', 'needs_review', ['message' => 'Purchase outcome uncertain. Reconcile with SIMcloud; never repeat automatically.']);
        sc_log('submission_outcome_unknown', $row, $error->getCode());
    }
}

function sc_poll(PDO $db, array $config, int $id, callable $simcloud): void {
    $row = sc_row($db, $id);
    if (!$row || !in_array($row['state'], ['queued', 'pending'], true) || sc_id($row['provider_id']) === '' || $row['next_poll'] > time()) { return; }
    $snapshot = sc_decrypt($row['snapshot'], $config);
    $polls = (int) $row['polls'] + 1;
    if ($polls > 16 || $row['created_at'] < time() - 3600) {
        sc_transition($db, $id, $row['state'], 'needs_review', ['message' => 'Status polling limit reached. Investigate using the saved provider ID.']); return;
    }
    // Claim this read before transport to avoid two worker processes racing terminal results.
    if (!sc_transition($db, $id, $row['state'], 'polling', ['polls' => $polls, 'next_poll' => time() + min(300, 30 * $polls)])) { return; }
    try {
        $field = in_array($snapshot['service'], ['airtime', 'data'], true) ? 'request_id' : 'order_id';
        $response = $simcloud($snapshot['service'], 'GET', [$field => $row['provider_id']]);
    } catch (Throwable $error) {
        sc_transition($db, $id, 'polling', 'pending', ['message' => 'Status read failed; the next bounded read will retry.']);
        sc_log('status_read_failed', $row, $error->getCode()); return;
    }
    try {
        $parsed = sc_status($response['body'], $snapshot, $row);
        sc_transition($db, $id, 'polling', $parsed['state'], ['result' => sc_encrypt($parsed['result'], $config), 'message' => '',
            'notified_at' => $parsed['state'] === 'delivered' && in_array($snapshot['service'], ['airtime', 'data'], true) ? time() : 0]);
    } catch (Throwable $error) {
        sc_transition($db, $id, 'polling', 'needs_review', ['message' => 'Provider status or purchase identity requires review.']);
        sc_log('status_requires_review', $row);
    }
}

function sc_notify(PDO $db, array $config, int $id, callable $mailer): void {
    $row = sc_row($db, $id);
    if (!$row || $row['state'] !== 'delivered' || (int) $row['notified_at'] !== 0 || $row['result'] === '') { return; }
    $snapshot = sc_decrypt($row['snapshot'], $config);
    if (!in_array($snapshot['service'], ['vas', 'electricity'], true)) { return; }
    if (!filter_var($snapshot['email'] ?? '', FILTER_VALIDATE_EMAIL) || !filter_var($config['mail_from'] ?? '', FILTER_VALIDATE_EMAIL)) { return; }
    // Claim once. A crash/uncertain mail response is shown for review, not resent forever.
    $stmt = $db->prepare('UPDATE ledger SET notified_at=-1 WHERE id=? AND state=\'delivered\' AND notified_at=0'); $stmt->execute([$id]);
    if ($stmt->rowCount() !== 1) { return; }
    $result = sc_decrypt($row['result'], $config);
    $text = "Your SIMcloud purchase\n\n" . $snapshot['name'] . "\nOrder: " . $row['order_id'] . "\n\n";
    foreach ($result as $entry) {
        $text .= $entry['description'] . "\n" . $entry['code'] . "\n";
        if (($entry['expiry'] ?? '') !== '') { $text .= 'Expiry: ' . $entry['expiry'] . "\n"; }
        if (($entry['units'] ?? '') !== '') { $text .= 'Units: ' . $entry['units'] . "\n"; }
        $text .= "\n";
    }
    try { $sent = $mailer($snapshot['email'], 'Your prepaid purchase — order ' . $row['order_id'], $text, $config['mail_from']); }
    catch (Throwable $error) { $sent = false; }
    $stmt = $db->prepare('UPDATE ledger SET notified_at=?,message=? WHERE id=? AND notified_at=-1');
    $stmt->execute([$sent ? time() : -1, $sent ? '' : 'Email outcome needs review. Codes remain available to the merchant.', $id]);
    sc_log($sent ? 'delivery_email_accepted' : 'delivery_email_requires_review', $row);
}

function sc_work(PDO $db, array $config, callable $zoho, callable $simcloud, callable $mailer): void {
    $db->exec("UPDATE ledger SET state='needs_review',message='Interrupted submission requires reconciliation.' WHERE state='submitting' AND updated_at<" . (time() - 120));
    $db->exec("UPDATE ledger SET state='pending' WHERE state='polling' AND updated_at<" . (time() - 120));
    // Read accepted purchases first, even when new submissions are paused or a backlog exists.
    foreach ($db->query("SELECT id FROM ledger WHERE state IN ('queued','pending') AND next_poll<=" . time() . " ORDER BY next_poll,id LIMIT 100")->fetchAll(PDO::FETCH_COLUMN) as $id) {
        sc_poll($db, $config, (int) $id, $simcloud);
    }
    foreach ($db->query("SELECT id FROM ledger WHERE state='delivered' AND notified_at=0 ORDER BY id LIMIT 100")->fetchAll(PDO::FETCH_COLUMN) as $id) {
        sc_notify($db, $config, (int) $id, $mailer);
    }
    if (($config['live_enabled'] ?? false) === true) {
        foreach ($db->query("SELECT id FROM ledger WHERE state='ready' ORDER BY id LIMIT 20")->fetchAll(PDO::FETCH_COLUMN) as $id) {
            sc_submit($db, $config, (int) $id, $zoho, $simcloud);
        }
    }
}
